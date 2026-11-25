<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Catalog\Services\CategoryService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Section\FieldSpec;
use App\Core\Section\FieldType;
use App\Core\Section\PageSpec;
use App\Core\Section\SectionSchema;
use App\Core\Section\Services\ShopPageService;
use App\Core\Theme\Models\ThemeVersion;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 公式テーマ fashion・botanica の区画の並び（ADR 0039 区切り 4）。
 * 種類 × パターンの部品がどれも描け、どの区画にも押して選ぶ印（data-section）が 1 つだけある。自由なページはテーマの外枠と画面で描き、
 * メニューに載せる。今の店の値（決まった並びの頃のトップ）は、移行で新着の商品を足して今までと同じ順に描ける
 */
final class OfficialThemePatternsTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function themes(): array
    {
        return ['fashion' => ['fashion'], 'botanica' => ['botanica']];
    }

    #[DataProvider('themes')]
    public function test_every_pattern_of_every_section_draws_with_one_section_mark(string $theme): void
    {
        $tenant = "op{$theme[0]}";
        $this->tenant($tenant)->forceFill(['theme' => $theme])->save();
        $schema = app(ThemeRegistry::class)->get($theme)->schema();
        $home = $schema->page('home');
        $this->assertNotNull($home);
        $this->assertFalse($home->fixed);

        // 置ける種類 × パターンをすべて 1 つずつ、どの欄も埋めて並べる（お知らせの帯は外枠が読むので、ページの区画にはならない）
        $sections = $this->inside($tenant, function () use ($schema, $home, $theme): array {
            $fill = $this->filler($schema);
            $sections = [];

            foreach ($home->types as $type) {
                // 核の共通の区画（basic_*）は CoreSectionsTest が 4 テーマで確かめる（ここはテーマの区画）
                if (str_starts_with($type, 'basic_')) {
                    continue;
                }

                foreach (array_keys($schema->section($type)->patterns ?? []) as $pattern) {
                    $sections[] = ['type' => $type, 'pattern' => $pattern] + $fill($type);
                }
            }

            $this->publishPage($theme, $schema, 'home', $sections);

            return array_values(array_filter(app(ThemeContentService::class)->publishedPage($theme, $schema, 'home'), static fn (array $s): bool => $s['type'] !== 'announcement'));
        });

        $html = $this->html("http://{$tenant}.localhost/");
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        foreach ($sections as $section) {
            $marks = $document->querySelectorAll("[data-section=\"{$section['id']}\"]");
            $this->assertCount(1, $marks, "{$section['type']}/{$section['pattern']}");
            // 区画の中に別の区画の印を入れ子にしない
            $this->assertNull($marks->item(0)?->querySelector('[data-section]'), "{$section['type']}/{$section['pattern']}");
        }

        $this->assertStringContainsString('見本のお知らせ', $html);
        // 開いた時に浮かぶ見出しの塊は、ヒーローが 3 つあってもページに 1 か所だけ
        $this->assertCount(1, $document->querySelectorAll('[data-motion-intro]'));
        // メニューの #new は、最初の新着の区画にだけ
        $this->assertCount(1, $document->querySelectorAll('#new'));
        $this->assertSame('new-arrivals', $document->querySelector('#new')?->getAttribute('data-test'));
        foreach (['large', 'scroll'] as $pattern) {
            $this->assertNotNull($document->querySelector("[data-test=\"product-rail\"][data-pattern=\"{$pattern}\"] [data-test=\"product-card\"]"), $pattern);
        }
    }

    #[DataProvider('themes')]
    public function test_a_free_page_is_drawn_in_the_theme_and_listed_in_the_menu(string $theme): void
    {
        $tenant = "fp{$theme[0]}";
        $this->tenant($tenant)->forceFill(['theme' => $theme])->save();
        $schema = app(ThemeRegistry::class)->get($theme)->schema();

        $this->inside($tenant, function () use ($schema, $theme): void {
            $pages = app(ShopPageService::class);
            $page = $pages->create(['title' => '<b>私たち</b>について', 'slug' => 'about', 'in_nav' => true], 'test');
            // 雛形の最初の並びは核の「見出しと文」。テーマの区画（ヒーロー「左右」）と核の汎用の区画を並べる
            $this->assertSame(['basic_text'], array_column($schema->page(PageSpec::freeName($page->id))->default ?? [], 'type'));
            $this->publishPage($theme, $schema, PageSpec::freeName($page->id), [
                ['type' => 'hero', 'pattern' => $schema->section('hero')?->hasPattern('split') ? 'split' : 'panel', 'values' => ['heading' => 'ページのヒーロー']],
                ['type' => 'basic_text', 'values' => ['heading' => '見出し', 'body' => '本文です']],
            ]);
            $pages->publish($page->id, 'test');
        });

        $html = $this->html("http://{$tenant}.localhost/pages/about");
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $this->assertSame($theme, $document->body?->getAttribute('data-theme'));
        // 題はテーマの画面の h1（エスケープ）。ヒーローの見出しは h2 に下がる
        $title = $this->element($document, '[data-test="free-page-title"]');
        $this->assertSame('H1', $title->tagName);
        $this->assertStringContainsString('&lt;b&gt;私たち&lt;/b&gt;について', $html);
        $this->assertSame('H2', $this->element($document, '[data-test="hero-heading"]')->tagName);
        $this->assertCount(1, $document->querySelectorAll('h1'));
        $this->assertCount(2, $document->querySelectorAll('main [data-section]'));
        $this->assertStringContainsString('本文です', $html);
        // メニュー（ヘッダとフッター）に載り、今のページの印が付く
        $link = $this->element($document, '[data-test="nav-page"]');
        $this->assertSame('/pages/about', parse_url((string) $link->getAttribute('href'), PHP_URL_PATH));
        $this->assertSame('page', $link->getAttribute('aria-current'));
        $this->assertNotNull($document->querySelector('[data-test="footer-page"]'));

        // トップでもメニューに載り、今のページの印は付かない
        $home = HTMLDocument::createFromString($this->html("http://{$tenant}.localhost/"), LIBXML_NOERROR);
        $this->assertFalse($this->element($home, '[data-test="nav-page"]')->hasAttribute('aria-current'));
    }

    /**
     * 開発の demo（fashion）・midori（botanica）と同じ、決まった並びの頃に保存したトップ（ID は種類の名前・パターンは default・新着の商品は無い）。
     * 移行で新着の商品をカテゴリのすぐ後に足し、今までのトップと同じ順に描ける。下書きを変えたので改訂が進み、2 回流しても同じ
     */
    #[DataProvider('themes')]
    public function test_the_migration_keeps_the_top_of_shops_saved_in_the_fixed_order(string $theme): void
    {
        $tenant = "mg{$theme[0]}";
        $this->tenant($tenant)->forceFill(['theme' => $theme])->save();
        $order = $theme === 'fashion'
            ? ['announcement', 'hero', 'categories', 'looks', 'seasonal', 'favorites', 'promises']
            : ['announcement', 'hero', 'looks', 'categories', 'seasonal', 'favorites', 'promises'];
        $migration = require database_path('migrations/tenant/2026_10_30_000700_add_new_arrivals_to_fashion_and_botanica.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $this->assertTrue(method_exists($migration, 'up') && method_exists($migration, 'down'));

        $this->inside($tenant, function () use ($order, $theme): void {
            $this->listing('ワンピース');
            $section = static fn (string $type, array $values = []): array => ['id' => $type, 'type' => $type, 'pattern' => 'default', 'hidden' => false, 'values' => (object) $values, 'items' => [], 'blocks' => []];
            $home = array_map(static fn (string $type): array => $type === 'hero' ? $section($type, ['heading' => '見出し']) : $section($type), $order);
            DB::table('theme_versions')->insert([
                ['id' => strtolower((string) Str::ulid()), 'theme' => $theme, 'name' => '標準', 'current' => true, 'draft' => json_encode(['pages' => ['home' => $home]]),
                    'published' => json_encode(['pages' => ['home' => $home]]), 'published_at' => now(), 'revision' => 3, 'created_at' => now(), 'updated_at' => now()],
                // まだトップを保存していないバージョンは触らない（読むとテーマの最初の並び）
                ['id' => strtolower((string) Str::ulid()), 'theme' => $theme, 'name' => '別', 'current' => false, 'draft' => '{"pages": {}}',
                    'published' => null, 'published_at' => null, 'revision' => 0, 'created_at' => now(), 'updated_at' => now()],
            ]);
        });

        // 移行の前: 新着の商品が並びに無いので出ない（足さないと今の店のトップから消える）
        $this->assertStringNotContainsString('data-test="new-arrivals"', $this->html("http://{$tenant}.localhost/"));

        $this->inside($tenant, function () use ($migration): void {
            $migration->up();
            $migration->up();
        });

        $expected = array_merge(array_slice($order, 0, (int) array_search('categories', $order, true) + 1), ['new_arrivals'], array_slice($order, (int) array_search('categories', $order, true) + 1));
        $this->inside($tenant, function () use ($expected): void {
            $version = ThemeVersion::query()->where('current', true)->sole();
            $this->assertSame($expected, array_column($version->draft['pages']['home'], 'type'));
            $this->assertSame($version->draft['pages']['home'], $version->published['pages']['home'] ?? null);
            $this->assertSame(4, $version->revision);
            $this->assertSame(0, ThemeVersion::query()->where('current', false)->sole()->revision);
            // 値の空の対応は {} のまま（DB の中で書き換えた）
            $this->assertStringContainsString('"values": {}', (string) DB::table('theme_versions')->where('current', true)->value('draft'));
        });

        // 今までのトップと同じ順（ヒーロー → … → 新着の商品 → …）で、新着の商品が出る
        $html = $this->html("http://{$tenant}.localhost/");
        $this->assertStringContainsString('ワンピース', $html);
        $positions = array_map(static fn (string $test): int => (int) strpos($html, "data-test=\"{$test}\""), [$theme === 'fashion' ? 'fashion-hero' : 'botanica-hero', 'new-arrivals']);
        $this->assertGreaterThan(0, $positions[0]);
        $this->assertGreaterThan($positions[0], $positions[1]);

        // down は足した区画だけを外す
        $this->inside($tenant, function () use ($migration, $order): void {
            $migration->down();
            $this->assertSame($order, array_column(ThemeVersion::query()->where('current', true)->sole()->draft['pages']['home'], 'type'));
        });
    }

    /**
     * 宣言のどの欄も埋めた値を作る（写真はショップの写真、商品は新着、カテゴリは写真のあるもの）
     *
     * @return \Closure(string): array<string, mixed>
     */
    private function filler(SectionSchema $schema): \Closure
    {
        $media = app(ShopMediaService::class);
        $photo = $media->add($this->image(), '見本の写真', 'test')->id;
        $listing = $this->listing('見本の商品');
        $category = app(CategoryService::class)->create(['name' => '見本のカテゴリ', 'slug' => 'sample', 'parent_id' => null])->id;
        app(CategoryService::class)->assign($listing, [$category]);

        $value = static fn (FieldSpec $field): mixed => match ($field->type) {
            FieldType::Text, FieldType::Textarea => $field->key === 'text' ? '見本のお知らせ' : '見本',
            FieldType::Image => $photo,
            FieldType::Link => '/products',
            FieldType::Products => ['source' => 'new'],
            FieldType::Categories => [$category],
            FieldType::Choice => array_key_first($field->options),
            FieldType::Html, FieldType::Richtext => '<p>見本の<strong>文</strong></p>',
        };
        $row = static fn (array $fields): array => array_map($value, $fields);

        return static function (string $type) use ($schema, $row): array {
            $spec = $schema->section($type);
            assert($spec !== null);

            return [
                'values' => $row($spec->fields),
                'items' => $spec->hasItems() ? [$row($spec->itemFields), $row($spec->itemFields)] : [],
                'blocks' => array_values(array_map(static fn ($block): array => ['type' => $block->key, 'values' => $row($block->fields)], $spec->blocks)),
            ];
        };
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function publishPage(string $theme, SectionSchema $schema, string $page, array $sections): void
    {
        $content = app(ThemeContentService::class);
        $current = $content->current($theme);
        $version = $content->savePage($theme, $schema, $page, $current?->id, $current->revision ?? 0, $sections, 'test');
        $content->publish($theme, $schema, $version->id, $version->revision, 'test');
    }

    private function listing(string $title): Listing
    {
        $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 4900, 'status' => ListingStatus::Active]);
        app(ListingImageService::class)->add($listing, $this->image());

        return $listing;
    }

    private function html(string $url): string
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        tenancy()->end();

        return $html;
    }

    private function element(HTMLDocument|Element $root, string $selector): Element
    {
        $element = $root->querySelector($selector);
        $this->assertNotNull($element, $selector);

        return $element;
    }

    private function image(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'op');
        file_put_contents($path, $this->jpeg(800, 600));
        $this->files[] = $path;

        return $path;
    }
}
