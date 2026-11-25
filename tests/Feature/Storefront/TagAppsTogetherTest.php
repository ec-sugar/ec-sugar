<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Storefront\Apps\AppsView;
use App\Storefront\Apps\ConsentCookie;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * タグの App 10 個（GA4・Google 広告・Microsoft Clarity・Meta ピクセル・LINEヤフー広告・X ピクセル・Pinterest タグ・
 * Microsoft 広告 UET・TikTok ピクセル・SmartNews 広告）を同じ店で同時に使う（ADR 0034）。
 * 同意したお客さんのふつうの画面に、10 個のスクリプトと、その前に核の Trusted Types の既定の関所が 1 つだけ出て、
 * 関所に渡す宛先と CSP には 10 個分の宛先がそろう（同じ宛先は 1 度だけ）。個人情報の画面には何も足さない
 */
final class TagAppsTogetherTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    /** App の名前 → その App が script-src に書いた宛先（各 App の composer.json の extra.ec-sugar.storefront.csp） */
    private const array SCRIPT_SOURCES = [
        'ga4' => ['https://www.googletagmanager.com'],
        'google-ads' => ['https://www.googletagmanager.com', 'https://www.googleadservices.com', 'https://www.google.com'],
        'clarity' => ['https://*.clarity.ms'],
        'meta-pixel' => ['https://connect.facebook.net'],
        'lineyahoo-ads' => ['https://tag.flvcdn.net'],
        'x-pixel' => ['https://static.ads-twitter.com'],
        'pinterest-tag' => ['https://s.pinimg.com'],
        'microsoft-uet' => ['https://bat.bing.com'],
        'tiktok-pixel' => ['https://analytics.tiktok.com'],
        'smartnews-ads' => ['https://cdn.smartnews-ads.com'],
    ];

    /** 解析の同意だけで出る App（ほかは「広告」の同意で出る） */
    private const array ANALYTICS_APPS = ['ga4', 'clarity'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // 核の関所のスクリプトは Vite::asset で道筋を出す。テストではビルドが無いので、入口の名前のままの道筋を返す偽物にする
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString('');
            }

            public function asset($asset, $buildDirectory = null): string
            {
                return '/build/'.$asset;
            }
        });
        $this->tenant('tat');
        // 同意の帯を出す店（既定は出さない）。帯を出さない店の動きは ConsentBannerTest
        $this->inside('tat', static fn () => app(ShopSettingsService::class)->setConsentBanner(true));
        $this->inside('tat', function (): void {
            foreach (array_keys(self::SCRIPT_SOURCES) as $app) {
                app(PluginStates::class)->set($app, true);
            }
            app(PluginSettings::class)->update('ga4', ['measurement_id' => 'G-ABC123DEF4']);
            app(PluginSettings::class)->update('google-ads', ['conversion_id' => 'AW-123456789', 'conversion_label' => 'AbC-D_efG-h12_34-567']);
            app(PluginSettings::class)->update('clarity', ['project_id' => '3t0wlogvdz']);
            app(PluginSettings::class)->update('meta-pixel', ['pixel_id' => '123456789012345']);
            app(PluginSettings::class)->update('lineyahoo-ads', ['tag_id' => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d']);
            app(PluginSettings::class)->update('x-pixel', ['pixel_id' => 'oka17', 'purchase_event_id' => 'ol288']);
            app(PluginSettings::class)->update('pinterest-tag', ['tag_id' => '2612345678901']);
            app(PluginSettings::class)->update('microsoft-uet', ['tag_id' => '343012345']);
            app(PluginSettings::class)->update('tiktok-pixel', ['pixel_id' => 'C4ABCDEFGHIJ12345678']);
            app(PluginSettings::class)->update('smartnews-ads', ['pixel_id' => 'ab124kadkkall3a134555']);
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_ten_tag_apps_share_one_core_policy_and_their_sources_add_up_in_the_csp(): void
    {
        $response = $this->shop('/', 'analytics=1&ads=1')->assertOk();
        $html = (string) $response->getContent();

        // 10 個のスクリプトが 1 つずつ
        foreach (array_keys(self::SCRIPT_SOURCES) as $app) {
            $this->assertSame(1, substr_count($html, 'data-sugar-app="'.$app.'"'), $app);
            $this->assertMatchesRegularExpression('~<script src="/apps/'.$app.'/'.$app.'\.js\?v=[0-9a-f]{12}" defer\s+data-sugar-app="'.$app.'"\s+data-sugar-page="general"~', $html, $app);
        }

        // 核の関所は 1 つだけで、どの App のスクリプトより前
        $this->assertSame(1, substr_count($html, 'data-sugar-trusted-types'));
        $policy = strpos($html, 'src="/build/'.AppsView::TRUSTED_TYPES_SCRIPT.'"');
        $this->assertNotFalse($policy);
        foreach (array_keys(self::SCRIPT_SOURCES) as $app) {
            $this->assertLessThan(strpos($html, '/apps/'.$app.'/'), $policy, $app);
        }

        // 関所が通す宛先は 10 個の App の script-src を合わせたもの（同じ宛先は 1 度だけ。connect-src・img-src は入れない）
        $this->assertSame(1, preg_match('~data-sugar-script-src="([^"]*)"~', $html, $m));
        $passed = json_decode(html_entity_decode($m[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
        $expected = array_values(array_unique(array_merge(...array_values(self::SCRIPT_SOURCES))));
        $this->assertEqualsCanonicalizing($expected, $passed);
        $this->assertSame(count($passed), count(array_unique($passed)));

        // CSP: 10 個分の宛先が種類ごとに足され、元の守り（Trusted Types）はそのまま
        $csp = $this->directives((string) $response->headers->get('Content-Security-Policy'));
        foreach ($expected as $source) {
            $this->assertContains($source, $csp['script-src'], $source);
        }
        $this->assertSame(count($csp['script-src']), count(array_unique($csp['script-src'])));
        foreach (['https://*.google-analytics.com', 'https://googleads.g.doubleclick.net', 'https://*.clarity.ms', 'https://www.facebook.com', 'https://apm.yahoo.co.jp', 'https://t.co', 'https://analytics.twitter.com', 'https://ct.pinterest.com', 'https://bat.bing.net', 'https://analytics.tiktok.com'] as $source) {
            $this->assertContains($source, $csp['connect-src'], $source);
        }
        foreach (['https://*.google-analytics.com', 'https://c.bing.com', 'https://www.facebook.com', 'https://t.co', 'https://ct.pinterest.com', 'https://bat.bing.com', 'https://analytics.tiktok.com', 'https://i.smartnews-ads.com', 'https://i6.smartnews-ads.com'] as $source) {
            $this->assertContains($source, $csp['img-src'], $source);
        }
        $this->assertSame(count($csp['connect-src']), count(array_unique($csp['connect-src'])));
        $this->assertSame(count($csp['img-src']), count(array_unique($csp['img-src'])));
        $this->assertSame(['https://www.googletagmanager.com'], $csp['frame-src']);
        $this->assertSame(["'script'"], $csp['require-trusted-types-for']);
        $this->assertSame(['default'], $csp['trusted-types']);
    }

    /**
     * よくある失敗: 解析だけに同意したお客さんに広告のタグ（解析の 2 つのほかの 8 つ）を出す。個人情報の画面に出す
     */
    public function test_only_consented_kinds_appear_and_private_pages_stay_clean(): void
    {
        $analytics = $this->shop('/', 'analytics=1&ads=0')->assertOk();
        $html = (string) $analytics->getContent();
        $csp = (string) $analytics->headers->get('Content-Security-Policy');
        $tokens = array_merge(...array_values($this->directives($csp)));
        foreach (self::SCRIPT_SOURCES as $app => $sources) {
            if (in_array($app, self::ANALYTICS_APPS, true)) {
                $this->assertStringContainsString('data-sugar-app="'.$app.'"', $html, $app);

                continue;
            }
            $this->assertStringNotContainsString('data-sugar-app="'.$app.'"', $html, $app);
            // 広告の App だけの宛先は CSP に足さない（Google 広告の www.googletagmanager.com は GA4 と同じなので除く）
            foreach (array_diff($sources, self::SCRIPT_SOURCES['ga4']) as $source) {
                $this->assertNotContains($source, $tokens, $app);
            }
        }
        foreach (['https://apm.yahoo.co.jp', 'https://t.co', 'https://analytics.twitter.com', 'https://ct.pinterest.com', 'https://bat.bing.net', 'https://i.smartnews-ads.com'] as $source) {
            $this->assertNotContains($source, $tokens, $source);
        }
        $this->assertStringNotContainsString('frame-src', $csp);

        // 個人情報の画面（注文の入力）: 全部に同意していても、スクリプトも関所も CSP の足し込みも無い
        $private = $this->shop('/checkout', 'analytics=1&ads=1');
        $this->assertStringNotContainsString('data-sugar-app=', (string) $private->getContent());
        $this->assertStringNotContainsString('data-sugar-trusted-types', (string) $private->getContent());
        $this->assertSame(config('storefront.csp'), $private->headers->get('Content-Security-Policy'));
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $path, string $consent): TestResponse
    {
        $this->withCookie(ConsentCookie::NAME, $consent);
        $response = $this->get("http://tat.localhost{$path}");
        tenancy()->end();
        $this->defaultCookies = [];

        return $response;
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
}
