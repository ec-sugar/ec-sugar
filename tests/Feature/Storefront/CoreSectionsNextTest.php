<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Media\Services\ShopMediaService;
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
 * 核の共通の区画の残り 5 種類: 数字で見せる・ロゴの帯・手順と流れ・比べる表・余白と区切り。
 * 公式テーマ 4 つのトップと自由なページに置け、どのパターンも描け（印は 1 つ）、見本の値だけでも落ちない。
 * ロゴの写真はこの店の写真だけ、リンクは店の中の道筋か https だけ、文はエスケープして出す。表は空の列と行を出さない
 */
final class CoreSectionsNextTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use OperatorSession;
    use WithTenants;

    private const array TYPES = ['basic_numbers', 'basic_logos', 'basic_steps', 'basic_table', 'basic_spacer'];

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
     * 公式テーマのトップと自由なページに 5 種類 × すべてのパターンを並べ、どれも描け、区画ごとに印が 1 つだけある
     */
    #[DataProvider('themes')]
    public function test_every_pattern_draws_on_the_top_and_a_free_page(string $theme): void
    {
        $tenant = "cn{$theme[0]}";
        $this->tenant($tenant)->forceFill(['theme' => $theme])->save();
        $schema = app(ThemeRegistry::class)->get($theme)->schema();

        foreach (['home', PageSpec::FREE] as $name) {
            $page = $name === 'home' ? $schema->page('home') : $schema->freePage();
            $this->assertNotNull($page);
            $this->assertSame([], array_values(array_diff(self::TYPES, $page->types)), "{$theme} の {$name} に置ける");
        }

        $ids = $this->inside($tenant, function () use ($schema, $theme): array {
            $fill = $this->filler();
            $sections = [];
            foreach (self::TYPES as $type) {
                foreach (array_keys($schema->section($type)->patterns ?? []) as $pattern) {
                    $sections[] = ['type' => $type, 'pattern' => $pattern] + $fill($schema, $type);
                }
            }

            $content = app(ThemeContentService::class);
            $version = $content->savePage($theme, $schema, 'home', null, 0, $sections, 'test');
            $page = app(ShopPageService::class)->create(['title' => '私たちについて', 'slug' => 'about', 'in_nav' => true], 'test');
            $version = $content->savePage($theme, $schema, PageSpec::freeName($page->id), $version->id, $version->revision, $sections, 'test');
            $content->publish($theme, $schema, $version->id, $version->revision, 'test');
            app(ShopPageService::class)->publish($page->id, 'test');

            return [
                'home' => array_column($content->publishedPage($theme, $schema, 'home'), 'pattern', 'id'),
                'page' => array_column($content->publishedPage($theme, $schema, PageSpec::freeName($page->id)), 'pattern', 'id'),
            ];
        });

        $this->assertCount(10, $ids['home']);
        foreach (['home' => '/', 'page' => '/pages/about'] as $which => $path) {
            $document = HTMLDocument::createFromString($this->html("http://{$tenant}.localhost{$path}"), LIBXML_NOERROR);

            foreach ($ids[$which] as $id => $pattern) {
                $marks = $document->querySelectorAll("main [data-section=\"{$id}\"]");
                $this->assertCount(1, $marks, "{$theme} {$which} {$id}");
                $this->assertSame($pattern, $marks->item(0)->getAttribute('data-pattern'), "{$theme} {$which} {$id}");
                $this->assertNull($marks->item(0)->querySelector('[data-section]'), "{$theme} {$which} {$id}: 印を入れ子にしない");
            }

            // 数字・ロゴ（ショップの写真と、確かめた行き先）・手順の番号・表・区切りの線
            $this->assertSame(4, $document->querySelectorAll('.sugar-numbers[data-pattern="row"] .sugar-numbers__value')->length);
            $this->assertNotNull($document->querySelector('.sugar-numbers[data-pattern="photo"] .sugar-numbers__media img'));
            $logo = $document->querySelector('.sugar-logos[data-pattern="grid"] a.sugar-logos__logo');
            $this->assertNotNull($logo);
            $this->assertSame('/products', $logo->getAttribute('href'));
            $image = $logo->querySelector('img');
            $this->assertNotNull($image);
            $this->assertStringContainsString('/media/shop/', (string) $image->getAttribute('src'));
            $this->assertSame('見本の文', $image->getAttribute('alt'));
            $this->assertSame(['1', '2', '3', '4'], array_map(static fn ($n): string => trim((string) $n->textContent), iterator_to_array($document->querySelectorAll('.sugar-steps[data-pattern="row"] .sugar-steps__number'))));
            $this->assertSame(6, $document->querySelectorAll('.sugar-table[data-pattern="table"] thead th')->length);
            $this->assertNotNull($document->querySelector('.sugar-table[data-pattern="photo"] .sugar-table__media img'));
            $this->assertNotNull($document->querySelector('.sugar-spacer[data-pattern="line"] hr'));
            $this->assertSame('s', $document->querySelector('.sugar-spacer[data-pattern="space"]')?->getAttribute('data-size'));
        }
    }

    /**
     * 足したときに入る見本の値（example・example_blocks）だけで描ける。ショップには見本の文が出て、写真を選ぶまでの枠は出さない。
     * 編集の画面の下書きのプレビューでは、写真を選ぶまでの枠と、余白の「余白」の文字（押して選べるように）を出す
     */
    public function test_the_example_values_alone_draw_and_the_preview_marks_the_spacer(): void
    {
        $this->tenant('cnx')->forceFill(['theme' => 'default'])->save();
        $schema = app(ThemeRegistry::class)->get('default')->schema();

        $ids = $this->inside('cnx', function () use ($schema): array {
            $sections = [];
            foreach (self::TYPES as $type) {
                $spec = $schema->section($type);
                $this->assertNotNull($spec);
                foreach (array_keys($spec->patterns) as $pattern) {
                    $sections[] = ['type' => $type, 'pattern' => $pattern, 'values' => $spec->example['values'] ?? [], 'items' => $spec->example['items'] ?? [], 'blocks' => $spec->exampleBlocks];
                }
            }

            $content = app(ThemeContentService::class);
            $version = $content->savePage('default', $schema, 'home', null, 0, $sections, 'test');
            $content->publish('default', $schema, $version->id, $version->revision, 'test');

            return array_column($content->publishedPage('default', $schema, 'home'), 'type', 'id');
        });

        $html = $this->html('http://cnx.localhost/');
        foreach (['数字で見るお店', 'ロゴ 1', '1 つ目の手順', '項目 1', 'ここに単位や測り方を書きます'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('sugar-section__placeholder', $html);
        $this->assertStringNotContainsString('sugar-spacer__label', $html);
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        // 表: 見出しの段（左上・S・M・L）と 3 行。見出しも中身も空の 4・5 列目は出さない
        $this->assertSame(['サイズ', 'S', 'M', 'L'], array_map(static fn ($th): string => trim((string) $th->textContent), iterator_to_array($document->querySelector('.sugar-table thead')?->querySelectorAll('th') ?? [])));
        $this->assertSame(3, $document->querySelector('.sugar-table tbody')?->querySelectorAll('tr')->length);

        $link = ThemePreviewLink::issue(Tenant::query()->findOrFail('cnx'), 'default', 'home');
        $this->assertNotNull($link);
        $preview = $this->html($link['url']);
        $this->assertStringContainsString('sugar-spacer__label', $preview);
        // 写真を選ぶまでの枠: 数字の「写真と数字」と、表の「写真とサイズ表」（ロゴは見本の名前を文字で出す）
        $this->assertSame(2, substr_count($preview, 'sugar-section__placeholder'));
        foreach (array_keys($ids) as $id) {
            $this->assertSame(1, substr_count($preview, "data-section=\"{$id}\""), (string) $id);
        }
    }

    /**
     * 店の人の値の確かめ: ロゴの行き先は店の中の道筋か https だけ、写真はこの店の写真だけ、大きさは決まった 3 つだけ。
     * 文はエスケープして出す（数字・表のます・手順に <script> を入れても動かない）。表は空の列と行を出さない
     */
    public function test_links_photos_and_choices_are_checked_and_text_is_escaped(): void
    {
        $this->tenant('cnl')->forceFill(['theme' => 'fashion'])->save();
        $this->tenant('cnm')->forceFill(['theme' => 'fashion'])->save();
        $schema = app(ThemeRegistry::class)->get('fashion')->schema();
        $other = $this->inside('cnm', fn (): string => app(ShopMediaService::class)->add($this->image(), 'ほかの店の写真', 'test')->id);

        $refused = [
            'sections.0.blocks.0.values.link' => [['type' => 'basic_logos', 'blocks' => [['type' => 'logo', 'values' => ['name' => 'x', 'link' => 'javascript:alert(1)']]]]],
            'sections.0.blocks.1.values.link' => [['type' => 'basic_logos', 'blocks' => [['type' => 'logo', 'values' => ['link' => '/products']], ['type' => 'logo', 'values' => ['link' => '//evil.example/']]]]],
            'sections.0.values.size' => [['type' => 'basic_spacer', 'values' => ['size' => 'xl']]],
            'sections.0.blocks.0.values.c1' => [['type' => 'basic_table', 'blocks' => [['type' => 'row', 'values' => ['c1' => str_repeat('あ', 61)]]]]],
            'sections' => [['type' => 'basic_logos', 'blocks' => [['type' => 'logo', 'values' => ['image' => $other]]]]],
        ];

        $this->inside('cnl', function () use ($schema, $refused): void {
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

            $version = $content->savePage('fashion', $schema, 'home', null, 0, [
                ['type' => 'basic_numbers', 'blocks' => [['type' => 'stat', 'values' => ['value' => '<s>9</s>', 'unit' => '<b>年</b>', 'label' => '"><img src=x onerror=alert(2)>']]]],
                ['type' => 'basic_steps', 'blocks' => [['type' => 'step', 'values' => ['title' => '<i>選ぶ</i>', 'text' => '<a href="javascript:alert(3)">x</a>']]]],
                ['type' => 'basic_table', 'values' => ['corner' => '<b>表</b>', 'col1' => 'A', 'col2' => null, 'col3' => null], 'blocks' => [
                    ['type' => 'row', 'values' => ['label' => '<script>alert(4)</script>', 'c1' => '1', 'c3' => '<u>3</u>']],
                    ['type' => 'row', 'values' => []],
                ]],
                ['type' => 'basic_logos', 'blocks' => [['type' => 'logo', 'values' => ['name' => '<script>alert(5)</script>', 'link' => 'https://example.com/shop?a=1']]]],
            ], 'test');
            $content->publish('fashion', $schema, $version->id, $version->revision, 'test');
        });

        $html = $this->html('http://cnl.localhost/');
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<u>', $html);
        $this->assertStringNotContainsString('<s>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('&lt;s&gt;9&lt;/s&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(4)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;i&gt;選ぶ&lt;/i&gt;', $html);
        $this->assertStringContainsString('href="https://example.com/shop?a=1"', $html);

        // 表: 1 列目（見出しあり）と 3 列目（中身あり）だけ。2・4・5 列目と空の行は出さない
        $table = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('.sugar-table table');
        $this->assertNotNull($table);
        $this->assertSame(3, $table->querySelectorAll('thead th')->length);
        $this->assertSame(1, $table->querySelectorAll('tbody tr')->length);
        $this->assertSame(['1', '<u>3</u>'], array_map(static fn ($td): string => (string) $td->textContent, iterator_to_array($table->querySelectorAll('tbody td'))));
    }

    /**
     * どの欄も埋めた値（写真はショップの写真、行き先は /products、選択肢は最初、表は 5 列とも）
     *
     * @return \Closure(SectionSchema, string): array<string, mixed>
     */
    private function filler(): \Closure
    {
        $photo = app(ShopMediaService::class)->add($this->image(), '見本の写真', 'test')->id;
        $value = static fn (FieldSpec $field): mixed => match ($field->type) {
            FieldType::Text, FieldType::Textarea => $field->max !== null && $field->max < 20 ? '見本' : '見本の文',
            FieldType::Image => $photo,
            FieldType::Link => '/products',
            FieldType::Products => ['source' => 'new'],
            FieldType::Categories => [],
            FieldType::Choice => array_key_first($field->options),
            FieldType::Html, FieldType::Richtext => '<p>見本</p>',
        };
        $row = static fn (array $fields): array => array_map($value, $fields);

        return static function (SectionSchema $schema, string $type) use ($row): array {
            $spec = $schema->section($type);
            assert($spec !== null);
            $blocks = [];
            foreach ($spec->blocks as $block) {
                $blocks = [...$blocks, ...array_fill(0, 4, ['type' => $block->key, 'values' => $row($block->fields)])];
            }

            return ['values' => $row($spec->fields), 'blocks' => $blocks];
        };
    }

    private function html(string $url): string
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        tenancy()->end();

        return $html;
    }

    private function image(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cn');
        file_put_contents($path, $this->jpeg(800, 600));
        $this->files[] = $path;

        return $path;
    }
}
