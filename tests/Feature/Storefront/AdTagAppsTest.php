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
 * 広告のタグの App「Pinterest タグ」（plugins/plugin-pinterest-tag）・「Microsoft 広告 UET」（plugins/plugin-microsoft-uet）・
 * 「TikTok ピクセル」（plugins/plugin-tiktok-pixel）。ADR 0034、docs/design/tag-apps.md の 6・9・10。
 * マニフェストが核の検査を通り、店で有効にして ID を入れ、「広告」に同意したお客さんのふつうの画面と購入の完了の画面にだけ、
 * 核がスクリプトと設定の印を出し、CSP に宣言した宛先を足す。形の違う ID は断る
 */
final class AdTagAppsTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    /**
     * App の名前 => [ファイル, 設定の項目, 形の合う ID, script-src の宛先, 公表の事業者]
     *
     * @var array<string, array{string, string, string, string, string}>
     */
    private const array APPS = [
        'pinterest-tag' => ['pinterest-tag.js', 'tag_id', '2612345678901', 'https://s.pinimg.com', 'Pinterest, Inc.'],
        'microsoft-uet' => ['microsoft-uet.js', 'tag_id', '343012345', 'https://bat.bing.com', 'Microsoft Corporation'],
        'tiktok-pixel' => ['tiktok-pixel.js', 'pixel_id', 'C4ABCDEFGHIJ12345678', 'https://analytics.tiktok.com', 'TikTok Pte. Ltd.'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('ata');
        // 同意の帯を出す店（既定は出さない）。帯を出さない店の動きは ConsentBannerTest
        $this->inside('ata', static fn () => app(ShopSettingsService::class)->setConsentBanner(true));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_three_apps_pass_the_core_checks_of_their_manifests(): void
    {
        $plugins = app(PluginRegistry::class);

        foreach (self::APPS as $name => [$file, $field, , $scriptSrc, $company]) {
            $app = $plugins->get($name)->app();
            $this->assertSame([$file], array_map(static fn ($s): string => $s->file, $app->scripts), $name);
            $this->assertSame([PageKind::General, PageKind::Purchase], $app->scripts[0]->pages, $name);
            $this->assertSame([ConsentKind::Ads], $app->consent, $name);
            $this->assertSame([$scriptSrc], $app->csp['script-src'], $name);
            // どれも iframe は使わない（frame-src を足さない）
            $this->assertArrayNotHasKey('frame-src', $app->csp, $name);
            $this->assertSame([$field], array_keys($app->settings), $name);
            $this->assertTrue($app->setting($field)?->required, $name);
            $this->assertSame($company, $app->disclosures[0]->company, $name);
        }
    }

    public function test_with_ids_and_ads_consent_the_tags_and_their_csp_appear_and_the_purchase_gets_the_order_values(): void
    {
        $this->inside('ata', function (): void {
            foreach (self::APPS as $name => [, $field, $id]) {
                app(PluginStates::class)->set($name, true);
                app(PluginSettings::class)->update($name, [$field => $id]);
            }
        });

        // 同意の前・解析だけに同意: 帯だけで、どの App も差し込まず、CSP も元のまま（3 つとも「広告」）
        foreach ([null, 'analytics=1&ads=0'] as $consent) {
            $response = $this->shop('/', $consent)->assertOk();
            foreach (array_keys(self::APPS) as $name) {
                $this->assertStringNotContainsString("/apps/{$name}/", (string) $response->getContent(), $name);
            }
            $this->assertSame(config('storefront.csp'), $response->headers->get('Content-Security-Policy'));
        }

        // 広告に同意: 3 つとも ID の印つきで差し込み、宣言した宛先を CSP に足す
        $response = $this->shop('/', 'analytics=0&ads=1')->assertOk();
        $html = (string) $response->getContent();
        foreach (self::APPS as $name => [$file, $field, $id]) {
            $attribute = 'data-setting-'.str_replace('_', '-', $field);
            $this->assertMatchesRegularExpression('~<script src="/apps/'.$name.'/'.preg_quote($file, '~').'\?v=[0-9a-f]{12}" defer\s+data-sugar-app="'.$name.'"\s+data-sugar-page="general"\s+'.$attribute.'="'.$id.'"~', $html, $name);
            $this->shop("/apps/{$name}/{$file}", null)->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        }
        $csp = (string) $response->headers->get('Content-Security-Policy');
        foreach (self::APPS as [, , , $scriptSrc]) {
            $this->assertContains($scriptSrc, $this->directive($csp, 'script-src'), $scriptSrc);
        }
        foreach (['https://ct.pinterest.com', 'https://bat.bing.com', 'https://bat.bing.net', 'https://analytics.tiktok.com'] as $host) {
            $this->assertContains($host, $this->directive($csp, 'connect-src'), $host);
            $this->assertContains($host, $this->directive($csp, 'img-src'), $host);
        }
        $this->assertStringNotContainsString('frame-src', $csp);
        $this->assertStringContainsString("require-trusted-types-for 'script'", $csp);
        // 画面に直に書いたスクリプトも、noscript の画像のピクセルも無い
        foreach (['pintrk(', 'uetq', 'ttq.', 'ct.pinterest.com/v3', '<noscript><img'] as $inline) {
            $this->assertStringNotContainsString($inline, $html, $inline);
        }

        // 購入の完了の画面: 注文の番号・金額（最小通貨単位の整数）・通貨・品の ID と数だけを、3 つの App に渡す
        [$order, $listing] = $this->inside('ata', function (): array {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            $location = Location::query()->firstOrFail();
            app(StockService::class)->receive($listing, $location, 5);
            $sale = app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), null, [LineDraft::fromListing($listing, 2, 1000, $location)], actor: 'test');

            return [$sale->id, $listing->id];
        });
        $complete = (string) $this->withSession(['checkout_last_order' => $order])->shop('/checkout/complete', 'analytics=0&ads=1')->assertOk()->getContent();
        foreach (array_keys(self::APPS) as $name) {
            $this->assertMatchesRegularExpression('~data-sugar-app="'.$name.'"\s+data-sugar-page="purchase"~', $complete, $name);
        }
        $this->assertSame(3, substr_count($complete, 'data-order-number="'.strtoupper($order).'"'));
        $this->assertSame(3, substr_count($complete, 'data-order-total="8800"'));
        $this->assertSame(3, substr_count($complete, 'data-order-currency="JPY"'));
        $this->assertSame(3, substr_count($complete, 'data-order-items="[{&quot;id&quot;:&quot;'.$listing.'&quot;,&quot;quantity&quot;:2}]"'));
    }

    /**
     * よくある失敗: ID ではなく、スニペットのコードや別のタグの ID を貼る。サーバーが 422 で断る
     */
    public function test_ids_in_the_wrong_shape_are_refused(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($owner, $authenticator);

        foreach ([
            ['pinterest-tag', 'tag_id', '12345678'],
            ['pinterest-tag', 'tag_id', "pintrk('load', '2612345678901')"],
            ['microsoft-uet', 'tag_id', '1234'],
            ['microsoft-uet', 'tag_id', '343012345"><script>'],
            ['tiktok-pixel', 'pixel_id', 'c4abcdefghij12345678'],
            ['tiktok-pixel', 'pixel_id', '123456789012345'.str_repeat('A', 11)],
            ['tiktok-pixel', 'pixel_id', "ttq.load('C4ABCDEFGHIJ12345678')"],
        ] as [$app, $field, $id]) {
            $this->send('PUT', "/api/tenants/ata/plugins/{$app}/settings", ['values' => [$field => $id]])
                ->assertStatus(422)->assertJsonValidationErrors('values.'.$field);
        }

        // 形の合う ID は通る
        foreach (self::APPS as $name => [, $field, $id]) {
            $this->send('PUT', "/api/tenants/ata/plugins/{$name}/settings", ['values' => [$field => $id]])->assertOk();
        }
        $this->inside('ata', fn () => $this->assertSame(['pixel_id' => 'C4ABCDEFGHIJ12345678'], app(PluginSettings::class)->publicValues('tiktok-pixel')));
    }

    /**
     * CSP の 1 つの指定の宛先の並び
     *
     * @return list<string>
     */
    private function directive(string $csp, string $name): array
    {
        foreach (explode(';', $csp) as $part) {
            $words = preg_split('/\s+/', trim($part)) ?: [];

            if (($words[0] ?? null) === $name) {
                return array_slice($words, 1);
            }
        }

        return [];
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $path, ?string $consent): TestResponse
    {
        if ($consent !== null) {
            $this->withCookie(ConsentCookie::NAME, $consent);
        }

        $response = $this->get("http://ata.localhost{$path}");
        tenancy()->end();
        $this->defaultCookies = [];

        return $response;
    }
}
