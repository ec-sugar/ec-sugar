<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Plugin\Manifest\ConsentKind;
use App\Core\Plugin\Manifest\PageKind;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Services\TransactionService;
use App\Storefront\Apps\ConsentCookie;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 広告のタグの App「SmartNews 広告」（plugins/plugin-smartnews-ads）。ADR 0034、docs/design/tag-apps.md の 11。
 * マニフェストが核の検査を通り、店で有効にしてピクセル ID を入れ、「広告」に同意したお客さんのふつうの画面と購入の完了の画面にだけ、
 * 核がスクリプトと設定の印を出し、CSP に宣言した宛先（script-src と img-src だけ）を足す。個人情報の画面には出さない。形の違う ID は断る
 */
final class SmartnewsAdsAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string PIXEL_ID = 'ab124kadkkall3a134555';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('sna');
        // 同意の帯を出す店（既定は出さない）。帯を出さない店の動きは ConsentBannerTest
        $this->inside('sna', static fn () => app(ShopSettingsService::class)->setConsentBanner(true));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_manifest_passes_the_core_checks(): void
    {
        $app = app(PluginRegistry::class)->get('smartnews-ads')->app();

        $this->assertSame(['smartnews-ads.js'], array_map(static fn ($s): string => $s->file, $app->scripts));
        $this->assertSame([PageKind::General, PageKind::Purchase], $app->scripts[0]->pages);
        $this->assertSame([ConsentKind::Ads], $app->consent);
        // pixel.js は画像で送るだけ（2 段目のスクリプト・fetch・iframe は無い）
        $this->assertSame([
            'script-src' => ['https://cdn.smartnews-ads.com'],
            'img-src' => ['https://i.smartnews-ads.com', 'https://i6.smartnews-ads.com'],
        ], $app->csp);
        $this->assertSame(['pixel_id'], array_keys($app->settings));
        $this->assertTrue($app->setting('pixel_id')?->required);
        $this->assertSame('スマートニュース株式会社', $app->disclosures[0]->company);
    }

    public function test_with_an_id_and_ads_consent_the_tag_and_its_csp_appear_and_the_purchase_gets_the_order_values(): void
    {
        $this->inside('sna', function (): void {
            app(PluginStates::class)->set('smartnews-ads', true);
            app(PluginSettings::class)->update('smartnews-ads', ['pixel_id' => self::PIXEL_ID]);
        });

        // 同意の前・解析だけに同意: 差し込まず、CSP も元のまま
        foreach ([null, 'analytics=1&ads=0'] as $consent) {
            $response = $this->shop('/', $consent)->assertOk();
            $this->assertStringNotContainsString('/apps/smartnews-ads/', (string) $response->getContent());
            $this->assertSame(config('storefront.csp'), $response->headers->get('Content-Security-Policy'));
        }

        // 広告に同意: ピクセル ID の印つきで差し込み、CSP に足すのは宣言した宛先だけ
        $response = $this->shop('/', 'analytics=0&ads=1')->assertOk();
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('~<script src="/apps/smartnews-ads/smartnews-ads\.js\?v=[0-9a-f]{12}" defer\s+data-sugar-app="smartnews-ads"\s+data-sugar-page="general"\s+data-setting-pixel-id="'.self::PIXEL_ID.'"~', $html);
        $this->shop('/apps/smartnews-ads/smartnews-ads.js', null)->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $this->assertSame([
            'script-src' => ['https://cdn.smartnews-ads.com'],
            'img-src' => ['https://i.smartnews-ads.com', 'https://i6.smartnews-ads.com'],
        ], $this->added((string) $response->headers->get('Content-Security-Policy')));
        // 画面に直に書いたスクリプトも、noscript の画像のピクセルも無い
        foreach (['SmartnewsAds', 'i.smartnews-ads.com/p', '<noscript><img'] as $inline) {
            $this->assertStringNotContainsString($inline, $html, $inline);
        }

        // 購入の完了の画面: 注文の番号・金額（最小通貨単位の整数）・通貨・品の ID と数だけを渡す（番号は App が二度送らないためだけに使う）
        [$order, $listing] = $this->inside('sna', function (): array {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            $location = Location::query()->firstOrFail();
            app(StockService::class)->receive($listing, $location, 5);
            $sale = app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), null, [LineDraft::fromListing($listing, 2, 1000, $location)], actor: 'test');

            return [$sale->id, $listing->id];
        });
        $complete = (string) $this->withSession(['checkout_last_order' => $order])->shop('/checkout/complete', 'analytics=0&ads=1')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~data-sugar-app="smartnews-ads"\s+data-sugar-page="purchase"\s+data-setting-pixel-id="'.self::PIXEL_ID.'"~', $complete);
        $this->assertStringContainsString('data-order-number="'.strtoupper($order).'"', $complete);
        $this->assertStringContainsString('data-order-total="8800"', $complete);
        $this->assertStringContainsString('data-order-currency="JPY"', $complete);
        $this->assertStringContainsString('data-order-items="[{&quot;id&quot;:&quot;'.$listing.'&quot;,&quot;quantity&quot;:2}]"', $complete);

        // 個人情報の画面（注文の入力）: 広告に同意していても、スクリプトも関所も CSP の足し込みも無い
        $private = $this->shop('/checkout', 'analytics=1&ads=1');
        $this->assertStringNotContainsString('data-sugar-app=', (string) $private->getContent());
        $this->assertStringNotContainsString('data-sugar-trusted-types', (string) $private->getContent());
        $this->assertSame(config('storefront.csp'), $private->headers->get('Content-Security-Policy'));
    }

    /**
     * よくある失敗: ID ではなく、ベースコードや大文字まじりの別のタグの ID を貼る。サーバーが 422 で断る
     */
    public function test_ids_in_the_wrong_shape_are_refused(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($owner, $authenticator);

        foreach ([
            'AB124KADKKALL3A134555',
            'ab124kadk',
            str_repeat('a', 33),
            'ab124kadkkall3a-134555',
            'SmartnewsAds.p("ab124kadkkall3a134555", "PageView")',
            'ab124kadkkall3a134555"><script>',
        ] as $id) {
            $this->send('PUT', '/api/tenants/sna/plugins/smartnews-ads/settings', ['values' => ['pixel_id' => $id]])
                ->assertStatus(422)->assertJsonValidationErrors('values.pixel_id');
        }

        // 形の合う ID は通る（公式の例の 21 文字と、Conversion API の例の 24 文字）
        foreach ([self::PIXEL_ID, '9112675ff25eb25f580feb82'] as $id) {
            $this->send('PUT', '/api/tenants/sna/plugins/smartnews-ads/settings', ['values' => ['pixel_id' => $id]])->assertOk();
        }
        $this->inside('sna', fn () => $this->assertSame(['pixel_id' => '9112675ff25eb25f580feb82'], app(PluginSettings::class)->publicValues('smartnews-ads')));
    }

    /**
     * CSP のうち、元の CSP（config の storefront.csp）に無くて足された宛先を、指定ごとに
     *
     * @return array<string, list<string>>
     */
    private function added(string $csp): array
    {
        $base = $this->directives((string) config('storefront.csp'));
        $out = [];
        foreach ($this->directives($csp) as $name => $sources) {
            $extra = array_values(array_diff($sources, $base[$name] ?? []));
            if ($extra !== []) {
                $out[$name] = $extra;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private function directives(string $csp): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $csp))) as $directive) {
            $parts = preg_split('/\s+/', $directive) ?: [];
            $name = (string) array_shift($parts);
            $out[$name] = $parts;
        }

        return $out;
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $path, ?string $consent): TestResponse
    {
        if ($consent !== null) {
            $this->withCookie(ConsentCookie::NAME, $consent);
        }

        $response = $this->get("http://sna.localhost{$path}");
        tenancy()->end();
        $this->defaultCookies = [];

        return $response;
    }
}
