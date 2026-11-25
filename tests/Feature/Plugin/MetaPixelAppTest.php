<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\Manifest\ConsentKind;
use App\Core\Plugin\Manifest\PageKind;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Storefront\Apps\ConsentCookie;
use App\Storefront\Cart\CartCookie;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「Meta ピクセル」（ADR 0034、docs/design/tag-apps.md の 3）。マニフェストが核の検査を通って登録され、
 * 店で使って ID を入れ、お客さんが「広告」に同意したときだけ、ふつうの画面と購入の完了の画面に差し込まれ、宣言した宛先が CSP に足される。
 * 形の違う ID は管理画面で断る
 */
final class MetaPixelAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string PIXEL_ID = '123456789012345';

    private ?string $cart = null;

    private ?FakeStripe $stripe = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->configureAdminHost();
        Cache::store()->flush();
        $this->tenant('mpa');
        // 同意の帯を出す店（既定は出さない）。帯を出さない店の動きは ConsentBannerTest
        $this->inside('mpa', static fn () => app(ShopSettingsService::class)->setConsentBanner(true));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        if ($this->stripe !== null) {
            FakeStripe::reset();
        }

        parent::tearDown();
    }

    public function test_the_manifest_passes_the_core_checks(): void
    {
        $app = app(PluginRegistry::class)->get('meta-pixel')->app();

        $this->assertSame(['meta-pixel.js'], array_map(static fn ($s): string => $s->file, $app->scripts));
        $this->assertSame([PageKind::General, PageKind::Purchase], $app->scripts[0]->pages);
        $this->assertSame([ConsentKind::Ads], $app->consent);
        $this->assertSame(['script-src' => ['https://connect.facebook.net'], 'connect-src' => ['https://www.facebook.com'], 'img-src' => ['https://www.facebook.com']], $app->csp);
        $this->assertSame(['pixel_id'], array_keys($app->settings));
        $this->assertTrue($app->settings['pixel_id']->required);
        $this->assertSame('Meta Platforms, Inc.', $app->disclosures[0]->company);
    }

    public function test_the_pixel_is_injected_after_ads_consent_with_its_csp_and_the_purchase_values(): void
    {
        $this->inside('mpa', function (): void {
            app(PluginStates::class)->set('meta-pixel', true);
            app(PluginSettings::class)->update('meta-pixel', ['pixel_id' => self::PIXEL_ID]);
        });

        // 同意の前・解析だけに同意: 差し込まない（この App は「広告」）
        $this->assertStringNotContainsString('/apps/meta-pixel/', (string) $this->shop('/')->assertOk()->getContent());
        $this->assertStringNotContainsString('/apps/meta-pixel/', (string) $this->shop('/', ads: false)->getContent());

        // 広告に同意: ふつうの画面に、ID の印つきで差し込み、CSP に宣言した宛先を足す
        $response = $this->shop('/', ads: true)->assertOk();
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('~<script src="/apps/meta-pixel/meta-pixel\.js\?v=[0-9a-f]{12}" defer\s+data-sugar-app="meta-pixel"\s+data-sugar-page="general"\s+data-setting-pixel-id="'.self::PIXEL_ID.'"~', $html);
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' https://connect.facebook.net", $csp);
        $this->assertStringContainsString("connect-src 'self' https://www.facebook.com", $csp);
        $this->assertMatchesRegularExpression("~img-src [^;]*https://www\.facebook\.com~", $csp);
        $this->assertStringContainsString("require-trusted-types-for 'script'", $csp);
        // 画面に直に書いたスクリプトも、noscript の画像も無い
        $this->assertStringNotContainsString('fbq(', $html);
        $this->assertStringNotContainsString('facebook.com/tr', $html);

        // 配るファイルは同じオリジンから
        $this->shop('/apps/meta-pixel/meta-pixel.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');

        // 購入の完了の画面: 注文の番号・金額・通貨・品の ID と数だけを渡す（名前・メール・電話は出さない）
        $listing = $this->listing(3300);
        $this->placeOrder($listing);
        $complete = (string) $this->shop('/checkout/complete', ads: true)->assertOk()->getContent();
        $this->assertStringContainsString('data-sugar-page="purchase"', $complete);
        $this->assertMatchesRegularExpression('~data-sugar-app="meta-pixel"[^>]*data-order-number="[^"]+"~', $complete);
        $this->assertStringContainsString('data-order-total="3300"', $complete);
        $this->assertStringContainsString('data-order-currency="JPY"', $complete);
        $this->assertStringContainsString('data-order-items="[{&quot;id&quot;:&quot;'.$listing.'&quot;,&quot;quantity&quot;:1}]"', $complete);
        preg_match('~<script src="/apps/meta-pixel/[^>]*>~', $complete, $tag);
        $this->assertNotEmpty($tag);
        foreach (['hanako@example.com', '09012345678', '山田'] as $private) {
            $this->assertStringNotContainsString($private, $tag[0]);
        }
    }

    /**
     * よくある失敗: ピクセル ID ではなく、スニペットのコードや別のタグの ID を貼る。サーバーが 422 で断る
     */
    public function test_a_malformed_pixel_id_is_rejected(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($owner, $authenticator);
        $url = '/api/tenants/mpa/plugins/meta-pixel/settings';

        foreach (['12345', 'G-ABC1234567', '1234567890123456789012', "fbq('init', '123456789012345')", '123456789012345"><script>'] as $id) {
            $this->send('PUT', $url, ['values' => ['pixel_id' => $id]])->assertStatus(422)->assertJsonValidationErrors('values.pixel_id');
        }

        $this->send('PUT', $url, ['values' => ['pixel_id' => self::PIXEL_ID]])->assertOk();
        $this->inside('mpa', fn () => $this->assertSame(['pixel_id' => self::PIXEL_ID], app(PluginSettings::class)->publicValues('meta-pixel')));
    }

    /**
     * ショップへ送る（カートと同意の Cookie を持ち越す）。ads が null なら同意を決めていないお客さん
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $path, ?bool $ads = null, string $method = 'GET', array $data = []): TestResponse
    {
        if ($ads !== null) {
            $this->withCookie(ConsentCookie::NAME, 'analytics=1&ads='.($ads ? '1' : '0'));
        }

        if ($this->cart !== null) {
            $this->withCookie(CartCookie::NAME, $this->cart);
        }

        $response = $method === 'GET' ? $this->get("http://mpa.localhost{$path}") : $this->post("http://mpa.localhost{$path}", $data);
        tenancy()->end();
        $this->cart = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->cart;
        $this->defaultCookies = [];

        return $response;
    }

    private function listing(int $amount): string
    {
        return $this->inside('mpa', function () use ($amount): string {
            app(ShopSettingsService::class)->setShipping(['fee' => 0, 'free_over' => null], 'test');
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '角砂糖', 'unit_amount' => $amount, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 5);

            return $listing->id;
        });
    }

    /**
     * 注文を確定して Stripe の支払いへ送られるところまで（受け付けた画面は、この人が今確定した注文を出す）
     */
    private function placeOrder(string $listing): void
    {
        $this->stripe = FakeStripe::install();
        $this->inside('mpa', fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]));

        $this->shop('/cart/items', method: 'POST', data: ['listing_id' => $listing, 'quantity' => '1']);
        $this->shop('/checkout', method: 'POST', data: ['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => ''], 'same_as_buyer' => '1']);
        $confirm = (string) $this->shop('/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->assertNotEmpty($key);
        $this->shop('/checkout/confirm', method: 'POST', data: ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertStatus(303);
    }
}
