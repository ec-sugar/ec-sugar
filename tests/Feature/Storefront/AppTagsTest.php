<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Plugin\Manifest\PageKind;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Storefront\Apps\AppsView;
use App\Storefront\Apps\ConsentCookie;
use App\Storefront\View\StorefrontView;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App のショップへの差し込み（ADR 0034）。使う店・同意のあるお客さん・ふつうと購入の完了の画面にだけ、核が <script src defer> を作り、
 * その応答の CSP にだけ App の宛先を足す。個人情報の画面には差し込まない（経路を全部数え上げて確かめる）
 */
final class AppTagsTest extends TestCase
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
        $this->tenant('ata');
        // 同意の帯を出す店（既定は出さない）。帯を出さない店の動きは ConsentBannerTest
        $this->inside('ata', static fn () => app(ShopSettingsService::class)->setConsentBanner(true));
        $this->tenant('atb');
        $this->inside('ata', function (): void {
            app(PluginStates::class)->set('tag-sample', true);
            app(PluginSettings::class)->update('tag-sample', ['tag_id' => 'TS-ABC123', 'api_secret' => 'very-secret-key-0001']);
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_the_tag_is_injected_only_with_consent_in_a_shop_that_uses_it(): void
    {
        // 同意の前: 帯は出るが、スクリプトも CSP の足し込みも無い
        $before = $this->shop('ata', '/')->assertOk();
        $this->assertStringContainsString('data-test="consent-banner"', (string) $before->getContent());
        $this->assertStringNotContainsString('/apps/tag-sample/', (string) $before->getContent());
        $this->assertSame(config('storefront.csp'), $before->headers->get('Content-Security-Policy'));

        // 同意する: 元の画面へ戻り、Cookie は HttpOnly・SameSite=Lax
        $decided = $this->post('http://ata.localhost/consent', ['decision' => 'all', 'return' => '/products'])->assertRedirect('http://ata.localhost/products');
        tenancy()->end();
        $cookie = $decided->getCookie(ConsentCookie::NAME);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        // ほかのサイトへ戻す道にしない
        $this->post('http://ata.localhost/consent', ['decision' => 'all', 'return' => '//evil.example/x'])->assertRedirect('http://ata.localhost');
        tenancy()->end();

        $html = (string) $this->shop('ata', '/', consent: true)->assertOk()->getContent();
        $response = $this->shop('ata', '/', consent: true);
        $this->assertMatchesRegularExpression('~<script src="/apps/tag-sample/tag-sample\.js\?v=[0-9a-f]{12}" defer\s+data-sugar-app="tag-sample"\s+data-sugar-page="general"\s+data-setting-tag-id="TS-ABC123"~', $html);
        $this->assertStringContainsString('data-setting-send-page-views="true"', $html);
        $this->assertStringContainsString('data-setting-region="jp"', $html);
        // 秘密の項目はショップに出ない
        $this->assertStringNotContainsString('very-secret-key-0001', $html);
        $this->assertStringNotContainsString('api-secret', $html);
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' https://tags.example.test", $csp);
        $this->assertStringContainsString("connect-src 'self' https://collect.example.test", $csp);
        $this->assertStringContainsString("require-trusted-types-for 'script'", $csp);
        // 公表と同意を変える道がフッターにある
        $this->assertStringContainsString('data-test="disclosure-link"', $html);
        $this->assertStringContainsString('data-test="consent-link"', $html);

        // 同意しなかった種類は差し込まない
        $this->assertStringNotContainsString('/apps/tag-sample/', (string) $this->shop('ata', '/', consent: false)->getContent());

        // 使わない店には差し込まず、帯もリンクも出さない
        $other = (string) $this->shop('atb', '/', consent: true)->getContent();
        $this->assertStringNotContainsString('/apps/tag-sample/', $other);
        $this->assertStringNotContainsString('consent-banner', $other);
        $this->assertStringNotContainsString('disclosure-link', $other);

        // 公表のページには使っている App だけ
        $this->assertSame(1, substr_count((string) $this->shop('ata', '/legal/external')->assertOk()->getContent(), 'data-test="disclosure-row"'));
        $this->shop('atb', '/legal/external')->assertOk()->assertSee('data-test="disclosure-empty"', false);
    }

    /**
     * 隔離した写し（ADR 0082）では、使う店で同意があってもタグを差し込まず、CSP も足さない（写しを開いた人のブラウザから本番の計測へ送らない）
     */
    public function test_an_isolated_copy_injects_no_tags(): void
    {
        $this->assertStringContainsString('/apps/tag-sample/', (string) $this->shop('ata', '/', consent: true)->assertOk()->getContent());

        config(['app.isolated' => true]);
        $response = $this->shop('ata', '/', consent: true)->assertOk();
        $this->assertStringNotContainsString('/apps/tag-sample/', (string) $response->getContent());
        $this->assertSame(config('storefront.csp'), $response->headers->get('Content-Security-Policy'));
    }

    /**
     * Trusted Types の既定の関所は核が作る: App を差し込む画面では、関所のスクリプトが App のスクリプトより前にあり、
     * 通してよい外の宛先はこの画面で差し込んだ App の script-src だけ。CSP は関所を default の 1 つ・1 度だけに絞る
     */
    public function test_the_core_trusted_types_policy_comes_before_the_app_scripts(): void
    {
        $html = (string) $this->shop('ata', '/', consent: true)->assertOk()->getContent();
        $policy = strpos($html, 'src="/build/'.AppsView::TRUSTED_TYPES_SCRIPT.'"');
        $app = strpos($html, '/apps/tag-sample/tag-sample.js?v=');
        $this->assertNotFalse($policy);
        $this->assertNotFalse($app);
        $this->assertLessThan($app, $policy);
        $this->assertSame(1, substr_count($html, 'data-sugar-trusted-types'));
        // 宛先は App の script-src だけ（connect-src・img-src の宛先は入れない）
        $this->assertMatchesRegularExpression('~<script src="/build/resources/storefront/trusted-types\.js" defer data-sugar-trusted-types data-sugar-script-src="\[&quot;https://tags\.example\.test&quot;\]"></script>~', $html);

        // 差し込まない画面（同意の前・App を使わない店・個人情報の画面）には関所のスクリプトを出さない
        $this->assertStringNotContainsString('data-sugar-trusted-types', (string) $this->shop('ata', '/', consent: false)->getContent());
        $this->assertStringNotContainsString('data-sugar-trusted-types', (string) $this->shop('atb', '/', consent: true)->getContent());
        $this->assertStringNotContainsString('data-sugar-trusted-types', (string) $this->shop('ata', '/checkout', consent: true)->getContent());

        // CSP: 作れる関所は default だけで、作り直しも許さない（App と外のタグは関所を作れない）。どの画面でも同じ
        foreach (['/', '/checkout'] as $path) {
            $csp = (string) $this->shop('ata', $path, consent: true)->headers->get('Content-Security-Policy');
            $this->assertMatchesRegularExpression('~(?:\A|; )trusted-types default(?:;|\z)~', $csp, $path);
            $this->assertStringNotContainsString('allow-duplicates', $csp, $path);
        }
    }

    /**
     * 守り (1): 個人情報の画面には差し込まない。店頭の経路を全部数え上げ、差し込んでよい画面（核が決めた一覧）以外では、
     * 同意があっても App のスクリプトが無く、CSP が元のままであることを確かめる。経路を足したらここに足す（足し忘れは落ちる）。
     * 同意の帯を出す店（同意の Cookie あり）と出さない店（既定。Cookie なし）の両方で同じ
     */
    public function test_no_tag_and_no_extra_csp_on_any_page_but_the_allowed_ones(): void
    {
        $paths = [
            'storefront.home' => ['/', true],
            'storefront.products' => ['/products', true],
            'storefront.cart' => ['/cart', true],
            'storefront.legal' => ['/legal/commerce', true],
            'storefront.product' => ['/products/01HZZZZZZZZZZZZZZZZZZZZZZZ', false], // 無い商品はエラーの画面（個人情報の扱い）
            'storefront.category' => ['/categories/none', false],
            'storefront.checkout' => ['/checkout', false],
            'storefront.checkout.confirm' => ['/checkout/confirm', false],
            'storefront.checkout.complete' => ['/checkout/complete', false], // 注文が無ければトップへ戻す
            'storefront.orders.lookup' => ['/orders', false],
            'storefront.orders.show' => ['/orders/01HZZZZZZZZZZZZZZZZZZZZZZZ', false],
            'storefront.postal' => ['/postal/1500001', false],
            'storefront.privacy' => ['/privacy', false], // 本文の無い店はエラーの画面。本文のある店は差し込んでよい画面（privacy。ADR 0094）
            'storefront.disclosure' => ['/legal/external', false],
            'storefront.consent' => ['/consent', false],
            'storefront.apps.asset' => ['/apps/tag-sample/tag-sample.js', false],
            'storefront.media.listing' => ['/media/listings/01HZZZZZZZZZZZZZZZZZZZZZZZ/large', false],
            'storefront.media.shop' => ['/media/shop/01HZZZZZZZZZZZZZZZZZZZZZZZ/large', false],
            // 検索エンジン向けの文字と XML（ADR 0061）。画面ではないので差し込まない
            'storefront.robots' => ['/robots.txt', false],
            'storefront.sitemap' => ['/sitemap.xml', false],
            // 店が選んだ見た目の CSS（ADR 0097）。画面ではないので差し込まない（印の合わない・署名の無いものは中身の無い 404。ThemeStyleTest）
            'storefront.theme.style' => ['/theme/style-0000000000000000.css', false],
            'storefront.preview.theme.style' => ['/preview/themes/default/style.css', false],
            'storefront.password' => ['/password', false], // 公開中の店ではトップへ送る。準備中の画面も差し込まない（ShopPasswordTest）
            'storefront.preview' => ['/preview/products/01HZZZZZZZZZZZZZZZZZZZZZZZ', false], // 署名の無いプレビューはエラーの画面。署名の合うプレビューも差し込まない（ListingPreviewTest）
            'storefront.preview.theme' => ['/preview/themes/default/home', false], // テーマの下書きのプレビューも同じ（ThemePreviewTest）
            'storefront.preview.theme.section' => ['/preview/themes/default/home/section', false], // 区画の試し見の断片は中身の無い 404（ThemeSectionTrialTest）
            'storefront.page' => ['/pages/none', false], // 無い・下書きの自由なページはエラーの画面。公開したページは差し込んでよい画面（page。ADR 0039 区切り 3）
            // 会員の画面（ADR 0044）: 核が描き、どれも差し込まない。会員でなければログインの画面へ送る
            'storefront.account.login' => ['/account/login', false],
            'storefront.account.code' => ['/account/login/code', false],
            'storefront.account' => ['/account', false],
            'storefront.account.addresses' => ['/account/addresses', false],
            'storefront.account.address' => ['/account/addresses/01HZZZZZZZZZZZZZZZZZZZZZZZ', false],
            'storefront.account.confirm' => ['/account/confirm', false],
            'storefront.account.leave' => ['/account/leave', false],
            'storefront.account.email' => ['/account/email', false],
            'storefront.account.passkeys' => ['/account/passkeys', false],
            // デモの店の「送るはずだったメール」（ADR 0086）: 来訪者のメールを出す画面なので差し込まない（デモでない店では見つかりません）
            'storefront.demo.mails' => ['/demo/mails', false],
            '(fallback)' => ['/no-such-page', false],
        ];

        $routes = array_filter(Route::getRoutes()->getRoutes(), static fn (LaravelRoute $r): bool => in_array('GET', $r->methods(), true)
            && array_intersect(['storefront', 'storefront-media'], $r->gatherMiddleware()) !== []);
        $this->assertNotEmpty($routes);

        foreach ([true, false] as $banner) {
            $this->inside('ata', static fn () => app(ShopSettingsService::class)->setConsentBanner($banner));

            foreach ($routes as $route) {
                $name = $route->isFallback ? '(fallback)' : (string) $route->getName();

                if (str_starts_with($name, 'storefront.plugin.')) {
                    continue; // プラグインの画面は核の画面を描かない
                }

                $this->assertArrayHasKey($name, $paths, "店頭の経路 {$name} の扱いを、このテストの一覧に足す");
                [$path, $allowed] = $paths[$name];
                $mode = $banner ? '帯を出す店' : '帯を出さない店';
                $response = $this->shop('ata', $path, consent: $banner ? true : null);
                $html = (string) $response->getContent();

                $this->assertSame($allowed, str_contains($html, '/apps/tag-sample/tag-sample.js?v='), "{$mode} {$name}: 差し込みの有無");
                $this->assertSame($allowed, str_contains($html, 'data-sugar-trusted-types'), "{$mode} {$name}: 核の関所の有無");
                if (! $allowed) {
                    $this->assertSame(config('storefront.csp'), $response->headers->get('Content-Security-Policy'), "{$mode} {$name}: CSP が足されている");
                }
            }
        }

        // 本文のあるプライバシーポリシー（ADR 0094）はふつうの画面（店の人の文だけ）。差し込み、核の画面から公表のページへの道を添える
        $this->inside('ata', static fn () => app(ShopSettingsService::class)->setPrivacyPolicy(['body' => '当店の個人情報の扱い']));
        $privacy = (string) $this->shop('ata', '/privacy')->assertOk()->getContent();
        $this->assertStringContainsString('/apps/tag-sample/tag-sample.js?v=', $privacy);
        $this->assertStringContainsString('data-test="privacy-disclosure"', $privacy);

        // 画面の種類の一覧（核が決める）。注文の入力・確認・ご注文の状況・エラーは差し込まない
        foreach (['checkout', 'checkout-confirm', 'order-lookup', 'order', 'error', 'anything-new'] as $page) {
            $this->assertSame(PageKind::Private, StorefrontView::kindOf($page), $page);
        }
        $this->assertSame(PageKind::Purchase, StorefrontView::kindOf('checkout-complete'));
    }

    public function test_the_script_file_is_served_from_the_same_origin_and_nothing_outside(): void
    {
        $this->shop('ata', '/apps/tag-sample/tag-sample.js')->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        // 使わない店・宣言していないファイル・ディレクトリの外へ出る書き方
        $this->shop('atb', '/apps/tag-sample/tag-sample.js')->assertNotFound();
        foreach (['/apps/tag-sample/other.js', '/apps/tag-sample/..%2Fcomposer.json', '/apps/tag-sample/%2e%2e%2fsrc%2fTagSampleServiceProvider.php', '/apps/tag-sample/..%2F..%2Fcomposer.js', '/apps/faq/tag-sample.js', '/apps/nope/tag-sample.js'] as $path) {
            $this->assertNotSame(200, $this->shop('ata', $path)->status(), $path);
        }
    }

    /**
     * @return TestResponse<Response>
     */
    private function shop(string $id, string $path, ?bool $consent = null): TestResponse
    {
        if ($consent !== null) {
            $this->withCookie(ConsentCookie::NAME, 'analytics='.($consent ? '1' : '0').'&ads=0');
        }

        $response = $this->get("http://{$id}.localhost{$path}");
        tenancy()->end();
        $this->defaultCookies = [];

        return $response;
    }
}
