<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Section\CoreSections;
use App\Core\Section\PageSpec;
use App\Core\Section\SectionIcons;
use App\Core\Section\Services\ShopPageService;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Access\ThemePreviewLink;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 核の区画「値段の表」とサービス向けの特長の絵。公式テーマ 4 つのトップと自由なページに置け、3 つのパターンがどれも描け、
 * 値段は書いた文字のまま・おすすめは選択肢の名前の札で 1 つだけ。値の確かめ（プランの数・文字の長さ・行き先の形・おすすめは 1 つまで・
 * 含むものの行の数・知らない絵）と、文のエスケープ。管理画面の欄は宣言から出る
 */
final class PricingSectionTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function themes(): array
    {
        return ['default' => ['default'], 'kinari' => ['kinari'], 'fashion' => ['fashion'], 'botanica' => ['botanica']];
    }

    /**
     * 公式テーマのトップと自由なページに、値段の表の 3 つのパターンとサービス向けの絵の特長を置いて公開すると、どれも描け（印は 1 つ）、
     * 値段は書いたまま・おすすめの札は 1 つのプランだけ・含むものは行ごとの箇条・ボタンは確かめた行き先
     */
    #[DataProvider('themes')]
    public function test_every_pattern_draws_on_the_top_and_a_free_page(string $theme): void
    {
        $tenant = "pr{$theme[0]}";
        $this->tenant($tenant)->forceFill(['theme' => $theme])->save();
        $schema = app(ThemeRegistry::class)->get($theme)->schema();

        foreach (['home' => $schema->page('home'), 'page' => $schema->freePage()] as $name => $page) {
            $this->assertNotNull($page);
            $this->assertContains(CoreSections::PRICING, $page->types, "{$theme} の {$name} に置ける");
        }

        $sections = [
            ...array_map(fn (string $pattern): array => ['type' => CoreSections::PRICING, 'pattern' => $pattern] + $this->pricing(), ['cards', 'featured', 'table']),
            ['type' => 'basic_features', 'pattern' => 'three', 'blocks' => array_map(
                static fn (string $icon): array => ['type' => 'feature', 'values' => ['icon' => $icon, 'title' => "{$icon} の見出し"]],
                ['wrench', 'message_circle', 'calendar_days', 'zap', 'lock', 'headset', 'sliders_horizontal', 'rocket', 'handshake', 'clipboard_check'],
            )],
        ];

        $ids = $this->inside($tenant, function () use ($schema, $theme, $sections): array {
            $content = app(ThemeContentService::class);
            $version = $content->savePage($theme, $schema, 'home', null, 0, $sections, 'test');
            $page = app(ShopPageService::class)->create(['title' => '料金', 'slug' => 'price', 'in_nav' => true], 'test');
            $version = $content->savePage($theme, $schema, PageSpec::freeName($page->id), $version->id, $version->revision, $sections, 'test');
            $content->publish($theme, $schema, $version->id, $version->revision, 'test');
            app(ShopPageService::class)->publish($page->id, 'test');

            return [
                'home' => array_column($content->publishedPage($theme, $schema, 'home'), 'pattern', 'id'),
                'page' => array_column($content->publishedPage($theme, $schema, PageSpec::freeName($page->id)), 'pattern', 'id'),
            ];
        });

        foreach (['home' => '/', 'page' => '/pages/price'] as $which => $path) {
            $html = $this->html("http://{$tenant}.localhost{$path}");
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

            $this->assertCount(4, $ids[$which]);
            foreach ($ids[$which] as $id => $pattern) {
                $marks = $document->querySelectorAll("main [data-section=\"{$id}\"]");
                $this->assertCount(1, $marks, "{$theme} {$which} {$id}");
                $this->assertSame($pattern, $marks->item(0)->getAttribute('data-pattern'), "{$theme} {$which} {$id}");
                $this->assertNull($marks->item(0)->querySelector('[data-section]'), "{$theme} {$which} {$id}: 印を入れ子にしない");
            }

            foreach (['cards', 'featured'] as $pattern) {
                $plans = $document->querySelectorAll(".sugar-pricing[data-pattern=\"{$pattern}\"] .sugar-pricing__plan");
                $this->assertSame(3, $plans->length, "{$theme} {$which} {$pattern}");
                [$first, $featured, $third] = [$plans->item(0), $plans->item(1), $plans->item(2)];
                $this->assertNotNull($first);
                $this->assertNotNull($featured);
                $this->assertNotNull($third);
                // おすすめは 2 つ目のプランだけ、札は選択肢の名前
                $this->assertNull($first->getAttribute('data-featured'));
                $this->assertSame('', $featured->getAttribute('data-featured'));
                $this->assertSame('いちばん人気', trim((string) $featured->querySelector('.sugar-pricing__badge')?->textContent));
                $this->assertSame(1, $document->querySelectorAll(".sugar-pricing[data-pattern=\"{$pattern}\"] .sugar-pricing__badge")->length);
                $this->assertSame('¥30,000〜', trim((string) $featured->querySelector('.sugar-pricing__price')?->textContent));
                $this->assertSame(['設置', '1 か月のサポート', '設定の説明'], array_map(
                    static fn ($li): string => trim((string) $li->textContent),
                    iterator_to_array($featured->querySelectorAll('.sugar-pricing__includes li')),
                ));
                $this->assertSame('/products/install', $featured->querySelector('a.sugar-pricing__button')?->getAttribute('href'));
                // ボタンの文字が無いプランはボタンを出さない
                $this->assertNull($third->querySelector('a.sugar-pricing__button'));
            }

            // 表: プランが列（左上は空のます）、値段・説明・含むもの・ボタンの段。おすすめの列に印
            $table = $document->querySelector('.sugar-pricing[data-pattern="table"] table');
            $this->assertNotNull($table);
            $this->assertSame(3, $table->querySelectorAll('thead th')->length);
            $this->assertSame(['値段', '説明', '含まれるもの'], array_map(static fn ($th): string => trim((string) $th->textContent), iterator_to_array($table->querySelectorAll('tbody th'))));
            $this->assertSame(5, $table->querySelectorAll('[data-featured]')->length);
            $this->assertSame(1, $table->querySelectorAll('.sugar-pricing__badge')->length);

            // サービス向けの絵は核の形で描く（10 個とも）
            foreach (['wrench', 'message_circle', 'calendar_days', 'zap', 'lock', 'headset', 'sliders_horizontal', 'rocket', 'handshake', 'clipboard_check'] as $icon) {
                $svg = $document->querySelector(".sugar-features svg[data-icon=\"{$icon}\"]");
                $this->assertNotNull($svg, "{$theme} {$which} {$icon}");
                $this->assertSame(count(SectionIcons::NODES[$icon]), $svg->children->length, $icon);
            }
            $this->assertStringContainsString('価格はすべて税込です', $html);
        }
    }

    /**
     * 店の人の値の確かめ: プランは 4 つまで・値段は 30 字まで・行き先は店の中の道筋か https だけ・おすすめの印は 1 つのプランだけで
     * 決まった選択肢から・含むものは 10 行まで・知らない欄と知らない絵は断る。断ったときは何も保存しない。
     * 文はどれもエスケープして出し、札の文字は店の人の文字にならない
     */
    public function test_the_values_are_checked_and_the_text_is_escaped(): void
    {
        $this->tenant('prv')->forceFill(['theme' => 'fashion'])->save();
        $schema = app(ThemeRegistry::class)->get('fashion')->schema();
        $plan = static fn (array $values): array => ['type' => 'plan', 'values' => $values + ['name' => 'プラン']];
        $pricing = static fn (array ...$plans): array => [['type' => CoreSections::PRICING, 'blocks' => $plans]];

        $refused = [
            ['sections.0.blocks', $pricing(...array_fill(0, 5, $plan([])))],
            ['sections.0.blocks.0.values.price', $pricing($plan(['price' => str_repeat('¥', 31)]))],
            ['sections.0.blocks.0.values.name', $pricing($plan(['name' => "改行\nあり"]))],
            ['sections.0.blocks.0.values.button_link', $pricing($plan(['button_label' => '申し込む', 'button_link' => 'javascript:alert(1)']))],
            ['sections.0.blocks.0.values.button_link', $pricing($plan(['button_link' => '//evil.example/']))],
            ['sections.0.blocks.0.values.button_link', $pricing($plan(['button_link' => 'https://shop@evil.example/']))],
            ['sections.0.blocks.0.values.button_link', $pricing($plan(['button_link' => 'http://example.com/']))],
            ['sections.0.blocks.0.values.button_link', $pricing($plan(['button_link' => 'https://example.com/"onmouseover=alert(1)']))],
            ['sections.0.blocks.2.values.featured', $pricing($plan([]), $plan(['featured' => 'best']), $plan(['featured' => 'recommended']))],
            ['sections.0.blocks.0.values.featured', $pricing($plan(['featured' => 'gold']))],
            ['sections.0.blocks.0.values.includes', $pricing($plan(['includes' => implode("\n", array_map(static fn (int $i): string => "行 {$i}", range(1, 11)))]))],
            ['sections.0.blocks.0.values.amount', $pricing($plan(['amount' => 30000]))],
            ['sections.0.blocks.0.values.icon', [['type' => 'basic_features', 'blocks' => [['type' => 'feature', 'values' => ['icon' => 'unicorn', 'title' => 'x']]]]]],
            ['sections.0.blocks.0.values.icon', [['type' => 'basic_features', 'blocks' => [['type' => 'feature', 'values' => ['icon' => '<svg onload=alert(1)>', 'title' => 'x']]]]]],
        ];

        $this->inside('prv', function () use ($schema, $refused, $plan, $pricing): void {
            $content = app(ThemeContentService::class);

            foreach ($refused as [$path, $sections]) {
                try {
                    $content->savePage('fashion', $schema, 'home', null, 0, $sections, 'test');
                    $this->fail("保存してしまった: {$path}");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey($path, $e->errors(), (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
                }
            }

            $this->assertNull($content->current('fashion'), '断ったときは何も保存しない');

            // 4 つまで・おすすめ 1 つ・含むものは空の行を数えずに 10 行は通る
            $version = $content->savePage('fashion', $schema, 'home', null, 0, [
                ...$pricing(
                    $plan(['name' => '<script>alert(1)</script>', 'price' => '"><img src=x onerror=alert(2)>', 'price_note' => '<b>税込</b>',
                        'description' => '<i>説明</i>', 'includes' => "<u>設置</u>\n\n<a href=\"javascript:alert(3)\">x</a>\n".implode("\n", array_fill(0, 8, '行')),
                        'button_label' => '<em>申し込む</em>', 'button_link' => 'https://example.com/apply?a=1&b=2', 'featured' => 'popular']),
                    $plan(['price' => '月 ¥980']),
                    $plan([]),
                    $plan([]),
                ),
            ], 'test');
            $content->publish('fashion', $schema, $version->id, $version->revision, 'test');
        });

        $html = $this->html('http://prv.localhost/');
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        foreach (['<b>', '<i>', '<u>', '<em>'] as $tag) {
            $this->assertStringNotContainsString($tag, $html);
        }
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;', $html);
        $this->assertStringContainsString('href="https://example.com/apply?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('月 ¥980', $html);

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $this->assertSame(4, $document->querySelectorAll('.sugar-pricing__plan')->length);
        $this->assertSame('人気', trim((string) $document->querySelector('.sugar-pricing__badge')?->textContent));
        // 空の行は箇条にしない
        $this->assertSame(10, $document->querySelector('.sugar-pricing__plan')?->querySelectorAll('.sugar-pricing__includes li')->length);
    }

    /**
     * 名前も値段も無いプランは出さず、1 つも無ければ区画ごと出さない。編集の画面のプレビューでは押して選べる知らせの箱を出す
     */
    public function test_an_empty_table_draws_nothing_on_the_shop_and_a_notice_in_the_preview(): void
    {
        $this->tenant('pre')->forceFill(['theme' => 'default'])->save();
        $schema = app(ThemeRegistry::class)->get('default')->schema();

        $id = $this->inside('pre', function () use ($schema): string {
            $content = app(ThemeContentService::class);
            $version = $content->savePage('default', $schema, 'home', null, 0, [
                ['type' => CoreSections::PRICING, 'values' => ['heading' => '料金の見出し'], 'blocks' => [['type' => 'plan', 'values' => ['description' => '説明だけ']]]],
            ], 'test');
            $content->publish('default', $schema, $version->id, $version->revision, 'test');

            return (string) $content->publishedPage('default', $schema, 'home')[0]['id'];
        });

        $html = $this->html('http://pre.localhost/');
        $this->assertStringNotContainsString("data-section=\"{$id}\"", $html);
        $this->assertStringNotContainsString('料金の見出し', $html);

        $link = ThemePreviewLink::issue(Tenant::query()->findOrFail('pre'), 'default', 'home');
        $this->assertNotNull($link);
        $preview = $this->html($link['url']);
        $this->assertSame(1, substr_count($preview, "data-section=\"{$id}\""));
        $this->assertStringContainsString('「値段の表」は、プランの名前か価格を入力するとここに表示されます', $preview);
    }

    /**
     * 管理画面のページの編集の画面は、宣言からそのまま欄を作る（区画・パターン・ワイヤーの絵・プランの欄・おすすめの選択肢・見本・
     * 特長の絵の選択肢にサービス向けの絵）。API から保存しても同じ決まりで断る（おすすめの印は 1 つまで）
     */
    public function test_the_admin_editor_builds_the_fields_from_the_declaration(): void
    {
        $this->tenant('pra')->forceFill(['theme' => 'kinari'])->save();
        $this->enrolledOperator();

        $page = $this->send('GET', '/api/tenants/pra/themes/kinari/pages/home')->assertOk()
            ->assertJsonPath('schema.basic_pricing.label', '値段の表')
            ->assertJsonPath('schema.basic_pricing.patterns', ['cards' => 'カードを横に', 'featured' => 'おすすめを大きく', 'table' => '表で比べる'])
            ->assertJsonPath('schema.basic_pricing.wireframes', ['cards' => 'pricing-cards', 'featured' => 'pricing-featured', 'table' => 'pricing-table'])
            ->assertJsonPath('schema.basic_pricing.max_blocks', 4)
            ->assertJsonPath('schema.basic_pricing.blocks.plan.fields.price.type', 'text')
            ->assertJsonPath('schema.basic_pricing.blocks.plan.fields.price.max', 30)
            ->assertJsonPath('schema.basic_pricing.blocks.plan.fields.button_link.type', 'link')
            ->assertJsonPath('schema.basic_pricing.blocks.plan.fields.featured.type', 'choice')
            ->assertJsonPath('schema.basic_pricing.blocks.plan.fields.featured.options', ['recommended' => 'おすすめ', 'popular' => '人気', 'best' => 'いちばん人気'])
            ->assertJsonPath('schema.basic_features.blocks.feature.fields.icon.options.wrench', '設置・作業（スパナ）')
            ->assertJsonPath('schema.basic_features.blocks.feature.fields.icon.options.headset', 'サポート（ヘッドセット）');
        $this->assertContains(CoreSections::PRICING, $page->json('page.types'));
        $this->assertCount(3, $page->json('schema.basic_pricing.example_blocks'));
        $this->assertSame(['plan', 'plan', 'plan'], array_column($page->json('schema.basic_pricing.example_blocks'), 'type'));
        $this->assertSame(SectionIcons::OPTIONS, $page->json('schema.basic_features.blocks.feature.fields.icon.options'));

        $this->send('PUT', '/api/tenants/pra/themes/kinari/pages/home/draft', ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => CoreSections::PRICING, 'blocks' => [
                ['type' => 'plan', 'values' => ['name' => 'A', 'featured' => 'recommended']],
                ['type' => 'plan', 'values' => ['name' => 'B', 'featured' => 'recommended']],
            ]],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['sections.0.blocks.1.values.featured']);

        // 見本の値のまま保存できる（足した区画にそのまま入る値）
        $this->send('PUT', '/api/tenants/pra/themes/kinari/pages/home/draft', ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => CoreSections::PRICING, 'pattern' => 'featured', 'values' => $page->json('schema.basic_pricing.example.values'), 'blocks' => $page->json('schema.basic_pricing.example_blocks')],
        ]])->assertOk();
    }

    /**
     * 3 つのプラン（2 つ目がおすすめ。3 つ目はボタンの文字が無い）
     *
     * @return array{values: array<string, mixed>, blocks: list<array{type: string, values: array<string, mixed>}>}
     */
    private function pricing(): array
    {
        return [
            'values' => ['heading' => '料金', 'body' => 'サービスの料金です', 'note' => '価格はすべて税込です'],
            'blocks' => [
                ['type' => 'plan', 'values' => ['name' => 'ライト', 'price' => '¥10,000', 'price_note' => '税込', 'description' => '小さく始める', 'includes' => "設置\n設定の説明",
                    'button_label' => '申し込む', 'button_link' => '/products/light']],
                ['type' => 'plan', 'values' => ['name' => 'おまかせ', 'price' => '¥30,000〜', 'price_note' => '税込', 'description' => 'すべてまかせる', 'includes' => "設置\n1 か月のサポート\n設定の説明",
                    'button_label' => '申し込む', 'button_link' => '/products/install', 'featured' => 'best']],
                ['type' => 'plan', 'values' => ['name' => 'ご相談', 'price' => 'お見積もり', 'description' => '内容に合わせて']],
            ],
        ];
    }

    private function html(string $url): string
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        tenancy()->end();

        return $html;
    }
}
