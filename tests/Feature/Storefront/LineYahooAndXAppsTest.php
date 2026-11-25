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
 * タグの App「LINEヤフー広告」（plugins/plugin-lineyahoo-ads）と「X ピクセル」（plugins/plugin-x-pixel）。ADR 0034、
 * docs/design/tag-apps.md の 5・7。マニフェストが核の検査を通り、店で有効にして ID を入れ、「広告」に同意したお客さんの
 * ふつうの画面と購入の完了の画面にだけ、核がスクリプトと設定の印を出し、CSP に宣言した宛先を足す。形の違う ID は断る
 */
final class LineYahooAndXAppsTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string TAG_ID = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('lyx');
        // 同意の帯を出す店（既定は出さない）。帯を出さない店の動きは ConsentBannerTest
        $this->inside('lyx', static fn () => app(ShopSettingsService::class)->setConsentBanner(true));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_both_apps_pass_the_core_checks_of_their_manifests(): void
    {
        $plugins = app(PluginRegistry::class);

        $ly = $plugins->get('lineyahoo-ads')->app();
        $this->assertSame(['lineyahoo-ads.js'], array_map(static fn ($s): string => $s->file, $ly->scripts));
        $this->assertSame([PageKind::General, PageKind::Purchase], $ly->scripts[0]->pages);
        $this->assertSame([ConsentKind::Ads], $ly->consent);
        $this->assertSame(['script-src' => ['https://tag.flvcdn.net'], 'connect-src' => ['https://apm.yahoo.co.jp']], $ly->csp);
        $this->assertSame(['tag_id'], array_keys($ly->settings));
        $this->assertTrue($ly->setting('tag_id')?->required);
        $this->assertSame('LINEヤフー株式会社', $ly->disclosures[0]->company);

        $x = $plugins->get('x-pixel')->app();
        $this->assertSame(['x-pixel.js'], array_map(static fn ($s): string => $s->file, $x->scripts));
        $this->assertSame([PageKind::General, PageKind::Purchase], $x->scripts[0]->pages);
        $this->assertSame([ConsentKind::Ads], $x->consent);
        $this->assertSame(['https://static.ads-twitter.com'], $x->csp['script-src']);
        $this->assertSame(['https://t.co', 'https://analytics.twitter.com'], $x->csp['connect-src']);
        $this->assertArrayNotHasKey('frame-src', $x->csp);
        $this->assertTrue($x->setting('pixel_id')?->required);
        $this->assertFalse($x->setting('purchase_event_id')?->required);
        $this->assertSame('X Corp.', $x->disclosures[0]->company);
    }

    public function test_with_ids_and_ads_consent_the_tags_and_their_csp_appear_and_the_purchase_gets_the_order_values(): void
    {
        $this->inside('lyx', function (): void {
            app(PluginStates::class)->set('lineyahoo-ads', true);
            app(PluginStates::class)->set('x-pixel', true);
            app(PluginSettings::class)->update('lineyahoo-ads', ['tag_id' => self::TAG_ID]);
            app(PluginSettings::class)->update('x-pixel', ['pixel_id' => 'oka17', 'purchase_event_id' => 'ol288']);
        });

        // 同意の前・解析だけに同意: どちらも差し込まない（どちらも「広告」）。宛先も足さない
        foreach ([null, 'analytics=1&ads=0'] as $consent) {
            $before = $this->shop('/', $consent)->assertOk();
            $this->assertStringNotContainsString('/apps/lineyahoo-ads/', (string) $before->getContent());
            $this->assertStringNotContainsString('/apps/x-pixel/', (string) $before->getContent());
            $this->assertStringNotContainsString('flvcdn', (string) $before->headers->get('Content-Security-Policy'));
            $this->assertStringNotContainsString('ads-twitter', (string) $before->headers->get('Content-Security-Policy'));
        }

        // 広告に同意: ふつうの画面に、ID の印つきで差し込み、CSP に宣言した宛先を足す
        $response = $this->shop('/', 'analytics=0&ads=1')->assertOk();
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('~<script src="/apps/lineyahoo-ads/lineyahoo-ads\.js\?v=[0-9a-f]{12}" defer\s+data-sugar-app="lineyahoo-ads"\s+data-sugar-page="general"\s+data-setting-tag-id="'.self::TAG_ID.'"~', $html);
        $this->assertMatchesRegularExpression('~<script src="/apps/x-pixel/x-pixel\.js\?v=[0-9a-f]{12}" defer\s+data-sugar-app="x-pixel"\s+data-sugar-page="general"\s+data-setting-pixel-id="oka17"\s+data-setting-purchase-event-id="ol288"~', $html);
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression('~script-src [^;]*https://tag\.flvcdn\.net~', $csp);
        $this->assertMatchesRegularExpression('~script-src [^;]*https://static\.ads-twitter\.com~', $csp);
        $this->assertMatchesRegularExpression('~connect-src [^;]*https://apm\.yahoo\.co\.jp~', $csp);
        $this->assertMatchesRegularExpression('~connect-src [^;]*https://t\.co[ ;][^;]*https://analytics\.twitter\.com~', $csp);
        $this->assertMatchesRegularExpression('~img-src [^;]*https://analytics\.twitter\.com~', $csp);
        $this->assertStringNotContainsString('frame-src', $csp);
        $this->assertStringContainsString("require-trusted-types-for 'script'", $csp);
        // 画面に直に書いたスクリプトは無い
        $this->assertStringNotContainsString('lytag(', $html);
        $this->assertStringNotContainsString('twq(', $html);

        // 配るファイルは同じオリジンから
        $this->shop('/apps/lineyahoo-ads/lineyahoo-ads.js', null)->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $this->shop('/apps/x-pixel/x-pixel.js', null)->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');

        // 購入の完了の画面: 注文の番号・金額（最小通貨単位の整数）・通貨・品の ID と数だけを、2 つの App に渡す
        [$order, $listing] = $this->inside('lyx', function (): array {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            $location = Location::query()->firstOrFail();
            app(StockService::class)->receive($listing, $location, 5);
            $sale = app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), null, [LineDraft::fromListing($listing, 2, 1000, $location)], actor: 'test');

            return [$sale->id, $listing->id];
        });
        $complete = (string) $this->withSession(['checkout_last_order' => $order])->shop('/checkout/complete', 'analytics=0&ads=1')->assertOk()->getContent();
        $number = strtoupper($order);
        foreach (['lineyahoo-ads', 'x-pixel'] as $app) {
            $this->assertMatchesRegularExpression('~data-sugar-app="'.$app.'"\s+data-sugar-page="purchase"~', $complete, $app);
        }
        $this->assertSame(2, substr_count($complete, 'data-order-number="'.$number.'"'));
        $this->assertSame(2, substr_count($complete, 'data-order-total="8800"'));
        $this->assertSame(2, substr_count($complete, 'data-order-currency="JPY"'));
        $this->assertSame(2, substr_count($complete, 'data-order-items="[{&quot;id&quot;:&quot;'.$listing.'&quot;,&quot;quantity&quot;:2}]"'));
    }

    /**
     * よくある失敗: スニペットのコードや、別の形の ID（従来の検索広告のコンバージョン ID・X のイベントのコードをまるごと）を貼る。422 で断る
     */
    public function test_ids_in_the_wrong_shape_are_refused(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($owner, $authenticator);

        foreach ([
            ['lineyahoo-ads', ['tag_id' => '1000178837'], 'tag_id'],
            ['lineyahoo-ads', ['tag_id' => '1a2b3c4d-5e6f-1a7b-8c9d-0e1f2a3b4c5d'], 'tag_id'],
            ['lineyahoo-ads', ['tag_id' => "lytag({type:'init',tagId:'".self::TAG_ID."'})"], 'tag_id'],
            ['lineyahoo-ads', ['tag_id' => self::TAG_ID.'"><script>'], 'tag_id'],
            ['x-pixel', ['pixel_id' => 'OKA17'], 'pixel_id'],
            ['x-pixel', ['pixel_id' => "twq('config','oka17')"], 'pixel_id'],
            ['x-pixel', ['pixel_id' => 'oka17', 'purchase_event_id' => 'tw-oka17-ol288'], 'purchase_event_id'],
        ] as [$app, $values, $field]) {
            $this->send('PUT', "/api/tenants/lyx/plugins/{$app}/settings", ['values' => $values])
                ->assertStatus(422)->assertJsonValidationErrors('values.'.$field);
        }

        // 形の合う ID は通る（タグ ID は大文字でも、X の購入のイベント ID は空でもよい）
        $this->send('PUT', '/api/tenants/lyx/plugins/lineyahoo-ads/settings', ['values' => ['tag_id' => strtoupper(self::TAG_ID)]])->assertOk();
        $this->send('PUT', '/api/tenants/lyx/plugins/x-pixel/settings', ['values' => ['pixel_id' => 'oka17']])->assertOk();
        $this->inside('lyx', fn () => $this->assertSame(['pixel_id' => 'oka17'], app(PluginSettings::class)->publicValues('x-pixel')));
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $path, ?string $consent): TestResponse
    {
        if ($consent !== null) {
            $this->withCookie(ConsentCookie::NAME, $consent);
        }

        $response = $this->get("http://lyx.localhost{$path}");
        tenancy()->end();
        $this->defaultCookies = [];

        return $response;
    }
}
