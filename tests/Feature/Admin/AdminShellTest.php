<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\Support\OperatorSession;
use Tests\TestCase;

/**
 * 管理画面の SPA の殻と防御ヘッダ。画面の動きはブラウザのテスト（tests/e2e）で確かめる。
 */
final class AdminShellTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;

    private string $publicPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();

        // ビルドの成果物（public/build）に頼らない。@vite が読む manifest をテスト用に置く
        $this->publicPath = sys_get_temp_dir().'/ec-sugar-shell-'.Str::random(8);
        (new Filesystem)->ensureDirectoryExists($this->publicPath.'/build');
        file_put_contents($this->publicPath.'/build/manifest.json', json_encode([
            'resources/admin/main.js' => ['file' => 'assets/main-test.js', 'src' => 'resources/admin/main.js', 'isEntry' => true, 'css' => ['assets/main-test.css']],
        ]));
        $this->app->usePublicPath($this->publicPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->publicPath);
        parent::tearDown();
    }

    public function test_the_shell_is_served_on_every_spa_page_without_data_or_inline_code(): void
    {
        foreach (['/', '/login', '/enroll', '/passkeys', '/tenants', '/tenants/shop1', '/tenants/a_b-1', '/tenants/shop1/listings', '/tenants/shop1/listings/new', '/tenants/shop1/listings/01k8z3x5y7a9b1c3d5e7f9g1h3', '/tenants/shop1/transactions', '/tenants/shop1/transactions/01k8z3x5y7a9b1c3d5e7f9g1h3', '/tenants/shop1/customers', '/tenants/shop1/customers/01k8z3x5y7a9b1c3d5e7f9g1h3', '/tenants/shop1/sales', '/tenants/shop1/settings', '/tenants/shop1/settings/legal', '/tenants/shop1/settings/privacy', '/tenants/shop1/settings/order-terms', '/tenants/shop1/settings/payments', '/tenants/shop1/settings/shipping', '/tenants/shop1/settings/basic', '/tenants/shop1/settings/records', '/tenants/shop1/settings/consent', '/tenants/shop1/settings/publish', '/tenants/shop1/settings/delivery-dates', '/tenants/shop1/settings/order-fields', '/tenants/shop1/settings/gift-cards', '/tenants/shop1/settings/seo', '/tenants/shop1/settings/agents', '/help', '/help/payments', '/help/orders', '/help/faq', '/tenants/shop1/plugins', '/tenants/shop1/plugins/ga4', '/tenants/shop1/plugins/plugin-faq', '/tenants/shop1/theme', '/tenants/shop1/theme/pages/home', '/tenants/shop1/theme/pages/page-01k8z3x5y7a9b1c3d5e7f9g1h3', '/tenants/shop1/pages'] as $page) {
            $response = $this->send('GET', $page)->assertOk();
            $html = (string) $response->getContent();

            $this->assertStringContainsString('<div id="app"></div>', $html, $page);
            $this->assertStringContainsString('<script type="module" src="http://admin.localhost/build/assets/main-test.js"></script>', $html, $page);
            $this->assertStringContainsString('href="http://admin.localhost/build/assets/main-test.css"', $html, $page);
            // インラインの script も style 属性も無い（CSP で禁じているので、あれば画面が動かない）
            $this->assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html), $page);
            $this->assertSame(0, preg_match('/\sstyle=|<style/i', $html), $page);
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'), $page);
        }
    }

    /**
     * SPA の経路（resources/admin/router.js）にある画面は、URL を直接開いても（読み込み直しても）画面が返る。
     * 画面を足したのにサーバーの受け付ける URL の一覧（routes/admin.php）に足し忘れると落ちる。
     */
    public function test_every_page_in_the_spa_router_can_be_opened_directly(): void
    {
        $router = (string) file_get_contents(resource_path('admin/router.js'));
        preg_match_all("/path: '(\/[^']*)'/", $router, $matches);
        $paths = array_values(array_filter($matches[1], static fn (string $path): bool => ! str_starts_with($path, '/:path')));
        $this->assertGreaterThan(8, count($paths));

        foreach ($paths as $path) {
            // 経路の引数を、その形に合う見本の値にする
            $page = (string) preg_replace_callback('/:(\w+)\([^)]*\)/', static fn (array $m): string => match ($m[1]) {
                'tenant' => 'shop1',
                'plugin' => 'ga4',
                'page' => 'home',
                'article' => 'payments',
                // OAuth の許可の頼みの合い言葉（乱数 32 バイトの base64url。ADR 0101）
                'request' => str_repeat('a', 43),
                default => '01k8z3x5y7a9b1c3d5e7f9g1h3',
            }, $path);
            $html = (string) $this->send('GET', $page)->assertOk()->getContent();
            $this->assertStringContainsString('<div id="app"></div>', $html, "{$path} を直接開くと画面が返らない（routes/admin.php の SPA の URL の一覧に足す）");
        }
    }

    public function test_the_shell_does_not_depend_on_who_is_signed_in(): void
    {
        $anonymous = (string) $this->send('GET', '/tenants')->getContent();
        [$operator] = $this->enrolledOperator();
        $signedIn = (string) $this->send('GET', '/tenants')->getContent();

        $this->assertSame($anonymous, $signedIn);
        $this->assertStringNotContainsString($operator->email, $signedIn);
    }

    public function test_only_listed_pages_get_the_shell(): void
    {
        foreach (['/nope', '/tenants/UPPER', '/tenants/a/b', '/tenants/-x', '/enroll/x', '/build', '/api', '/auth', '/tenants/shop1/listings/new/x', '/tenants/shop1/listings/01K8Z3X5Y7A9B1C3D5E7F9G1H3', '/tenants/shop1/listings/short', '/tenants/shop1/transactions/new', '/tenants/shop1/transactions/01K8Z3X5Y7A9B1C3D5E7F9G1H3/personal', '/tenants/shop1/customers/new', '/tenants/shop1/customers/01K8Z3X5Y7A9B1C3D5E7F9G1H3', '/tenants/shop1/customers/01k8z3x5y7a9b1c3d5e7f9g1h3/personal', '/tenants/shop1/customersx', '/tenants/shop1/sales/x', '/tenants/shop1/salesx', '/tenants/shop1/settings/nope', '/tenants/shop1/settings/legal/x', '/tenants/shop1/settings/privacy/x', '/tenants/shop1/settings/tax', '/tenants/shop1/settings/records/x', '/tenants/shop1/settings/consent/x', '/tenants/shop1/settings/seo/x', '/tenants/shop1/settings/agents/x', '/tenants/shop1/settings/delivery-dates/x', '/tenants/shop1/settings/order-fields/x', '/help/x', '/helpx', '/help/Orders', '/help/1faq', '/help/orders/x', '/help/'.str_repeat('a', 33), '/tenants/shop1/settingslegal', '/tenants/shop1/plugins/GA4', '/tenants/shop1/plugins/4ga', '/tenants/shop1/plugins/g', '/tenants/shop1/plugins/ga4/x', '/tenants/shop1/plugins/'.str_repeat('a', 33), '/tenants/shop1/theme/pages', '/tenants/shop1/theme/pages/Home', '/tenants/shop1/theme/pages/home/x', '/tenants/shop1/theme/pages/1home', '/tenants/shop1/pages/about', '/tenants/shop1/pagesx', '/tenants/shop1/apps', '/tenants/shop1/apps/Subscription', '/tenants/shop1/apps/subscription/01K8Z3X5Y7A9B1C3D5E7F9G1H3', '/tenants/shop1/apps/subscription/short', '/tenants/shop1/apps/subscription/01k8z3x5y7a9b1c3d5e7f9g1h3/x', '/oauth/consent/short', '/oauth/consent/'.str_repeat('a', 44), '/oauth/consentx'] as $page) {
            $this->send('GET', $page)->assertNotFound();
        }

        $this->send('POST', '/tenants')->assertStatus(405);
    }

    public function test_admin_responses_carry_the_strict_headers(): void
    {
        foreach ([$this->send('GET', '/login'), $this->send('GET', '/auth/csrf'), $this->send('GET', '/auth/me'), $this->send('GET', '/nope')] as $response) {
            $csp = (string) $response->headers->get('Content-Security-Policy');

            $this->assertStringContainsString("default-src 'none'", $csp);
            $this->assertStringContainsString("script-src 'self';", $csp);
            $this->assertStringContainsString("style-src 'self';", $csp);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringContainsString("require-trusted-types-for 'script'", $csp);
            $this->assertStringNotContainsString('unsafe', $csp);
            $this->assertStringNotContainsString('data:', $csp);
            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }

        // 接続元の制限で断った 404 にも付く
        config(['admin.allowed_ips' => ['10.1.2.3/32']]);
        $this->assertStringContainsString("default-src 'none'", (string) $this->send('GET', '/login')->assertNotFound()->headers->get('Content-Security-Policy'));
    }

    public function test_other_hosts_keep_their_own_headers(): void
    {
        $response = $this->get('http://localhost/')->assertOk();

        $this->assertSame(config('security.csp'), $response->headers->get('Content-Security-Policy'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    /**
     * Laravel 13 の CSRF の検査は、Sec-Fetch-Site が same-origin ならトークン無しで通す（ブラウザが付け、JS からは偽れない）。
     * same-site まで通すと、同じ親ドメインの店頭（shop.example.com）から管理画面（admin.example.com）へ送れてしまう。
     * トークンを使わない設定（originOnly）にもしない。どちらも有効にしていないことを見張る（本物の拒否は tests/e2e で確かめる）。
     */
    public function test_csrf_does_not_trust_same_site_storefronts(): void
    {
        $class = new ReflectionClass(PreventRequestForgery::class);

        $this->assertFalse($class->getStaticPropertyValue('allowSameSite'));
        $this->assertFalse($class->getStaticPropertyValue('originOnly'));
        $this->assertContains(ValidateCsrfToken::class, app(Router::class)->getMiddlewareGroups()['admin']);
    }

    /**
     * 画面のコードに、Trusted Types と CSP をすり抜ける書き方や外部への参照が無いこと。
     * Vue の 'vue' ポリシーは HTML をそのまま通すので、v-html などを使うと守りが無くなる。
     */
    public function test_the_admin_source_avoids_raw_html_and_external_resources(): void
    {
        $forbidden = [
            '/v-html/' => 'v-html（HTML をそのまま差し込む）',
            '/\binnerHTML\b|\bouterHTML\b|insertAdjacentHTML|document\.write/' => 'DOM への HTML の書き込み',
            '/\beval\s*\(|new\s+Function\s*\(|setTimeout\s*\(\s*[\'"`]/' => '文字列をコードとして実行',
            '/:style\b|v-bind:style|\bstyle="/' => 'インラインの style（CSP の style-src で止まる）',
            '/https?:\/\//' => '外部の URL（CSP は自分のオリジンだけ）',
            '/localStorage|sessionStorage|indexedDB/' => 'ブラウザの保存領域（セッション以外に情報を残さない）',
        ];

        $files = 0;

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('admin'), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $files++;

            foreach ($forbidden as $pattern => $what) {
                $this->assertSame(0, preg_match($pattern, $source), "{$file->getFilename()}: {$what}");
            }
        }

        $this->assertGreaterThan(5, $files);
    }

    /**
     * 画面（pages/）は部品を組み合わせるだけ。画面のファイルに書いてよいのは並べ方のクラスだけで、
     * 色・文字・角丸・影などの見た目は components/ui の部品に書く（統一感を部品で保つ）。
     */
    public function test_pages_only_arrange_components_and_leave_the_look_to_them(): void
    {
        $layout = '/\A(?:'.implode('|', [
            'flex|inline-flex|grid|block|inline|inline-block|contents|hidden|sr-only|relative|absolute|sticky|fixed|grow|shrink|shrink-0|grow-0',
            'flex-(?:row|col|wrap|nowrap|1|auto|none|initial)',
            '(?:grid-cols|grid-rows|col-span|row-span|col-start|col-end)-[\w\[\]-]+',
            'gap(?:-[xy])?-[\w.]+|space-[xy]-[\w.]+',
            '(?:items|justify|self|place-items|place-self|content|order|basis)-[\w-]+',
            '(?:min-|max-)?(?:w|h)-[\w.\/\[\]-]+|size-[\w.]+',
            '-?[mp][trblxy]?-[\w.]+',
            '-?(?:top|right|bottom|left|inset)(?:-[xy])?-[\w.]+|z-\d+',
            'overflow(?:-[xy])?-(?:auto|hidden|scroll|visible)',
            'text-(?:left|right|center)|pointer-events-none',
            'num',
        ]).')\z/';

        $files = 0;

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('admin/pages'), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $files++;

            // template の中の class="…" と :class="…"（:class は中の文字列だけ）
            $template = (string) preg_replace('/\A.*?<template>|<\/template>\s*\z/s', '', $source);
            preg_match_all('/(?<![\w:-])(:?)class="([^"]*)"/', $template, $attributes, PREG_SET_ORDER);

            foreach ($attributes as [, $dynamic, $value]) {
                $strings = $dynamic === ':' ? (preg_match_all('/\'([^\']*)\'|`([^`]*)`/', $value, $literals) ? array_merge($literals[1], $literals[2]) : []) : [$value];

                foreach (preg_split('/\s+/', implode(' ', $strings), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
                    // md: や sm: などの画面幅の前置きは外して見る（hover: などの状態の前置きは見た目なので外さない）
                    $bare = (string) preg_replace('/\A(?:(?:sm|md|lg|xl|2xl):)+/', '', $class);
                    $this->assertMatchesRegularExpression($layout, $bare, "{$file->getFilename()}: 見た目のクラス「{$class}」は部品（components/ui）に書く");
                }
            }
        }

        $this->assertGreaterThan(5, $files);
    }

    /**
     * 色は admin.css のトークン（accent・ink など）だけを通す（統一感と、後から色を変えたときに全画面へそろって効くこと）。
     * 画面や部品に色の値・Tailwind の既定の色・その場限りの色を書いたら落ちる。
     */
    public function test_the_admin_screens_use_only_design_tokens_for_color(): void
    {
        $palette = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|black|white';
        $forbidden = [
            '/#[0-9a-fA-F]{3,8}\b(?![\w-])/' => '色の値（トークンを使う）',
            '/\b(?:bg|text|ring|border|outline|fill|stroke|accent|decoration|divide|placeholder|shadow|from|via|to|caret)-(?:'.$palette.')(?:-\d{2,3})?\b/' => 'Tailwind の既定の色（トークンを使う）',
            '/-\[(?:#|rgb|hsl|oklch|color)/' => 'その場限りの色（トークンを使う）',
        ];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('admin'), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            // トークンを定義する場所だけは色の値を持つ。画像（ロゴなど）は書き方の決まりの外（中のバイトが色の値に見えることがある）
            if ($file->getFilename() === 'admin.css' || ! in_array($file->getExtension(), ['vue', 'js', 'css'], true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            foreach ($forbidden as $pattern => $what) {
                $this->assertSame(0, preg_match($pattern, $source, $match), "{$file->getFilename()}: {$what}: ".($match[0] ?? ''));
            }
        }
    }
}
