<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Section\SectionSchema;
use App\Core\Section\SectionValues;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 区画の値を宣言どおりに確かめる（ADR 0028 区切り 2）。保存のときは違うところを道筋ごとに断り、読むときは崩れた値を捨てる
 */
final class SectionValuesTest extends TestCase
{
    private const string ULID_A = '01jbx0000000000000000000aa';

    private const string ULID_B = '01jbx0000000000000000000bb';

    private function schema(): SectionSchema
    {
        $manifest = json_decode((string) file_get_contents(base_path('tests/Support/Themes/sample/theme.json')), true);
        assert(is_array($manifest) && is_array($manifest['sections']));

        return SectionSchema::fromArray($manifest['sections']);
    }

    public function test_values_are_normalised_into_the_declared_shape(): void
    {
        $values = SectionValues::validate($this->schema(), [
            // 宣言と違う順に送っても、宣言の順にそろう
            'rail' => ['values' => ['title' => '  新着  ', 'products' => ['source' => 'pick', 'ids' => [strtoupper(self::ULID_A), self::ULID_B]]]],
            'hero' => ['values' => ['heading' => " 春の\u{3000}新作 ", 'body' => "一行目\r\n二行目\r三行目", 'image' => strtoupper(self::ULID_A), 'link' => '/products?sort=new']],
            'features' => ['hidden' => true, 'items' => [['title' => '鉢', 'tone' => 'dark'], ['title' => '土']]],
            'shelves' => ['values' => ['list' => []]],
        ]);

        $this->assertSame(['hero', 'features', 'rail', 'shelves'], array_keys($values));
        $this->assertSame([
            'hidden' => false,
            'values' => ['heading' => "春の\u{3000}新作", 'body' => "一行目\n二行目\n三行目", 'image' => self::ULID_A, 'link' => '/products?sort=new'],
            'items' => [],
        ], $values['hero']);
        $this->assertTrue($values['features']['hidden']);
        // 宣言した欄はすべて入る（空は null）
        $this->assertSame([['title' => '鉢', 'image' => null, 'tone' => 'dark'], ['title' => '土', 'image' => null, 'tone' => null]], $values['features']['items']);
        $this->assertSame(['title' => '新着', 'products' => ['source' => 'pick', 'category' => null, 'ids' => [self::ULID_A, self::ULID_B]]], $values['rail']['values']);
        $this->assertSame(['list' => []], $values['shelves']['values']);
        // 空の文は null
        $this->assertSame(['text' => null], SectionValues::validate($this->schema(), ['announcement' => ['values' => ['text' => '   ']]])['announcement']['values']);

        $this->assertSame([self::ULID_A], SectionValues::imageIds($this->schema(), $values));
    }

    public function test_links_are_paths_inside_the_shop_or_plain_https(): void
    {
        foreach (['/', '/products', '/c/plants?page=2', 'https://example.com/a?b=c#d'] as $link) {
            $this->assertSame($link, SectionValues::validate($this->schema(), ['hero' => ['values' => ['link' => $link]]])['hero']['values']['link'], $link);
        }

        foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,<script>', 'http://example.com/', '//evil.example/', '/\\evil.example', 'https://shop.example@evil.example/',
            'https://user:pass@example.com/', 'products', 'https://', "/a\u{0000}b", "/a\nb", 'vbscript:x', '/<script>', 'https://example.com/'.str_repeat('a', 500),
            // 外の URL でも属性やタグを閉じられる文字は通さない（テーマのエスケープに頼り切らない）
            'https://example.com/"onmouseover=x', 'https://a.example#<script>', "https://example.com/'x", 'https://example.com/a b', 'https://example.com/`x`'] as $link) {
            try {
                SectionValues::validate($this->schema(), ['hero' => ['values' => ['link' => $link]]]);
                $this->fail("受け付けてしまった: {$link}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('sections.hero.values.link', $e->errors(), $link);
            }
        }
    }

    /**
     * @param  array<mixed>  $input
     */
    #[DataProvider('wrong')]
    public function test_wrong_values_are_refused_with_where_they_are_wrong(array $input, string $path): void
    {
        try {
            SectionValues::validate($this->schema(), $input);
            $this->fail('受け付けてしまった');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($path, $e->errors(), implode(',', array_keys($e->errors())));
        }
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function wrong(): iterable
    {
        yield 'セクションが並び' => [[['values' => []]], 'sections'];
        yield '宣言に無いセクション' => [['footer' => ['values' => []]], 'sections.footer'];
        yield 'セクションに知らない項目' => [['hero' => ['values' => [], 'style' => 'x']], 'sections.hero.style'];
        yield '隠すが文字' => [['hero' => ['hidden' => 'yes']], 'sections.hero.hidden'];
        yield '宣言に無い欄' => [['hero' => ['values' => ['subtitle' => 'x']]], 'sections.hero.values.subtitle'];
        yield '1 行に改行' => [['hero' => ['values' => ['heading' => "一\n二"]]], 'sections.hero.values.heading'];
        yield '1 行に C1 制御文字' => [['hero' => ['values' => ['heading' => "改\u{0085}行"]]], 'sections.hero.values.heading'];
        yield '複数行にタブ以外の制御文字' => [['hero' => ['values' => ['body' => "一\u{0007}二"]]], 'sections.hero.values.body'];
        yield '複数行に C1 制御文字' => [['hero' => ['values' => ['body' => "一\u{009F}二"]]], 'sections.hero.values.body'];
        yield '壊れた文字' => [['hero' => ['values' => ['heading' => "\xC3\x28"]]], 'sections.hero.values.heading'];
        yield '長すぎる' => [['hero' => ['values' => ['heading' => str_repeat('あ', 61)]]], 'sections.hero.values.heading'];
        yield '文が数' => [['hero' => ['values' => ['heading' => 12]]], 'sections.hero.values.heading'];
        yield '文が並び' => [['hero' => ['values' => ['heading' => ['x']]]], 'sections.hero.values.heading'];
        yield '写真が ID でない' => [['hero' => ['values' => ['image' => '../../etc/passwd']]], 'sections.hero.values.image'];
        yield '繰り返しの無いセクションに繰り返し' => [['hero' => ['items' => [['heading' => 'x']]]], 'sections.hero.items'];
        yield '繰り返しが多すぎる' => [['features' => ['items' => [['title' => 'a'], ['title' => 'b'], ['title' => 'c'], ['title' => 'd']]]], 'sections.features.items'];
        yield '繰り返しが並びでない' => [['features' => ['items' => ['a' => ['title' => 'a']]]], 'sections.features.items'];
        yield '繰り返しの中の知らない欄' => [['features' => ['items' => [['title' => 'a', 'href' => '/x']]]], 'sections.features.items.0.href'];
        yield '選択肢に無い値' => [['features' => ['items' => [['tone' => 'neon']]]], 'sections.features.items.0.tone'];
        yield '許していない取得元' => [['rail' => ['values' => ['products' => ['source' => 'category', 'category' => self::ULID_A]]]], 'sections.rail.values.products.source'];
        yield '選ぶのに商品が無い' => [['rail' => ['values' => ['products' => ['source' => 'pick', 'ids' => []]]]], 'sections.rail.values.products.ids'];
        yield '選んだ商品が重なる' => [['rail' => ['values' => ['products' => ['source' => 'pick', 'ids' => [self::ULID_A, self::ULID_A]]]]], 'sections.rail.values.products.ids'];
        yield '選んだ商品が多すぎる' => [['rail' => ['values' => ['products' => ['source' => 'pick', 'ids' => ['01jbx0000000000000000000a1', '01jbx0000000000000000000a2', '01jbx0000000000000000000a3', '01jbx0000000000000000000a4', '01jbx0000000000000000000a5']]]]], 'sections.rail.values.products.ids'];
        yield '商品の並びに知らない項目' => [['rail' => ['values' => ['products' => ['source' => 'new', 'sql' => 'x']]]], 'sections.rail.values.products'];
        yield 'カテゴリが多すぎる' => [['shelves' => ['values' => ['list' => ['01jbx0000000000000000000a1', '01jbx0000000000000000000a2', '01jbx0000000000000000000a3', '01jbx0000000000000000000a4', '01jbx0000000000000000000a5']]]], 'sections.shelves.values.list'];
        yield 'カテゴリが ID でない' => [['shelves' => ['values' => ['list' => ['plants']]]], 'sections.shelves.values.list'];
    }

    public function test_errors_stop_piling_up_after_a_limit(): void
    {
        $input = array_fill_keys(array_map(static fn (int $i): string => "x{$i}", range(1, 5000)), ['values' => []]);

        try {
            SectionValues::validate($this->schema(), $input);
            $this->fail('受け付けてしまった');
        } catch (ValidationException $e) {
            $this->assertCount(50, $e->errors());
        }
    }

    public function test_stored_values_that_no_longer_fit_are_dropped_without_stopping_the_shop(): void
    {
        $values = SectionValues::sanitize($this->schema(), [
            'footer' => ['values' => ['x' => 'y']],                      // テーマの更新で無くなった区画
            'hero' => ['hidden' => 'yes', 'values' => ['heading' => str_repeat('あ', 61), 'body' => '残る', 'link' => 'javascript:alert(1)', 'old' => 'x']],
            'features' => ['items' => [['title' => 'a'], 'broken', ['title' => 'b'], ['title' => 'c'], ['title' => 'd']]],
            'rail' => ['values' => ['products' => ['source' => 'pick', 'ids' => []]]],
        ]);

        $this->assertSame(['hero', 'features', 'rail'], array_keys($values));
        $this->assertSame(['hidden' => false, 'values' => ['heading' => null, 'body' => '残る', 'image' => null, 'link' => null], 'items' => []], $values['hero']);
        $this->assertSame(['a', 'b'], array_column($values['features']['items'], 'title'));
        $this->assertNull($values['rail']['values']['products']);

        // どんな形の値でも止まらない
        $this->assertSame([], SectionValues::sanitize($this->schema(), 'garbage'));
        $this->assertSame([], SectionValues::sanitize($this->schema(), [1, 2, 3]));
        $this->assertSame([], SectionValues::sanitize($this->schema(), null));
    }

    /**
     * ページの並び（ADR 0039）: しまってある並びが今の宣言に合わなくなっても、ショップは止めずに合う区画だけを出す
     */
    public function test_a_stored_page_that_no_longer_fits_keeps_what_still_fits(): void
    {
        $schema = $this->pageSchema();
        $home = $schema->page('home');
        $this->assertNotNull($home);

        $sections = SectionValues::sanitizePage($schema, $home, [
            ['id' => 'a', 'type' => 'footer', 'pattern' => 'default'],                                  // 無くなった種類
            ['id' => 'b', 'type' => 'hero', 'pattern' => 'diagonal', 'values' => ['heading' => '残る'],  // 無くなったパターン
                'blocks' => [['type' => 'video', 'values' => []], ['type' => 'button', 'values' => ['label' => '見る', 'old' => 'x']]]],
            ['id' => 'b', 'type' => 'rail'],                                                            // ID が重なる
            ['type' => 'rail'],                                                                         // ID が無い
            ['id' => 'c', 'type' => 'announcement'],
            ['id' => 'd', 'type' => 'announcement'],                                                    // 上限（1 つ）を越える
            'garbage',
        ]);

        $this->assertSame(['b', 'c'], array_column($sections, 'id'));
        $this->assertSame('full', $sections[0]['pattern']);
        $this->assertSame('残る', $sections[0]['values']['heading']);
        $this->assertSame([['type' => 'button', 'values' => ['label' => '見る', 'link' => null]]], $sections[0]['blocks']);

        // まだ保存していないページは最初の並び、壊れた値も最初の並び
        $this->assertSame(['announcement', 'hero', 'features', 'rail', 'shelves'], array_column(SectionValues::sanitizePage($schema, $home, null), 'id'));
        $this->assertSame(['announcement', 'hero', 'features', 'rail', 'shelves'], array_column(SectionValues::sanitizePage($schema, $home, 'garbage'), 'id'));
        $this->assertSame([], SectionValues::sanitizePage($schema, $home, []));
    }

    public function test_category_uses_follow_the_field_types_in_values_items_and_blocks(): void
    {
        // カテゴリを持てる欄を、区画の欄・繰り返し・ブロックのそれぞれに置いた宣言
        $schema = SectionSchema::fromArray([
            'shelf' => ['label' => '棚', 'fields' => ['list' => ['type' => 'categories', 'label' => 'カテゴリ'], 'note' => ['type' => 'text', 'label' => '文']]],
            'tabs' => ['label' => 'タブ', 'fields' => [], 'items' => ['label' => 'タブ', 'max' => 3, 'fields' => ['list' => ['type' => 'categories', 'label' => 'カテゴリ']]]],
            'rows' => ['label' => '段', 'fields' => [], 'blocks' => ['row' => ['label' => '段', 'fields' => ['products' => ['type' => 'products', 'label' => '商品']]]]],
        ]);
        $section = static fn (string $type, array $body): array => $body + ['id' => $type, 'type' => $type, 'pattern' => 'default', 'hidden' => false, 'values' => [], 'items' => [], 'blocks' => []];
        $stored = [
            $section('shelf', ['values' => ['list' => [self::ULID_B, strtoupper(self::ULID_A)], 'note' => self::ULID_B]]),
            $section('tabs', ['items' => [['list' => []], ['list' => [self::ULID_A]]]]),
            $section('rows', ['blocks' => [['type' => 'row', 'values' => ['products' => ['source' => 'category', 'category' => self::ULID_A, 'ids' => []]]]]]),
            // 出どころがカテゴリでなければ、残った category は数えない。宣言に無い種類・崩れた区画も数えない
            $section('rows', ['id' => 'rows-2', 'blocks' => [['type' => 'row', 'values' => ['products' => ['source' => 'pick', 'category' => self::ULID_B, 'ids' => [self::ULID_B]]]]]]),
            ['id' => 'x', 'type' => 'gone', 'values' => ['list' => [self::ULID_B]]],
            'garbage',
        ];

        $this->assertSame(['棚', 'タブ', '段'], SectionValues::categoryUses($schema, $stored, strtoupper(self::ULID_A)));
        // 文の欄に同じ文字があっても数えない（ULID_B はカテゴリの並びの欄の分だけ）
        $this->assertSame(['棚'], SectionValues::categoryUses($schema, $stored, self::ULID_B));
        $this->assertSame([], SectionValues::categoryUses($schema, null, self::ULID_A));
        $this->assertSame([], SectionValues::categoryUses($schema, ['shelf' => $stored[0]], self::ULID_A));
    }

    private function pageSchema(): SectionSchema
    {
        $manifest = json_decode((string) file_get_contents(base_path('tests/Support/Themes/sample/theme.json')), true);
        assert(is_array($manifest) && is_array($manifest['sections']));

        return SectionSchema::fromManifest($manifest['sections'], $manifest['pages'] ?? null);
    }
}
