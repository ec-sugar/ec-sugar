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
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 公式テーマの角砂糖と kinari を区画の並びの形にした（ADR 0039 区切り 4）。
 * どの種類のどのパターンも描けて、区画の外枠に印（data-section）がある。フッターは外枠がパターンで描き分ける。
 * 自由なページはテーマの画面 page で描き、メニューに載る。今の店の値は移行で新しい並びに写り、トップは前と同じ順に描く
 */
final class OfficialThemePagesTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    /** 外枠（layout）が読む種類。ページの部品は持たない */
    private const array LAYOUT_TYPES = ['announcement', 'footer'];

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

    public function test_every_pattern_of_every_section_is_drawn_with_its_mark(): void
    {
        foreach (['default' => 'opa', 'kinari' => 'opb'] as $theme => $shop) {
            $this->tenant($shop)->forceFill(['theme' => $theme])->save();
            $ids = $this->inside($shop, function () use ($theme): array {
                $refs = $this->catalogWithPhotos();
                $schema = app(ThemeRegistry::class)->get($theme)->schema();
                $sections = [];

                foreach (ThemeContentService::pageSpec($schema, 'home')->types as $type) {
                    $spec = $schema->section($type);
                    $this->assertNotNull($spec);

                    // 外枠が描く種類と、核の共通の区画（basic_*。CoreSectionsTest が 4 テーマで確かめる）は除く
                    if (in_array($type, self::LAYOUT_TYPES, true) || str_starts_with($type, 'basic_')) {
                        continue;
                    }

                    foreach (array_keys($spec->patterns) as $pattern) {
                        $sections[] = ['type' => $type, 'pattern' => $pattern, 'values' => $this->filled($spec->fields, $refs),
                            'items' => $spec->hasItems() ? [$this->filled($spec->itemFields, $refs), $this->filled($spec->itemFields, $refs)] : []];
                    }
                }

                $placed = $this->publishHome($theme, $schema, $sections);

                return array_combine(array_column($placed, 'id'), array_map(static fn (array $s): string => "{$s['type']}/{$s['pattern']}", $placed));
            });

            $html = (string) $this->get("http://{$shop}.localhost/")->assertOk()->getContent();
            tenancy()->end();

            // 区画ごとに印が 1 つだけ（入れ子にしない）。並びの順に描く
            foreach ($ids as $id => $what) {
                $this->assertSame(1, substr_count($html, "data-section=\"{$id}\""), "{$theme}: {$what}");
            }
            $positions = array_map(static fn (string $id): int|false => strpos($html, "data-section=\"{$id}\""), array_keys($ids));
            $sorted = $positions;
            sort($sorted);
            $this->assertSame($sorted, $positions, $theme);
            // 店の人の文は文字として出す
            $this->assertStringNotContainsString('<b>見本', $html);
            $this->assertStringContainsString('&lt;b&gt;見本', $html);
        }
    }

    public function test_the_footer_is_drawn_in_each_pattern_and_falls_back_when_removed(): void
    {
        foreach (['default' => 'opc', 'kinari' => 'opd'] as $theme => $shop) {
            $this->tenant($shop)->forceFill(['theme' => $theme])->save();
            $schema = app(ThemeRegistry::class)->get($theme)->schema();
            $footer = $schema->section('footer');
            $this->assertNotNull($footer);

            foreach (array_keys($footer->patterns) as $pattern) {
                $values = $theme === 'kinari' ? ['message' => '<i>秋</i>の新作'] : ['powered' => 'hide'];
                $this->inside($shop, fn (): array => $this->publishHome($theme, $schema, [['type' => 'hero'], ['type' => 'footer', 'pattern' => $pattern, 'values' => $values]]));
                $html = (string) $this->get("http://{$shop}.localhost/")->assertOk()->getContent();
                tenancy()->end();

                // 外枠が描くので区画の印は付けない（どの画面にも出る）
                $this->assertMatchesRegularExpression("#<footer[^>]*\\sdata-test=\"footer\" data-pattern=\"{$pattern}\"#", $html, "{$theme}: {$pattern}");
                $this->assertStringNotContainsString('data-section', $this->between($html, '<footer', '</footer>'));
                // 外枠に App のリンクの口は 1 つ、特定商取引法の表記へのリンクはどの形にもある
                $this->assertStringContainsString('/legal/commerce', $this->between($html, '<footer', '</footer>'));
                if ($theme === 'kinari' && $pattern === 'cta') {
                    $this->assertStringContainsString('&lt;i&gt;秋&lt;/i&gt;の新作', $html);
                }
                if ($theme === 'default') {
                    $this->assertStringNotContainsString('data-test="powered-by"', $html);
                }
            }

            // フッターを外したトップでも、外枠は標準の形で描く
            $this->inside($shop, fn (): array => $this->publishHome($theme, $schema, [['type' => 'hero']]));
            $html = (string) $this->get("http://{$shop}.localhost/")->assertOk()->getContent();
            tenancy()->end();
            $this->assertMatchesRegularExpression('#<footer[^>]*\sdata-test="footer" data-pattern="default"#', $html);
        }
    }

    public function test_free_pages_are_drawn_by_the_theme_and_listed_in_its_menus(): void
    {
        foreach (['default' => 'ope', 'kinari' => 'opf'] as $theme => $shop) {
            $this->tenant($shop)->forceFill(['theme' => $theme])->save();
            $ids = $this->inside($shop, function () use ($theme): array {
                $pages = app(ShopPageService::class);
                $page = $pages->create(['title' => '私たちについて', 'slug' => 'about', 'in_nav' => true], 'test');
                $schema = app(ThemeRegistry::class)->get($theme)->schema();
                $content = app(ThemeContentService::class);
                $name = PageSpec::freeName($page->id);
                $version = $content->savePage($theme, $schema, $name, null, 0, [
                    ['type' => 'hero', 'pattern' => 'split', 'values' => ['heading' => 'ていねいに作っています']],
                    ['type' => 'basic_text', 'values' => ['heading' => 'お店のこと', 'body' => '小さなお店です。']],
                ], 'test');
                $content->publish($theme, $schema, $version->id, $version->revision, 'test');
                $pages->publish($page->id, 'test');

                return array_column($version->draft['pages'][$name], 'id');
            });

            $page = $this->get("http://{$shop}.localhost/pages/about")->assertOk();
            tenancy()->end();
            // テーマの画面 page（核の既定の画面ではない）。題と、テーマの区画と核の汎用の区画の両方
            $page->assertSee('data-test="free-page-title"', false)->assertSee('私たちについて')->assertDontSee('sugar-free-page__title', false)
                ->assertSee('ていねいに作っています')->assertSee('小さなお店です。');
            foreach ($ids as $id) {
                $page->assertSee("data-section=\"{$id}\"", false);
            }
            // 印はこのページの区画だけ（外枠のお知らせの帯・フッターなど、トップの区画は混ざらない）
            $this->assertSame(count($ids), substr_count((string) $page->getContent(), 'data-section="'));
            $page->assertSee('aria-current="page"', false);

            // メニュー（角砂糖はヘッダ、kinari はスマホのメニューとパソコンの案内）に載る
            $home = $this->get("http://{$shop}.localhost/")->assertOk();
            tenancy()->end();
            foreach ($theme === 'default' ? ['nav-page'] : ['menu-nav-page', 'nav-page'] as $mark) {
                $this->assertMatchesRegularExpression("#<a href=\"/pages/about\"[^>]*data-test=\"{$mark}\">私たちについて</a>#", (string) $home->getContent(), "{$theme}: {$mark}");
            }
        }
    }

    /**
     * 決まった並びのときにしまった値（宣言の順・新しい種類は無い）は、移行で新しい最初の並びの順になり、無かった種類（新着・フッター）が空で入る。
     * 下書きと公開に同じ写しをするので「公開していない変更」の印は変わらない。トップを保存していないバージョンと、ほかのテーマは触らない
     */
    public function test_the_migration_puts_current_values_into_the_new_order_and_the_top_looks_the_same(): void
    {
        $this->tenant('opg')->forceFill(['theme' => 'kinari'])->save();
        $migration = require database_path('migrations/tenant/2026_10_30_000800_default_and_kinari_themes_to_page_order.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $this->assertTrue(method_exists($migration, 'up'));

        $section = static fn (string $type, array $values = [], array $items = [], bool $hidden = false): array => ['id' => $type, 'type' => $type, 'pattern' => 'default', 'hidden' => $hidden, 'values' => (object) $values, 'items' => $items, 'blocks' => []];
        // 区切り 1 の移行が写した形（決まった並びの宣言の順）
        $kinari = ['pages' => ['home' => [
            $section('announcement', ['text' => '送料無料']), $section('hero', ['heading' => '秋の装い']), $section('categories'), $section('looks'),
            $section('seasonal', ['heading' => '冬支度']), $section('favorites'), $section('promises', [], [['icon' => 'returns', 'title' => '返品OK', 'body' => null]]),
        ]]];
        $default = ['pages' => ['home' => [$section('announcement'), $section('hero', ['heading' => '角砂糖の店'], [], true), $section('categories'), $section('promises'), $section('footer', ['powered' => 'hide'])]]];

        $rows = $this->inside('opg', function () use ($kinari, $default, $migration): array {
            $row = static fn (string $theme, array $draft, ?array $published, bool $current = true, string $name = '標準'): array => ['id' => strtolower((string) Str::ulid()), 'theme' => $theme, 'name' => $name, 'current' => $current,
                'draft' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'published' => $published === null ? null : json_encode($published, JSON_UNESCAPED_UNICODE), 'published_at' => $published === null ? null : now(), 'revision' => 3, 'created_at' => now(), 'updated_at' => now()];
            $rows = [
                'kinari' => $row('kinari', $kinari, $kinari),
                'default' => $row('default', $default, null),
                'unsaved' => $row('kinari', ['pages' => (object) []], null, false, '別'),
                'fashion' => $row('fashion', ['pages' => ['home' => [['id' => 'hero', 'type' => 'hero', 'pattern' => 'default', 'values' => (object) [], 'items' => [], 'blocks' => []]]]], null),
            ];
            DB::table('theme_versions')->insert(array_values($rows));
            $before = DB::table('theme_versions')->whereIn('id', [$rows['unsaved']['id'], $rows['fashion']['id']])->orderBy('id')->get(['draft'])->all();

            $migration->up();

            $this->assertEquals($before, DB::table('theme_versions')->whereIn('id', [$rows['unsaved']['id'], $rows['fashion']['id']])->orderBy('id')->get(['draft'])->all());

            return array_map(static fn (array $r): string => $r['id'], $rows);
        });

        [$draft, $published, $revision] = $this->inside('opg', fn (): array => [ThemeVersion::query()->findOrFail($rows['kinari'])->draft, ThemeVersion::query()->findOrFail($rows['kinari'])->published, ThemeVersion::query()->findOrFail($rows['kinari'])->revision]);
        $this->assertSame(['announcement', 'hero', 'categories', 'looks', 'new_arrivals', 'favorites', 'seasonal', 'promises', 'footer'], array_column($draft['pages']['home'], 'type'));
        $this->assertSame($draft, $published);
        $this->assertSame(3, $revision);
        $this->assertSame('冬支度', $draft['pages']['home'][6]['values']['heading']);
        $this->assertEquals(['id' => 'new_arrivals', 'type' => 'new_arrivals', 'pattern' => 'default', 'hidden' => false, 'values' => [], 'items' => [], 'blocks' => []], $draft['pages']['home'][4]);

        $defaultDraft = $this->inside('opg', fn (): array => ThemeVersion::query()->findOrFail($rows['default'])->draft);
        $this->assertSame(['announcement', 'hero', 'products', 'categories', 'promises', 'footer'], array_column($defaultDraft['pages']['home'], 'type'));
        $this->assertTrue($defaultDraft['pages']['home'][1]['hidden']);
        $this->assertSame('hide', $defaultDraft['pages']['home'][5]['values']['powered']);

        // 2 回目は何も変えない
        $this->inside('opg', function () use ($migration, $draft, $rows): void {
            $migration->up();
            $this->assertSame($draft, ThemeVersion::query()->findOrFail($rows['kinari'])->draft);
        });

        // 公開した値で、トップは前と同じ順（ヒーロー → カテゴリ → 新着 → よく選ばれている商品 → 季節の特集 → 約束）に描く
        $this->inside('opg', function (): void {
            $this->catalogWithPhotos();
        });
        $page = $this->get('http://opg.localhost/')->assertOk();
        tenancy()->end();
        $page->assertSeeInOrder(['data-test="announcement"', '送料無料', 'data-test="hero"', '秋の装い', 'data-test="new-arrivals"', 'data-test="product-card"', 'data-test="promises"', '返品OK', 'data-test="footer"'], false);
        // 季節の特集は写真が無いので出さない（前と同じ）
        $page->assertDontSee('data-test="seasonal"', false);
    }

    /**
     * 欄の宣言から、描ける値を作る（文は HTML の文字を含めて、エスケープを確かめる）
     *
     * @param  array<string, FieldSpec>  $fields
     * @param  array{image: string, category: string}  $refs
     * @return array<string, mixed>
     */
    private function filled(array $fields, array $refs): array
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field->key] = match ($field->type) {
                FieldType::Text, FieldType::Textarea => mb_substr('<b>見本</b>'.$field->label, 0, (int) ($field->max ?? 30)),
                FieldType::Image => $refs['image'],
                FieldType::Link => '/products',
                FieldType::Products => ['source' => 'new'],
                FieldType::Categories => [$refs['category']],
                FieldType::Choice => array_key_first($field->options),
                FieldType::Html, FieldType::Richtext => '<p>見本の<strong>文</strong></p>',
            };
        }

        return $values;
    }

    /**
     * 写真つきの商品 3 点と、商品のあるカテゴリ 1 つと、ショップの写真 1 枚（テナント文脈の中で呼ぶ）
     *
     * @return array{image: string, category: string}
     */
    private function catalogWithPhotos(): array
    {
        $category = app(CategoryService::class)->create(['name' => '器', 'slug' => 'utsuwa'])->id;

        foreach (['マグ', '豆皿', '一輪挿し'] as $title) {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 1200, 'status' => ListingStatus::Active]);
            app(ListingImageService::class)->add($listing, $this->file(400, 400));
            app(CategoryService::class)->assign($listing, [$category]);
        }

        return ['image' => app(ShopMediaService::class)->add($this->file(1600, 900), null, 'test')->id, 'category' => $category];
    }

    /**
     * トップの並びを置き換えて公開する（テナント文脈の中で呼ぶ）
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>> しまった並び（ID つき）
     */
    private function publishHome(string $theme, SectionSchema $schema, array $sections): array
    {
        $content = app(ThemeContentService::class);
        $current = $content->current($theme);
        $version = $content->savePage($theme, $schema, 'home', $current?->id, $current->revision ?? 0, $sections, 'test');
        $content->publish($theme, $schema, $version->id, $version->revision, 'test');

        return $version->draft['pages']['home'];
    }

    private function between(string $html, string $start, string $end): string
    {
        $rest = substr($html, (int) strpos($html, $start));

        return substr($rest, 0, (int) strpos($rest, $end));
    }

    private function file(int $width, int $height): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'otp');
        file_put_contents($path, $this->jpeg($width, $height));
        $this->files[] = $path;

        return $path;
    }
}
