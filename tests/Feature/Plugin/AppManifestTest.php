<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Manifest\AppCategory;
use App\Core\Plugin\Manifest\AppManifest;
use App\Core\Plugin\Manifest\ConsentKind;
use App\Core\Plugin\Manifest\CspSources;
use App\Core\Plugin\Manifest\InvalidAppManifestException;
use App\Core\Plugin\Manifest\PageKind;
use App\Core\Plugin\Manifest\SettingType;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\Support\AppContract;
use Tests\TestCase;

/**
 * App のマニフェスト（composer.json の extra.ec-sugar。ADR 0034）の形の確かめ。形が違えば登録で止める。
 * CSP の宛先は https のホストだけ（'unsafe-*'・data:・* だけ・IP の住所・http を断る。*. のサブドメインは許す）。
 * スクリプトは assets/ の中の .js だけで、外を指すリンクや個人情報の画面は断る
 */
final class AppManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/sugar-app-manifest-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->dir.'/assets');
        File::put($this->dir.'/assets/tag.js', '// tag');
        File::put($this->dir.'/secret.js', '// assets の外');
        File::put($this->dir.'/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_sample_app_is_registered_with_its_manifest(): void
    {
        $app = app(PluginRegistry::class)->get('tag-sample')->app();

        $this->assertSame(['tag-sample.js'], array_map(static fn ($s): string => $s->file, $app->scripts));
        $this->assertSame([PageKind::General, PageKind::Purchase], $app->scripts[0]->pages);
        $this->assertSame(['script-src' => ['https://tags.example.test'], 'connect-src' => ['https://collect.example.test'], 'img-src' => ['https://*.example.test']], $app->csp);
        $this->assertSame([ConsentKind::Analytics], $app->consent);
        $this->assertSame(['tag_id', 'send_page_views', 'region', 'api_secret'], array_keys($app->settings));
        $this->assertTrue($app->settings['api_secret']->secret);
        $this->assertSame(SettingType::Choice, $app->settings['region']->type);
        $this->assertCount(1, $app->disclosures);
        // マニフェストの無いプラグインは、核の口を使わない
        $this->assertSame([], app(PluginRegistry::class)->get('faq')->app()->scripts);
    }

    public function test_a_well_formed_manifest_is_read(): void
    {
        $app = AppManifest::fromArray($this->manifest(), $this->dir);

        $this->assertSame('tag.js', $app->scripts[0]->file);
        $this->assertSame(realpath($this->dir.'/assets/tag.js'), $app->scripts[0]->path);
        $this->assertSame(['https://*.tags.example.com', 'https://example.com/collect'], $app->csp['connect-src']);
        $this->assertSame('~\A(?:G-[A-Z0-9]{4,12})\z~u', $app->settings['tag_id']->regex());
        $this->assertNotNull($app->script('tag.js'));
        $this->assertNull($app->script('other.js'));
    }

    /**
     * CSP の宛先の形。断る形を多く並べる
     */
    public function test_csp_sources_must_be_https_hosts(): void
    {
        foreach (['https://example.com', 'https://www.google-analytics.com', 'https://*.example.com', 'https://a.b.example.co.jp', 'https://example.com:8443', 'https://example.com/path/to', 'https://xn--eckwd4c7c.xn--zckzah'] as $ok) {
            $this->assertNull(CspSources::problem($ok), $ok);
        }

        foreach ([
            "'unsafe-inline'", "'unsafe-eval'", "'unsafe-hashes'", "'self'", "'nonce-abc'", "'sha256-abc='", "'strict-dynamic'", "'wasm-unsafe-eval'",
            'data:', 'blob:', 'filesystem:', 'javascript:', 'https:', 'http:', '*', '*.example.com', 'https://*', 'https://*.com', 'https://*.*.example.com',
            'http://example.com', 'ws://example.com', 'wss://example.com', '//example.com', 'example.com',
            'https://127.0.0.1', 'https://10.0.0.1:8080', 'https://[::1]', 'https://[2001:db8::1]', 'https://192.168.5.6',
            'https://localhost', 'https://intranet', 'https://Example.com', 'https://example.com/a b', 'https://example.com;script-src *',
            'https://example.com https://evil.example', "https://example.com'", 'https://exa_mple.com', 'https://-example.com', 'https://example.com/?q=1',
            'https://a.example.com/'.str_repeat('a', 300), '', 1, null, ['https://example.com'],
        ] as $bad) {
            $this->assertNotNull(CspSources::problem($bad), var_export($bad, true));
            $this->refused(['storefront' => ['scripts' => [['file' => 'tag.js', 'pages' => ['general']]], 'csp' => ['script-src' => [$bad]], 'consent' => []], 'disclosure' => $this->disclosure()], 'storefront.csp.script-src.0');
        }

        // 足せる種類は 4 つだけ（default-src・style-src・form-action などは足せない）
        foreach (['default-src', 'style-src', 'form-action', 'base-uri', 'object-src', 'worker-src', 'require-trusted-types-for', 'trusted-types'] as $directive) {
            $this->refused(['storefront' => ['scripts' => [['file' => 'tag.js', 'pages' => ['general']]], 'csp' => [$directive => ['https://example.com']], 'consent' => []], 'disclosure' => $this->disclosure()], 'storefront.csp');
        }

        // 多すぎる宛先
        $many = array_map(static fn (int $i): string => "https://h{$i}.example.com", range(1, CspSources::MAX_PER_DIRECTIVE + 1));
        $this->refused(['storefront' => ['scripts' => [['file' => 'tag.js', 'pages' => ['general']]], 'csp' => ['img-src' => $many], 'consent' => []], 'disclosure' => $this->disclosure()], 'storefront.csp.img-src');
    }

    public function test_scripts_stay_inside_assets_and_off_private_pages(): void
    {
        $script = fn (mixed $file, mixed $pages = ['general']): array => ['storefront' => ['scripts' => [['file' => $file, 'pages' => $pages]], 'consent' => []]];

        // ディレクトリの外・ほかの拡張子・名前の形
        foreach (['../secret.js', 'assets/tag.js', '/etc/passwd', 'tag.mjs', 'tag.js.php', 'TAG.js', '.hidden.js', 'missing.js', "tag\0.js", 'tag.js/', 'sub/tag.js', '%2e%2e%2fsecret.js', 1] as $file) {
            $this->refused($script($file), 'storefront.scripts.0.file');
        }

        // assets/ の中から外を指すリンク
        symlink($this->dir.'/secret.js', $this->dir.'/assets/link.js');
        $this->refused($script('link.js'), 'storefront.scripts.0.file');

        // 大きすぎる
        File::put($this->dir.'/assets/big.js', str_repeat('a', AppManifest::MAX_SCRIPT_BYTES + 1));
        $this->refused($script('big.js'), 'storefront.scripts.0.file');

        // 個人情報の画面・知らない画面・空
        foreach ([['private'], ['general', 'private'], ['checkout'], ['order'], [], 'general', [1]] as $pages) {
            $this->refused($script('tag.js', $pages), 'storefront.scripts.0.pages');
        }

        // 二重・多すぎ・無い
        $this->refused(['storefront' => ['scripts' => [['file' => 'tag.js', 'pages' => ['general']], ['file' => 'tag.js', 'pages' => ['purchase']]], 'consent' => []]], 'storefront.scripts.1.file');
        $this->refused(['storefront' => ['scripts' => [], 'consent' => []]], 'storefront.scripts');
        $this->refused(['storefront' => ['consent' => []]], 'storefront.scripts');
        // 同意の種類は必ず書く・決まった種類だけ
        $this->refused(['storefront' => ['scripts' => [['file' => 'tag.js', 'pages' => ['general']]]]], 'storefront.consent');
        $this->refused(['storefront' => ['scripts' => [['file' => 'tag.js', 'pages' => ['general']]], 'consent' => ['marketing']]], 'storefront.consent');
        // 外の宛先を書いたら公表も書く
        $this->refused(['storefront' => ['scripts' => [['file' => 'tag.js', 'pages' => ['general']]], 'csp' => ['script-src' => ['https://example.com']], 'consent' => []]], 'disclosure');
        // HTML を渡す口は無い（知らない書き方は断る）
        $this->refused(['storefront' => ['scripts' => [['file' => 'tag.js', 'pages' => ['general']]], 'consent' => [], 'html' => '<script>alert(1)</script>']], 'storefront');
        $this->refused(['storefront' => ['scripts' => [['file' => 'tag.js', 'pages' => ['general'], 'inline' => 'alert(1)']], 'consent' => []]], 'storefront.scripts.0');
        $this->refused(['head' => '<script></script>'], 'extra.ec-sugar');
    }

    public function test_settings_and_disclosures_are_declared_in_the_fixed_shapes(): void
    {
        $setting = fn (array $spec): array => ['settings' => ['key' => $spec]];

        foreach ([
            [['type' => 'html', 'label' => 'x'], 'settings.key.type'],
            [['type' => 'text', 'label' => 'x', 'max' => 10], 'settings.key.pattern'],
            [['type' => 'text', 'label' => 'x', 'pattern' => 'a+'], 'settings.key.max'],
            [['type' => 'text', 'label' => 'x', 'pattern' => 'a+', 'max' => 201], 'settings.key.max'],
            [['type' => 'text', 'label' => 'x', 'pattern' => 'a+', 'max' => 0], 'settings.key.max'],
            [['type' => 'text', 'label' => 'x', 'pattern' => '(unclosed', 'max' => 10], 'settings.key.pattern'],
            [['type' => 'text', 'label' => 'x', 'pattern' => 'a~b', 'max' => 10], 'settings.key.pattern'],
            [['type' => 'text', 'label' => 'x', 'pattern' => 'a+', 'max' => 10, 'default' => 'aaa'], 'settings.key.default'],
            [['type' => 'toggle', 'label' => 'x', 'secret' => true], 'settings.key.secret'],
            [['type' => 'toggle', 'label' => 'x', 'default' => 'yes'], 'settings.key.default'],
            [['type' => 'toggle', 'label' => 'x', 'pattern' => 'a'], 'settings.key.pattern'],
            [['type' => 'choice', 'label' => 'x'], 'settings.key.options'],
            [['type' => 'choice', 'label' => 'x', 'options' => ['A B' => '名前']], 'settings.key.options'],
            [['type' => 'choice', 'label' => 'x', 'options' => ['a' => 'あ'], 'default' => 'b'], 'settings.key.default'],
            [['type' => 'toggle', 'label' => ''], 'settings.key.label'],
            [['type' => 'toggle', 'label' => str_repeat('長', 31)], 'settings.key.label'],
            [['type' => 'toggle', 'label' => 'x', 'required' => 'yes'], 'settings.key.required'],
            [['type' => 'toggle', 'label' => 'x', 'onload' => 'alert(1)'], 'settings.key'],
            // 写真（ロゴなど）は ID だけ。形の決まり・既定・選択肢・秘密は書けない
            [['type' => 'media', 'label' => 'x', 'pattern' => 'a+', 'max' => 10], 'settings.key.pattern'],
            [['type' => 'media', 'label' => 'x', 'default' => '01jbq0000000000000000000zz'], 'settings.key.default'],
            [['type' => 'media', 'label' => 'x', 'options' => ['a' => 'あ']], 'settings.key.options'],
            [['type' => 'media', 'label' => 'x', 'secret' => true], 'settings.key.secret'],
        ] as [$spec, $path]) {
            $this->refused($setting($spec), $path);
        }

        $this->refused(['settings' => ['Bad-Key' => ['type' => 'toggle', 'label' => 'x']]], 'settings');
        $this->refused(['settings' => [['type' => 'toggle', 'label' => 'x']]], 'settings');

        foreach ([
            [['company' => 'A', 'data' => 'B', 'purpose' => 'C', 'policy' => 'http://example.com'], 'disclosure.0.policy'],
            [['company' => 'A', 'data' => 'B', 'purpose' => 'C', 'policy' => 'javascript:alert(1)'], 'disclosure.0.policy'],
            [['company' => 'A', 'data' => 'B', 'purpose' => 'C', 'policy' => 'https://example.com/"><script>'], 'disclosure.0.policy'],
            [['company' => 'A', 'data' => 'B', 'purpose' => 'C', 'policy' => 'https://example.com/a b'], 'disclosure.0.policy'],
            [['company' => '', 'data' => 'B', 'purpose' => 'C', 'policy' => 'https://example.com'], 'disclosure.0.company'],
            [['company' => 'A', 'data' => str_repeat('長', 301), 'purpose' => 'C', 'policy' => 'https://example.com'], 'disclosure.0.data'],
            [['company' => 'A', 'data' => 'B', 'policy' => 'https://example.com'], 'disclosure.0.purpose'],
            [['company' => "A\nB", 'data' => 'B', 'purpose' => 'C', 'policy' => 'https://example.com'], 'disclosure.0.company'],
        ] as [$entry, $path]) {
            $this->refused(['disclosure' => [$entry]], $path);
        }
    }

    /**
     * 登録簿は composer.json を読み、名前・プラグイン API が登録と違う・形が違うものを登録しない
     */
    public function test_the_registry_refuses_a_manifest_that_does_not_match(): void
    {
        $write = fn (array $manifest): string => tap($this->dir.'/composer.json', static fn (string $path) => File::put($path, (string) json_encode(['extra' => ['ec-sugar' => $manifest]])));
        $base = ['type' => 'plugin', 'name' => 'mine', 'plugin_api' => 1, 'core' => '^1.0'];

        foreach ([
            ['name' => 'other'] + $base,
            ['plugin_api' => 2] + $base,
            ['type' => 'theme'] + $base,
            $base + ['storefront' => ['scripts' => [['file' => '../secret.js', 'pages' => ['general']]], 'consent' => []]],
        ] as $manifest) {
            try {
                (new PluginRegistry)->register(new PluginDefinition('mine', '私の', '', 1, manifest: $write($manifest)));
                $this->fail('形の違うマニフェストで登録できた: '.json_encode($manifest));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // 無い・壊れた composer.json
        foreach ([$this->dir.'/missing.json', tap($this->dir.'/broken.json', static fn (string $p) => File::put($p, '{'))] as $path) {
            try {
                (new PluginRegistry)->register(new PluginDefinition('mine', '私の', '', 1, manifest: $path));
                $this->fail("読めない composer.json で登録できた: {$path}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $registry = new PluginRegistry;
        $registry->register(new PluginDefinition('mine', '私の', '', 1, manifest: $write($base + $this->manifest())));
        $this->assertSame('tag.js', $registry->get('mine')->app()->scripts[0]->file);
    }

    /**
     * マニフェストのキー: 新しいキー ec-sugar だけでも、旧名のころのキー sugar-ec だけでも同じに登録する。
     * 両方あれば新しい方だけを読み、古い方は混ぜない（新しい方が違えば、古い方が正しくても断る）
     */
    public function test_the_registry_reads_the_old_key_and_prefers_the_new_one(): void
    {
        $base = ['type' => 'plugin', 'name' => 'mine', 'plugin_api' => 1, 'core' => '^1.0'];
        $write = fn (array $extra): string => tap($this->dir.'/composer.json', static fn (string $path) => File::put($path, (string) json_encode(['extra' => $extra])));
        $category = function (array $extra) use ($write): AppCategory {
            $registry = new PluginRegistry;
            $registry->register(new PluginDefinition('mine', '私の', '', 1, manifest: $write($extra)));

            return $registry->get('mine')->app()->category;
        };

        $this->assertSame(AppCategory::Analytics, $category(['ec-sugar' => $base + ['category' => 'analytics']]));
        $this->assertSame(AppCategory::Analytics, $category(['sugar-ec' => $base + ['category' => 'analytics']]));
        $this->assertSame(AppCategory::Page, $category(['sugar-ec' => $base + ['category' => 'analytics'], 'ec-sugar' => $base + ['category' => 'page']]));

        // 新しいキーが違えば、古いキーが正しくても断る。誤りの文は読んだキーで言う
        try {
            $category(['sugar-ec' => $base, 'ec-sugar' => ['name' => 'other'] + $base]);
            $this->fail('新しいキーの違いを古いキーで通した');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('extra.ec-sugar の type・name・plugin_api', $e->getMessage());
        }

        try {
            $category(['sugar-ec' => $base + ['category' => 'payments']]);
            $this->fail('古いキーで知らない目的を通した');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('extra.sugar-ec.category', $e->getMessage());
        }
    }

    /**
     * App の目的。核が決めた言葉だけを 1 つ。知らない言葉は登録で断り、書かなければ「そのほか」
     */
    public function test_the_category_is_one_of_the_fixed_words_and_defaults_to_other(): void
    {
        $this->assertSame(AppCategory::Other, AppManifest::fromArray($this->manifest(), $this->dir)->category);
        $this->assertSame(AppCategory::Other, AppManifest::none()->category);

        foreach (AppCategory::cases() as $category) {
            $this->assertSame($category, AppManifest::fromArray(['category' => $category->value] + $this->manifest(), $this->dir)->category);
        }

        // 知らない言葉・大文字・前後の空白・空・並び・数・null は断る（打ち間違いを「そのほか」に黙って落とさない）
        foreach (['ads', 'Analytics', ' page', '', 'その他', ['page'], 1, null, true] as $bad) {
            $this->refused(['category' => $bad] + $this->manifest(), 'category');
        }

        // 区画を足しても、ほかの宣言を足しても目的は残る（区画を入れ直すときに落とさない）
        $this->assertSame(AppCategory::Page, AppManifest::fromArray(['category' => 'page'], $this->dir)->withSections([])->category);

        // 登録簿でも同じ: 知らない言葉の App は登録しない
        $base = ['type' => 'plugin', 'name' => 'mine', 'plugin_api' => 1, 'core' => '^1.0'];
        File::put($this->dir.'/composer.json', (string) json_encode(['extra' => ['ec-sugar' => $base + ['category' => 'payments']]]));

        try {
            (new PluginRegistry)->register(new PluginDefinition('mine', '私の', '', 1, manifest: $this->dir.'/composer.json'));
            $this->fail('知らない目的で登録できた');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('category', $e->getMessage());
        }
    }

    /**
     * 今ある App は、どれも目的を書いている（タグの App は解析か集客と広告、区画の App とよくある質問はページの部品、商品フィードは集客と広告）
     */
    public function test_every_installed_app_declares_its_category(): void
    {
        $registry = app(PluginRegistry::class);
        $categories = [];

        foreach ($registry->all() as $plugin) {
            $categories[$plugin->name] = $plugin->app()->category->value;
        }

        // 公開用の木（1.0。ADR 0098）は定期便・予約・チケット・抽選販売の App が入っていないので、その分を外して比べる
        $this->assertSame(AppContract::withoutAbsent([
            'age-check' => 'selling',
            'blog' => 'marketing',
            'booking' => 'selling',
            'bundle' => 'selling',
            'cart-reminder' => 'marketing',
            'catalog-sort' => 'products',
            'clarity' => 'analytics',
            'contact' => 'customers',
            'downloads' => 'selling',
            'faq' => 'page',
            'favorites' => 'customers',
            'ga4' => 'analytics',
            'gift' => 'selling',
            'gift-cards' => 'selling',
            'google-ads' => 'marketing',
            'google-map' => 'page',
            'line' => 'customers',
            'lineyahoo-ads' => 'marketing',
            'lottery' => 'selling',
            'member-ranks' => 'customers',
            'meta-pixel' => 'marketing',
            'microsoft-uet' => 'marketing',
            'newsletter' => 'marketing',
            'order-print' => 'operations',
            'pinterest-tag' => 'marketing',
            'preorder' => 'selling',
            'product-csv' => 'products',
            'product-feed' => 'marketing',
            'purchase-limits' => 'selling',
            'recommendations' => 'page',
            'referral' => 'marketing',
            'restock' => 'products',
            'reviews' => 'customers',
            'sale-period' => 'selling',
            'services' => 'selling',
            'shipping-csv' => 'shipping',
            'shop-notify' => 'operations',
            'smartnews-ads' => 'marketing',
            'subscription' => 'selling',
            'tag-sample' => 'analytics',
            'tickets' => 'selling',
            'tiktok-pixel' => 'marketing',
            'video' => 'page',
            'wrapping' => 'selling',
            'x-pixel' => 'marketing',
        ]), $categories);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'settings' => [
                'tag_id' => ['type' => 'text', 'label' => '測定 ID', 'pattern' => 'G-[A-Z0-9]{4,12}', 'max' => 14, 'required' => true],
                'debug' => ['type' => 'toggle', 'label' => '確認する', 'default' => false],
                'region' => ['type' => 'choice', 'label' => '地域', 'options' => ['jp' => '日本', 'us' => '米国'], 'default' => 'jp'],
                'secret' => ['type' => 'text', 'label' => '鍵', 'pattern' => '[a-z]{8,}', 'max' => 40, 'secret' => true],
            ],
            'disclosure' => $this->disclosure(),
            'storefront' => [
                'scripts' => [['file' => 'tag.js', 'pages' => ['general', 'purchase', 'general']]],
                'csp' => ['script-src' => ['https://tags.example.com'], 'connect-src' => ['https://*.tags.example.com', 'https://example.com/collect'], 'frame-src' => []],
                'consent' => ['analytics', 'ads', 'analytics'],
            ],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function disclosure(): array
    {
        return [['company' => '見本の会社', 'data' => '見たページ', 'purpose' => '解析', 'policy' => 'https://example.com/privacy']];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function refused(array $manifest, string $path): void
    {
        try {
            AppManifest::fromArray($manifest, $this->dir);
            $this->fail("通ってしまった（{$path} で断るはず）: ".json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        } catch (InvalidAppManifestException $e) {
            $this->assertSame($path, $e->path, $e->getMessage());
        }
    }
}
