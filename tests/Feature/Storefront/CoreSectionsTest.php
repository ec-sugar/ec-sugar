<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\AddonService;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\VariantService;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Section\CoreSections;
use App\Core\Section\FieldSpec;
use App\Core\Section\FieldType;
use App\Core\Section\PageSpec;
use App\Core\Section\SectionSchema;
use App\Core\Section\Services\ShopPageService;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Access\ThemePreviewLink;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 核の共通の区画: お客さんの声・よくある質問・特長・呼びかけの帯・ギャラリー・1 つの商品を大きく・入口を並べる・作り手と物語。
 * 公式テーマ 4 つのトップと自由なページに置け、どのパターンも描け（印は 1 つ）、見本の値だけでも落ちない。
 * 商品の区画は公開中の商品だけを出し、公開をやめた・消した商品では何も出さない。リンクは店の中の道筋か https だけ、文はエスケープして出す
 */
final class CoreSectionsTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use OperatorSession;
    use WithTenants;

    /** 核の共通の区画（で足した 8 種類） */
    private const array TYPES = ['basic_testimonials', 'basic_faq', 'basic_features', 'basic_cta', 'basic_gallery', 'basic_product', 'basic_entries', 'basic_story'];

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
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
        return ['default' => ['default'], 'kinari' => ['kinari'], 'fashion' => ['fashion'], 'botanica' => ['botanica']];
    }

    /**
     * 公式テーマのトップと自由なページに 8 種類 × すべてのパターンを並べ、どれも描け、区画ごとに印が 1 つだけある。
     * 1 つの商品を大きくは 1 つのページに 1 つまでなので、トップに「写真が左」、自由なページに「写真が全面」
     */
    #[DataProvider('themes')]
    public function test_every_pattern_of_every_core_section_draws_on_the_top_and_a_free_page(string $theme): void
    {
        $tenant = "cs{$theme[0]}";
        $this->tenant($tenant)->forceFill(['theme' => $theme])->save();
        $schema = app(ThemeRegistry::class)->get($theme)->schema();

        foreach (['home', PageSpec::FREE] as $name) {
            $page = $name === 'home' ? $schema->page('home') : $schema->freePage();
            $this->assertNotNull($page);
            $this->assertSame([], array_values(array_diff(self::TYPES, $page->types)), "{$theme} の {$name} に置ける");
        }

        [$listing, $ids] = $this->inside($tenant, function () use ($schema, $theme): array {
            $listing = $this->listing('帆布のトートバッグ');
            $fill = $this->filler($listing->id);
            $home = [];
            foreach (self::TYPES as $type) {
                foreach (array_keys($schema->section($type)->patterns ?? []) as $pattern) {
                    if ($type !== CoreSections::PRODUCT || $pattern === 'split') {
                        $home[] = ['type' => $type, 'pattern' => $pattern] + $fill($schema, $type);
                    }
                }
            }

            $content = app(ThemeContentService::class);
            $version = $content->savePage($theme, $schema, 'home', null, 0, $home, 'test');
            $page = app(ShopPageService::class)->create(['title' => '私たちについて', 'slug' => 'about', 'in_nav' => true], 'test');
            $version = $content->savePage($theme, $schema, PageSpec::freeName($page->id), $version->id, $version->revision, [
                ['type' => CoreSections::PRODUCT, 'pattern' => 'full'] + $fill($schema, CoreSections::PRODUCT),
                ['type' => 'basic_faq', 'pattern' => 'side'] + $fill($schema, 'basic_faq'),
            ], 'test');
            $content->publish($theme, $schema, $version->id, $version->revision, 'test');
            app(ShopPageService::class)->publish($page->id, 'test');

            return [$listing, [
                'home' => array_column($content->publishedPage($theme, $schema, 'home'), 'pattern', 'id'),
                'page' => array_column($content->publishedPage($theme, $schema, PageSpec::freeName($page->id)), 'pattern', 'id'),
            ]];
        });

        $this->assertCount(18, $ids['home']);
        foreach (['home' => '/', 'page' => '/pages/about'] as $which => $path) {
            $html = $this->html("http://{$tenant}.localhost{$path}");
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

            foreach ($ids[$which] as $id => $pattern) {
                $marks = $document->querySelectorAll("main [data-section=\"{$id}\"]");
                $this->assertCount(1, $marks, "{$theme} {$which} {$id}");
                $this->assertSame($pattern, $marks->item(0)->getAttribute('data-pattern'), "{$theme} {$which} {$id}");
                $this->assertNull($marks->item(0)->querySelector('[data-section]'), "{$theme} {$which} {$id}: 印を入れ子にしない");
            }

            // 「カートに入れる」は核のフォーム（ページに 1 つだけ）で、選んだ商品を送る
            $forms = $document->querySelectorAll('.sugar-product form[data-cart-form]');
            $this->assertCount(1, $forms, "{$theme} {$which}");
            $this->assertSame($listing->id, $forms->item(0)?->querySelector('input[name="listing_id"]')?->getAttribute('value'));
            $this->assertStringContainsString('帆布のトートバッグ', $html);
            // 編集の画面の外では「入れるとここに出ます」の知らせを出さない
            $this->assertStringNotContainsString('sugar-section__empty', $html);
        }

        $home = HTMLDocument::createFromString($this->html("http://{$tenant}.localhost/"), LIBXML_NOERROR);
        // よくある質問は <details> で開く（JavaScript が無くても）。特長の絵は核の SVG、ギャラリーと入口はショップの写真
        $this->assertGreaterThanOrEqual(4, $home->querySelectorAll('.sugar-faq details.sugar-faq__item summary')->length);
        $this->assertNotNull($home->querySelector('.sugar-features svg[data-icon="truck"] path'));
        $this->assertGreaterThanOrEqual(6, $home->querySelectorAll('.sugar-gallery img')->length);
        $this->assertSame('/products', $home->querySelector('.sugar-entries a.sugar-entries__card')?->getAttribute('href'));
        $this->assertNotNull($home->querySelector('.sugar-story[data-pattern="timeline"] ol.sugar-story__timeline li h3'));
        $this->assertNotNull($home->querySelector('.sugar-cta[data-pattern="photo"] .sugar-cta__panel[data-photo] img'));
        $this->assertNotNull($home->querySelector('.sugar-voices[data-pattern="scroll"] .sugar-voices__item'));
    }

    /**
     * 足したときに入る見本の値（example・example_blocks）だけで描ける。ショップには文の見本が出て、写真の要る所（ギャラリー）と
     * 選ぶまで出ない所（1 つの商品）は何も出さない。編集の画面の下書きのプレビューでは、写真を選ぶまでの枠と「選ぶとここに出ます」を出す
     */
    public function test_the_example_values_alone_draw_and_the_preview_shows_what_is_still_missing(): void
    {
        $this->tenant('csx')->forceFill(['theme' => 'default'])->save();
        $schema = app(ThemeRegistry::class)->get('default')->schema();

        $ids = $this->inside('csx', function () use ($schema): array {
            $sections = [];
            foreach (self::TYPES as $type) {
                $spec = $schema->section($type);
                $this->assertNotNull($spec);
                foreach (array_keys($spec->patterns) as $pattern) {
                    if ($type !== CoreSections::PRODUCT || $pattern === 'split') {
                        $sections[] = ['type' => $type, 'pattern' => $pattern, 'values' => $spec->example['values'] ?? [], 'items' => $spec->example['items'] ?? [], 'blocks' => $spec->exampleBlocks];
                    }
                }
            }

            $content = app(ThemeContentService::class);
            $version = $content->savePage('default', $schema, 'home', null, 0, $sections, 'test');
            $content->publish('default', $schema, $version->id, $version->revision, 'test');

            return array_column($content->publishedPage('default', $schema, 'home'), 'type', 'id');
        });

        $html = $this->html('http://csx.localhost/');
        foreach (['ここにお客さんの声が入ります', '送料はいくらですか？', '選ばれる理由', '呼びかけの見出しを入力してください', '自分用', '出来事を入力してください', '私たちについて'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('sugar-gallery', $html);
        $this->assertStringNotContainsString('sugar-product', $html);
        $this->assertStringNotContainsString('sugar-section__placeholder', $html);

        // 下書きのプレビュー: 写真を選ぶまでの枠と、商品を選ぶまでの知らせ。どの区画にも押して選ぶ印がある
        $link = ThemePreviewLink::issue(Tenant::query()->findOrFail('csx'), 'default', 'home');
        $this->assertNotNull($link);
        $preview = $this->html($link['url']);
        $this->assertStringContainsString('「1 つの商品を大きく」は、公開中の商品を選ぶとここに表示されます', $preview);
        $this->assertGreaterThanOrEqual(6, substr_count($preview, 'sugar-section__placeholder'));
        foreach (array_keys($ids) as $id) {
            $this->assertSame(1, substr_count($preview, "data-section=\"{$id}\""), (string) $id);
        }
    }

    /**
     * 1 つの商品を大きく: 種類（色・サイズ）とオプションは商品の画面と同じ核の部品。公開中の商品だけを出し、
     * 下書きに戻した・消した・ほかの店の商品の ID・選んでいない、のどれも何も出さず、ページは落ちない
     */
    public function test_the_product_section_shows_only_a_published_product_and_survives_its_removal(): void
    {
        $this->tenant('csp')->forceFill(['theme' => 'kinari'])->save();
        $this->tenant('csq')->forceFill(['theme' => 'kinari'])->save();
        $schema = app(ThemeRegistry::class)->get('kinari')->schema();
        $other = $this->inside('csq', fn (): string => $this->listing('ほかの店の商品')->id);

        // 選んだ商品の ID の並び（null は「選んでいない」＝欄が空）
        $place = function (?array $ids) use ($schema): void {
            $this->inside('csp', function () use ($schema, $ids): void {
                $content = app(ThemeContentService::class);
                $current = $content->current('kinari');
                $version = $content->savePage('kinari', $schema, 'home', $current?->id, $current->revision ?? 0, [
                    ['type' => CoreSections::PRODUCT, 'pattern' => 'split', 'values' => ['product' => $ids === null ? null : ['source' => 'pick', 'ids' => $ids], 'kicker' => '今月のおすすめ']],
                ], 'test');
                $content->publish('kinari', $schema, $version->id, $version->revision, 'test');
            });
        };

        $apron = $this->inside('csp', function (): Listing {
            $listing = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => 'エプロン', 'unit_amount' => 4800, 'status' => ListingStatus::Active]);
            $listing = app(VariantService::class)->define($listing, $listing->version, [['name' => '色', 'values' => ['墨', 'からし']]]);
            foreach ($listing->variants()->get() as $variant) {
                app(StockService::class)->receive($variant, Location::query()->firstOrFail(), 5);
            }

            return app(AddonService::class)->define($listing, Listing::query()->findOrFail($listing->id)->version, [['kind' => 'toggle', 'name' => 'ギフト包装', 'amount' => 330]]);
        });

        $place([$apron->id]);
        $document = HTMLDocument::createFromString($this->html('http://csp.localhost/'), LIBXML_NOERROR);
        $section = $document->querySelector('.sugar-product');
        $this->assertNotNull($section);
        $this->assertStringContainsString('エプロン', (string) $section->textContent);
        $this->assertStringContainsString('今月のおすすめ', (string) $section->textContent);
        $this->assertStringContainsString('¥4,800', (string) $section->querySelector('[data-variant-price]')?->textContent);
        $this->assertNotNull($section->querySelector('form[data-cart-form] [data-variant-picker] select[name="variant_id"]'));
        $this->assertNotNull($section->querySelector('form[data-cart-form] [data-addon-fields] input[type="checkbox"]'));
        $this->assertNotNull($section->querySelector('img[data-variant-photo], .sugar-product__photo'));

        // 下書きに戻すと出さない（ページは落ちない）。公開に戻すとまた出る
        $this->inside('csp', static function () use ($apron): void {
            $listing = Listing::query()->findOrFail($apron->id);
            app(ListingService::class)->update($listing, $listing->version, ['status' => ListingStatus::Draft]);
        });
        $this->assertStringNotContainsString('sugar-product', $this->html('http://csp.localhost/'));
        $this->inside('csp', static function () use ($apron): void {
            $listing = Listing::query()->findOrFail($apron->id);
            app(ListingService::class)->update($listing, $listing->version, ['status' => ListingStatus::Active]);
        });
        $this->assertStringContainsString('sugar-product', $this->html('http://csp.localhost/'));

        // 消した商品・ほかの店の商品の ID・選んでいない、はどれも何も出さない
        $this->inside('csp', static function () use ($apron): void {
            $listing = Listing::query()->findOrFail($apron->id);
            app(ListingService::class)->delete($listing, $listing->version);
        });
        $this->assertStringNotContainsString('sugar-product', $this->html('http://csp.localhost/'));
        $place([$other]);
        $html = $this->html('http://csp.localhost/');
        $this->assertStringNotContainsString('sugar-product', $html);
        $this->assertStringNotContainsString('ほかの店の商品', $html);
        $place(null);
        $this->assertStringNotContainsString('sugar-product', $this->html('http://csp.localhost/'));
    }

    /**
     * 店の人の値の確かめ: リンクは店の中の道筋か https だけ（javascript:・data:・// で始まる別のホストを断る）。絵は核の選択肢だけ。
     * 1 つの商品を大きくは 1 つのページに 1 つまで。文はエスケープして出す（<script> を入れても動かない）
     */
    public function test_links_icons_and_the_limit_are_checked_and_text_is_escaped(): void
    {
        $this->tenant('csl')->forceFill(['theme' => 'fashion'])->save();
        $schema = app(ThemeRegistry::class)->get('fashion')->schema();

        $refused = [
            'sections.0.values.button_link' => [['type' => 'basic_cta', 'values' => ['heading' => 'x', 'button_label' => '見る', 'button_link' => 'javascript:alert(1)']]],
            'sections.0.blocks.0.values.link' => [['type' => 'basic_entries', 'blocks' => [['type' => 'entry', 'values' => ['title' => 'x', 'link' => '//evil.example/x']]]]],
            'sections.1.values.button_link' => [['type' => 'basic_text'], ['type' => 'basic_faq', 'values' => ['button_link' => 'data:text/html,<script>alert(1)</script>']]],
            'sections.0.blocks.1.values.link' => [['type' => 'basic_entries', 'blocks' => [['type' => 'entry', 'values' => ['link' => '/products']], ['type' => 'entry', 'values' => ['link' => 'http://example.com/']]]]],
            'sections.0.blocks.0.values.icon' => [['type' => 'basic_features', 'blocks' => [['type' => 'feature', 'values' => ['icon' => 'skull', 'title' => 'x']]]]],
            'sections.1.type' => [['type' => CoreSections::PRODUCT], ['type' => CoreSections::PRODUCT]],
        ];

        $this->inside('csl', function () use ($schema, $refused): void {
            $content = app(ThemeContentService::class);

            foreach ($refused as $path => $sections) {
                try {
                    $content->savePage('fashion', $schema, 'home', null, 0, $sections, 'test');
                    $this->fail("保存してしまった: {$path}");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey($path, $e->errors(), (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
                }
            }

            $this->assertNull($content->current('fashion'), '断ったときは何も保存しない');

            // 店の中の道筋と https は通る。文はタグのまま保存し、出すときにエスケープする
            $version = $content->savePage('fashion', $schema, 'home', null, 0, [
                ['type' => 'basic_cta', 'values' => ['heading' => '<script>alert("cta")</script>', 'button_label' => '見る', 'button_link' => 'https://example.com/campaign?a=1']],
                ['type' => 'basic_testimonials', 'blocks' => [['type' => 'voice', 'values' => ['quote' => '<script>alert(1)</script>よかった', 'name' => '<img src=x onerror=alert(2)>', 'detail' => '"><b>x</b>']]]],
                ['type' => 'basic_faq', 'blocks' => [['type' => 'question', 'values' => ['question' => '<b>送料</b>は？', 'answer' => '<a href="javascript:alert(3)">答え</a>']]]],
                ['type' => 'basic_entries', 'blocks' => [['type' => 'entry', 'values' => ['title' => 'ギフト', 'link' => '/pages/gift']]]],
            ], 'test');
            $content->publish('fashion', $schema, $version->id, $version->revision, 'test');
        });

        $html = $this->html('http://csl.localhost/');
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;よかった', $html);
        $this->assertStringContainsString('&lt;b&gt;送料&lt;/b&gt;は？', $html);
        $this->assertStringContainsString('href="https://example.com/campaign?a=1"', $html);
        $this->assertStringContainsString('href="/pages/gift"', $html);
    }

    /**
     * 今の形（種類 => 値）で保存する「テーマの中身」の画面は、テーマの区画だけを送る。トップに置いた核の区画を、送らなかったからと外さない
     */
    public function test_saving_the_old_keyed_content_keeps_the_core_sections_on_the_top(): void
    {
        $this->tenant('csk')->forceFill(['theme' => 'default'])->save();
        $schema = app(ThemeRegistry::class)->get('default')->schema();

        $this->inside('csk', function () use ($schema): void {
            $content = app(ThemeContentService::class);
            $version = $content->savePage('default', $schema, 'home', null, 0, [
                ['type' => 'hero', 'values' => ['heading' => '前の見出し']],
                ['type' => 'basic_faq', 'blocks' => [['type' => 'question', 'values' => ['question' => '送料は？']]]],
                ['type' => 'products'],
            ], 'test');

            $version = $content->saveDraft('default', $schema, $version->id, $version->revision, ['hero' => ['values' => ['heading' => '新しい見出し']], 'products' => []], 'test');
            $home = $content->draftPage($version, $schema, 'home');
            $this->assertSame(['hero', 'basic_faq', 'products'], array_column($home, 'type'));
            $this->assertSame('新しい見出し', $home[0]['values']['heading']);
            $this->assertSame('送料は？', $home[1]['blocks'][0]['values']['question']);
        });
    }

    /**
     * どの欄も埋めた値（写真はショップの写真、商品は選んだ商品、絵はトラック）
     *
     * @return \Closure(SectionSchema, string): array<string, mixed>
     */
    private function filler(string $listingId): \Closure
    {
        $photo = app(ShopMediaService::class)->add($this->image(), '見本の写真', 'test')->id;
        $value = static fn (FieldSpec $field): mixed => match ($field->type) {
            FieldType::Text, FieldType::Textarea => '見本',
            FieldType::Image => $photo,
            FieldType::Link => '/products',
            FieldType::Products => in_array('new', $field->sources, true) ? ['source' => 'new'] : ['source' => 'pick', 'ids' => [$listingId]],
            FieldType::Categories => [],
            FieldType::Choice => array_key_first($field->options),
            FieldType::Html, FieldType::Richtext => '<p>見本の<strong>文</strong></p>',
        };
        $row = static fn (array $fields): array => array_map($value, $fields);

        return static function (SectionSchema $schema, string $type) use ($row): array {
            $spec = $schema->section($type);
            assert($spec !== null);
            $blocks = [];
            foreach ($spec->blocks as $block) {
                // ギャラリーは写真 6 枚（格子の 2 段）、ほかは 4 つずつ
                $blocks = [...$blocks, ...array_fill(0, $type === 'basic_gallery' ? 6 : 4, ['type' => $block->key, 'values' => $row($block->fields)])];
            }

            return ['values' => $row($spec->fields), 'blocks' => $blocks];
        };
    }

    private function listing(string $title): Listing
    {
        $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 4900, 'status' => ListingStatus::Active]);
        app(ListingImageService::class)->add($listing, $this->image());
        app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 3);

        return $listing;
    }

    private function html(string $url): string
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        tenancy()->end();

        return $html;
    }

    private function image(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cs');
        file_put_contents($path, $this->jpeg(800, 600));
        $this->files[] = $path;

        return $path;
    }
}
