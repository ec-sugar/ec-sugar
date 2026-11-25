<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Models\ShopSettings;
use App\Core\Shop\Services\ShopSettingsService;
use App\Storefront\Apps\ConsentCookie;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 解析・広告のタグの同意の帯は、既定では出さない（ADR 0034 の 4 の置き換え）。
 * 出さない店: 帯も同意の Cookie の確かめも無く、ふつうの画面に最初からタグを差し込み、外部送信の公表だけ。
 * 出す店: 今までどおり、受け入れたお客さんにだけ（AppTagsTest ほか、タグの App のテストは帯を出す店で流す）。
 * どちらでも個人情報の画面には差し込まない（AppTagsTest の経路の数え上げが両方の形で確かめる）
 */
final class ConsentBannerTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

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
        $this->tenant('cba');
        $this->inside('cba', function (): void {
            app(PluginStates::class)->set('tag-sample', true);
            app(PluginSettings::class)->update('tag-sample', ['tag_id' => 'TS-ABC123']);
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_by_default_the_tags_load_from_the_start_without_a_banner(): void
    {
        $this->assertFalse($this->inside('cba', static fn (): bool => app(ShopSettingsService::class)->showsConsentBanner()));

        // 同意の Cookie が無くても、前に「同意しない」を選んだ Cookie があっても、最初から差し込む（帯は出さない）
        foreach ([null, 'analytics=0&ads=0'] as $cookie) {
            $response = $this->shop('/', $cookie)->assertOk();
            $html = (string) $response->getContent();
            $this->assertStringContainsString('/apps/tag-sample/tag-sample.js?v=', $html, (string) $cookie);
            $this->assertStringContainsString('data-sugar-trusted-types', $html, (string) $cookie);
            $this->assertStringNotContainsString('data-test="consent-banner"', $html, (string) $cookie);
            // フッターは公表だけ（Cookie の設定のリンクは出さない）
            $this->assertStringContainsString('data-test="disclosure-link"', $html);
            $this->assertStringNotContainsString('data-test="consent-link"', $html);
            // CSP は宣言した宛先だけを足す（守りは変えない）
            $csp = (string) $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("script-src 'self' https://tags.example.test", $csp);
            $this->assertStringContainsString("connect-src 'self' https://collect.example.test", $csp);
            $this->assertStringContainsString("require-trusted-types-for 'script'", $csp);
        }

        // 個人情報の画面には入れない
        $checkout = $this->shop('/checkout', null);
        $this->assertStringNotContainsString('/apps/tag-sample/', (string) $checkout->getContent());
        $this->assertSame(config('storefront.csp'), $checkout->headers->get('Content-Security-Policy'));

        // 公表のページは、帯を出さない店の文（表示したときに送る・止め方は各事業者の案内）
        $disclosure = (string) $this->shop('/legal/external', null)->assertOk()->getContent();
        $this->assertStringContainsString('ショップのページを表示したときに行います', $disclosure);
        $this->assertStringNotContainsString('同意された場合にだけ行います', $disclosure);
        $this->assertSame(1, substr_count($disclosure, 'data-test="disclosure-row"'));

        // Cookie の設定の画面は「選ぶものは無い」
        $this->shop('/consent', null)->assertOk()->assertSee('data-test="consent-empty"', false);
    }

    public function test_a_shop_that_shows_the_banner_loads_the_tags_only_after_consent(): void
    {
        $this->inside('cba', static fn () => app(ShopSettingsService::class)->setConsentBanner(true));

        $before = $this->shop('/', null)->assertOk();
        $this->assertStringContainsString('data-test="consent-banner"', (string) $before->getContent());
        $this->assertStringNotContainsString('/apps/tag-sample/', (string) $before->getContent());
        $this->assertSame(config('storefront.csp'), $before->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('/apps/tag-sample/', (string) $this->shop('/', 'analytics=0')->getContent());

        $after = (string) $this->shop('/', 'analytics=1')->getContent();
        $this->assertStringContainsString('/apps/tag-sample/tag-sample.js?v=', $after);
        $this->assertStringContainsString('data-test="consent-link"', $after);

        $disclosure = (string) $this->shop('/legal/external', null)->getContent();
        $this->assertStringContainsString('同意された場合にだけ行います', $disclosure);
        $this->assertStringNotContainsString('ショップのページを表示したときに行います', $disclosure);
    }

    /**
     * 移行（2026_10_30_000900）: 今ある店も「出さない」になる（設定の行のある店も、前に帯を出していた店も）
     */
    public function test_the_migration_leaves_existing_shops_without_the_banner(): void
    {
        $migration = require database_path('migrations/tenant/2026_10_30_000900_add_consent_banner_to_shop_settings.php');

        $this->inside('cba', static function () use ($migration): void {
            $migration->down();
            $migration->up();
        });

        $this->assertTrue($this->inside('cba', static fn (): bool => ShopSettings::query()->exists()));
        $this->assertFalse($this->inside('cba', static fn (): bool => app(ShopSettingsService::class)->showsConsentBanner()));
        $html = (string) $this->shop('/', null)->assertOk()->getContent();
        $this->assertStringContainsString('/apps/tag-sample/tag-sample.js?v=', $html);
        $this->assertStringNotContainsString('data-test="consent-banner"', $html);
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $path, ?string $cookie): TestResponse
    {
        if ($cookie !== null) {
            $this->withCookie(ConsentCookie::NAME, $cookie);
        }

        $response = $this->get("http://cba.localhost{$path}");
        tenancy()->end();
        $this->defaultCookies = [];

        return $response;
    }
}
