<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Plugin\Manifest\ConsentKind;
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
 * タグの App「Google アナリティクス 4」（plugins/plugin-ga4）と「Google 広告」（plugins/plugin-google-ads）。ADR 0034、
 * docs/design/tag-apps.md の 1・2。マニフェストが核の検査を通り、店で有効にして ID を入れ、同意したお客さんのふつうの画面と
 * 購入の完了の画面にだけ、核がスクリプトと設定の印を出し、CSP に宣言した宛先を足す。形の違う ID は断る
 */
final class GoogleTagAppsTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('gta');
        // 同意の帯を出す店（既定は出さない）。帯を出さない店の動きは ConsentBannerTest
        $this->inside('gta', static fn () => app(ShopSettingsService::class)->setConsentBanner(true));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_both_apps_pass_the_core_checks_of_their_manifests(): void
    {
        $plugins = app(PluginRegistry::class);

        $ga4 = $plugins->get('ga4')->app();
        $this->assertSame(['ga4.js'], array_map(static fn ($s): string => $s->file, $ga4->scripts));
        $this->assertSame([ConsentKind::Analytics], $ga4->consent);
        $this->assertSame(['https://www.googletagmanager.com'], $ga4->csp['script-src']);
        $this->assertArrayNotHasKey('frame-src', $ga4->csp);
        $this->assertTrue($ga4->setting('measurement_id')?->required);
        $this->assertSame('Google LLC', $ga4->disclosures[0]->company);

        $ads = $plugins->get('google-ads')->app();
        $this->assertSame(['google-ads.js'], array_map(static fn ($s): string => $s->file, $ads->scripts));
        $this->assertSame([ConsentKind::Ads], $ads->consent);
        $this->assertSame(['https://www.googletagmanager.com'], $ads->csp['frame-src']);
        $this->assertTrue($ads->setting('conversion_id')?->required);
        $this->assertTrue($ads->setting('conversion_label')?->required);
        $this->assertSame('Google LLC', $ads->disclosures[0]->company);
    }

    public function test_with_ids_and_consent_the_tags_and_their_csp_appear_and_the_purchase_gets_the_order_values(): void
    {
        $this->inside('gta', function (): void {
            app(PluginStates::class)->set('ga4', true);
            app(PluginStates::class)->set('google-ads', true);
            app(PluginSettings::class)->update('ga4', ['measurement_id' => 'G-ABC123DEF4']);
            app(PluginSettings::class)->update('google-ads', ['conversion_id' => 'AW-123456789', 'conversion_label' => 'AbC-D_efG-h12_34-567']);
        });

        // 同意の前: 帯だけで、スクリプトも CSP の足し込みも無い
        $before = $this->shop('/', null);
        $this->assertStringContainsString('data-test="consent-banner"', (string) $before->getContent());
        $this->assertStringNotContainsString('/apps/ga4/', (string) $before->getContent());
        $this->assertSame(config('storefront.csp'), $before->headers->get('Content-Security-Policy'));

        // 解析と広告の両方に同意: 2 つとも差し込み、設定の印を付け、宣言した宛先を足す
        $response = $this->shop('/', 'analytics=1&ads=1')->assertOk();
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('~<script src="/apps/ga4/ga4\.js\?v=[0-9a-f]{12}" defer\s+data-sugar-app="ga4"\s+data-sugar-page="general"\s+data-setting-measurement-id="G-ABC123DEF4"~', $html);
        $this->assertMatchesRegularExpression('~<script src="/apps/google-ads/google-ads\.js\?v=[0-9a-f]{12}" defer\s+data-sugar-app="google-ads"\s+data-sugar-page="general"\s+data-setting-conversion-id="AW-123456789"\s+data-setting-conversion-label="AbC-D_efG-h12_34-567"~', $html);
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' https://www.googletagmanager.com https://www.googleadservices.com https://www.google.com", $csp);
        $this->assertStringContainsString('https://*.google-analytics.com', $csp);
        $this->assertStringContainsString('https://googleads.g.doubleclick.net', $csp);
        $this->assertStringContainsString('frame-src https://www.googletagmanager.com', $csp);
        $this->assertStringContainsString("require-trusted-types-for 'script'", $csp);
        // 2 つの App のファイルが同じオリジンから配られる
        $this->shop('/apps/ga4/ga4.js', null)->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $this->shop('/apps/google-ads/google-ads.js', null)->assertOk();

        // 解析だけに同意: GA4 だけ。Google 広告の宛先（iframe を含む）は足さない
        $analyticsOnly = $this->shop('/', 'analytics=1&ads=0');
        $this->assertStringContainsString('/apps/ga4/ga4.js?v=', (string) $analyticsOnly->getContent());
        $this->assertStringNotContainsString('/apps/google-ads/', (string) $analyticsOnly->getContent());
        $this->assertStringNotContainsString('googleadservices', (string) $analyticsOnly->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('frame-src', (string) $analyticsOnly->headers->get('Content-Security-Policy'));

        // 購入の完了の画面: 注文の番号・金額（最小通貨単位の整数）・通貨・品の ID と数だけを、2 つの App に渡す
        [$order, $listing] = $this->inside('gta', function (): array {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            $location = Location::query()->firstOrFail();
            app(StockService::class)->receive($listing, $location, 5);
            $sale = app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), null, [LineDraft::fromListing($listing, 2, 1000, $location)], actor: 'test');

            return [$sale->id, $listing->id];
        });
        $complete = (string) $this->withSession(['checkout_last_order' => $order])->shop('/checkout/complete', 'analytics=1&ads=1')->assertOk()->getContent();
        $number = strtoupper($order);
        foreach (['ga4', 'google-ads'] as $app) {
            $this->assertMatchesRegularExpression('~data-sugar-app="'.$app.'"\s+data-sugar-page="purchase"~', $complete, $app);
        }
        $this->assertSame(2, substr_count($complete, 'data-order-number="'.$number.'"'));
        $this->assertSame(2, substr_count($complete, 'data-order-total="8800"'));
        $this->assertSame(2, substr_count($complete, 'data-order-currency="JPY"'));
        $this->assertSame(2, substr_count($complete, 'data-order-items="[{&quot;id&quot;:&quot;'.$listing.'&quot;,&quot;quantity&quot;:2}]"'));
    }

    /**
     * よくある失敗: 形の違う ID（古いユニバーサル アナリティクスの UA-・GA4 の ID を広告の欄に・ラベルに send_to をまるごと）は 422 で断る
     */
    public function test_ids_in_the_wrong_shape_are_refused(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($owner, $authenticator);

        foreach ([
            ['ga4', ['measurement_id' => 'UA-12345678-1'], 'measurement_id'],
            ['ga4', ['measurement_id' => 'g-abc123def4'], 'measurement_id'],
            ['ga4', ['measurement_id' => 'G-ABC123"><script>'], 'measurement_id'],
            ['google-ads', ['conversion_id' => 'G-ABC123DEF4', 'conversion_label' => 'AbC-D_efG'], 'conversion_id'],
            ['google-ads', ['conversion_id' => 'AW-123456789', 'conversion_label' => 'AW-123456789/AbC-D_efG'], 'conversion_label'],
        ] as [$app, $values, $field]) {
            $this->send('PUT', "/api/tenants/gta/plugins/{$app}/settings", ['values' => $values])
                ->assertStatus(422)->assertJsonValidationErrors('values.'.$field);
        }

        // 形の合う ID は通る
        $this->send('PUT', '/api/tenants/gta/plugins/ga4/settings', ['values' => ['measurement_id' => 'G-ABC123DEF4']])->assertOk();
        $this->send('PUT', '/api/tenants/gta/plugins/google-ads/settings', ['values' => ['conversion_id' => 'AW-123456789', 'conversion_label' => 'AbC-D_efG-h12_34-567']])->assertOk();
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $path, ?string $consent): TestResponse
    {
        if ($consent !== null) {
            $this->withCookie(ConsentCookie::NAME, $consent);
        }

        $response = $this->get("http://gta.localhost{$path}");
        tenancy()->end();
        $this->defaultCookies = [];

        return $response;
    }
}
