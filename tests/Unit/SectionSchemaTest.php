<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Section\CoreSections;
use App\Core\Section\FieldType;
use App\Core\Section\InvalidSectionSchemaException;
use App\Core\Section\SectionSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * テーマの区画の宣言（theme.json の sections。ADR 0028 区切り 2）。核は形だけを確かめ、上限は核の決まりを越えられない
 */
final class SectionSchemaTest extends TestCase
{
    public function test_a_declaration_is_read_in_order_with_the_core_caps_filled_in(): void
    {
        $schema = SectionSchema::fromArray([
            'hero' => ['label' => ' ヒーロー ', 'fields' => [
                'heading' => ['type' => 'text', 'label' => '見出し', 'max' => 60],
                'body' => ['type' => 'textarea', 'label' => '文'],
                'image' => ['type' => 'image', 'label' => '写真'],
            ]],
            'features' => ['label' => '特集', 'items' => ['max' => 3, 'fields' => [
                'tone' => ['type' => 'choice', 'label' => '色', 'options' => ['light' => '明るい', 'dark' => '暗い']],
            ]]],
            'rail' => ['label' => '並び', 'fields' => ['products' => ['type' => 'products', 'label' => '商品']]],
        ]);

        $this->assertSame(['hero', 'features', 'rail'], array_keys($schema->sections));
        $hero = $schema->section('hero');
        $this->assertNotNull($hero);
        $this->assertSame('ヒーロー', $hero->label);
        $this->assertSame(60, $hero->fields['heading']->max);
        // max を書かなければ核の上限
        $this->assertSame(FieldType::Textarea->cap(), $hero->fields['body']->max);
        $this->assertNull($hero->fields['image']->max);
        $this->assertFalse($hero->hasItems());

        $features = $schema->section('features');
        $this->assertNotNull($features);
        $this->assertSame(3, $features->maxItems);
        $this->assertSame([], $features->fields);
        $this->assertSame(FieldType::PRODUCT_SOURCES, $schema->section('rail')?->fields['products']->sources);

        // 管理画面に渡す形（パターンはどの種類にも出す。書かなければ default の 1 つ。ADR 0039）
        $this->assertSame([
            'label' => '特集',
            'fields' => [],
            'patterns' => ['default' => '標準'],
            'items' => ['max' => 3, 'fields' => ['tone' => ['type' => 'choice', 'label' => '色', 'options' => ['light' => '明るい', 'dark' => '暗い']]]],
        ], $schema->toArray()['features']);
        $this->assertSame(['type' => 'text', 'label' => '見出し', 'max' => 60], $schema->toArray()['hero']['fields']['heading']);
    }

    /**
     * @param  array<mixed>  $sections
     */
    #[DataProvider('broken')]
    public function test_a_broken_declaration_is_refused_with_where_it_is_wrong(array $sections, string $path): void
    {
        try {
            SectionSchema::fromArray($sections);
            $this->fail('受け付けてしまった');
        } catch (InvalidSectionSchemaException $e) {
            $this->assertSame($path, $e->path);
        }
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function broken(): iterable
    {
        $text = ['type' => 'text', 'label' => '文'];

        yield 'セクションが並び' => [[['label' => 'a', 'fields' => ['a' => $text]]], 'sections'];
        yield 'セクションの名前が大文字' => [['Hero' => ['label' => 'a', 'fields' => ['a' => $text]]], 'sections'];
        yield 'セクションの名前に道筋' => [['../x' => ['label' => 'a', 'fields' => ['a' => $text]]], 'sections'];
        yield 'セクションの名前が数字' => [['1' => ['label' => 'a', 'fields' => ['a' => $text]]], 'sections'];
        yield 'セクションの名前が長い' => [[str_repeat('a', 33) => ['label' => 'a', 'fields' => ['a' => $text]]], 'sections'];
        yield 'セクションに知らない書き方' => [['hero' => ['label' => 'a', 'fields' => ['a' => $text], 'style' => 'x']], 'sections.hero'];
        yield 'セクションの名前が無い' => [['hero' => ['fields' => ['a' => $text]]], 'sections.hero.label'];
        yield 'セクションの名前に改行' => [['hero' => ['label' => "a\nb", 'fields' => ['a' => $text]]], 'sections.hero.label'];
        yield 'セクションの名前に C1 制御文字' => [['hero' => ['label' => "a\u{0085}", 'fields' => ['a' => $text]]], 'sections.hero.label'];
        yield 'セクションの名前が長い文' => [['hero' => ['label' => str_repeat('あ', 31), 'fields' => ['a' => $text]]], 'sections.hero.label'];
        yield '欄が無い' => [['hero' => ['label' => 'a']], 'sections.hero'];
        yield '欄が並び' => [['hero' => ['label' => 'a', 'fields' => [$text]]], 'sections.hero.fields'];
        yield '欄が多すぎる' => [['hero' => ['label' => 'a', 'fields' => array_fill_keys(array_map(static fn (int $i): string => "f{$i}", range(1, 21)), $text)]], 'sections.hero.fields'];
        yield '知らない種類' => [['hero' => ['label' => 'a', 'fields' => ['a' => ['type' => 'script', 'label' => 'x']]]], 'sections.hero.fields.a.type'];
        yield '種類が無い' => [['hero' => ['label' => 'a', 'fields' => ['a' => ['label' => 'x']]]], 'sections.hero.fields.a.type'];
        yield '欄に知らない書き方' => [['hero' => ['label' => 'a', 'fields' => ['a' => $text + ['default' => 'x']]]], 'sections.hero.fields.a'];
        yield '上限が核を越える' => [['hero' => ['label' => 'a', 'fields' => ['a' => $text + ['max' => 201]]]], 'sections.hero.fields.a.max'];
        yield '上限が 0' => [['hero' => ['label' => 'a', 'fields' => ['a' => $text + ['max' => 0]]]], 'sections.hero.fields.a.max'];
        yield '上限が文字' => [['hero' => ['label' => 'a', 'fields' => ['a' => $text + ['max' => '60']]]], 'sections.hero.fields.a.max'];
        yield '写真に上限' => [['hero' => ['label' => 'a', 'fields' => ['a' => ['type' => 'image', 'label' => 'x', 'max' => 3]]]], 'sections.hero.fields.a.max'];
        yield '商品の並びが核を越える' => [['hero' => ['label' => 'a', 'fields' => ['a' => ['type' => 'products', 'label' => 'x', 'max' => 25]]]], 'sections.hero.fields.a.max'];
        yield '選択肢が無い choice' => [['hero' => ['label' => 'a', 'fields' => ['a' => ['type' => 'choice', 'label' => 'x']]]], 'sections.hero.fields.a.options'];
        yield '文に選択肢' => [['hero' => ['label' => 'a', 'fields' => ['a' => $text + ['options' => ['x' => 'y']]]]], 'sections.hero.fields.a.options'];
        yield '選択肢が並び' => [['hero' => ['label' => 'a', 'fields' => ['a' => ['type' => 'choice', 'label' => 'x', 'options' => ['a', 'b']]]]], 'sections.hero.fields.a.options'];
        yield '選択肢の値が大文字' => [['hero' => ['label' => 'a', 'fields' => ['a' => ['type' => 'choice', 'label' => 'x', 'options' => ['Light' => 'y']]]]], 'sections.hero.fields.a.options'];
        yield '文に取得元' => [['hero' => ['label' => 'a', 'fields' => ['a' => $text + ['from' => ['new']]]]], 'sections.hero.fields.a.from'];
        yield '知らない取得元' => [['hero' => ['label' => 'a', 'fields' => ['a' => ['type' => 'products', 'label' => 'x', 'from' => ['all']]]]], 'sections.hero.fields.a.from'];
        yield '取得元が空' => [['hero' => ['label' => 'a', 'fields' => ['a' => ['type' => 'products', 'label' => 'x', 'from' => []]]]], 'sections.hero.fields.a.from'];
        yield '説明が長い' => [['hero' => ['label' => 'a', 'fields' => ['a' => $text + ['help' => str_repeat('あ', 101)]]]], 'sections.hero.fields.a.help'];
        yield '繰り返しに上限が無い' => [['hero' => ['label' => 'a', 'items' => ['fields' => ['a' => $text]]]], 'sections.hero.items.max'];
        yield '繰り返しが核を越える' => [['hero' => ['label' => 'a', 'items' => ['max' => 13, 'fields' => ['a' => $text]]]], 'sections.hero.items.max'];
        yield '繰り返しに欄が無い' => [['hero' => ['label' => 'a', 'items' => ['max' => 3, 'fields' => []]]], 'sections.hero.items.fields'];
        yield '繰り返しの入れ子' => [['hero' => ['label' => 'a', 'items' => ['max' => 3, 'fields' => ['a' => $text], 'items' => []]]], 'sections.hero.items'];
    }

    /**
     * 核の汎用の区画にも見本がある（足した区画に入る、見本と分かる短い文。写真は入れない）。
     * 欄に見本の無い区画（入口を並べる）は、ブロックの見本で形になる
     */
    public function test_the_core_sections_carry_examples_without_photos(): void
    {
        foreach (CoreSections::specs() as $key => $spec) {
            $values = array_filter($spec->example['values'] ?? [], static fn (mixed $v): bool => $v !== null);
            $this->assertTrue($values !== [] || $spec->exampleBlocks !== [], $key);
            $this->assertNull($spec->example['values']['image'] ?? null, $key);
        }

        $this->assertSame(['label' => 'くわしく見る', 'link' => '/products'], CoreSections::section('basic_button')?->example['values'] ?? null);
        $this->assertSame('見出しを入力してください', CoreSections::section('basic_text')?->toArray()['example']['values']['heading'] ?? null);
    }

    public function test_the_number_of_sections_is_capped(): void
    {
        $section = ['label' => 'a', 'fields' => ['a' => ['type' => 'text', 'label' => 'x']]];
        SectionSchema::fromArray(array_fill_keys(array_map(static fn (int $i): string => "s{$i}", range(1, SectionSchema::MAX_SECTIONS)), $section));

        $this->expectException(InvalidSectionSchemaException::class);
        SectionSchema::fromArray(array_fill_keys(array_map(static fn (int $i): string => "s{$i}", range(1, SectionSchema::MAX_SECTIONS + 1)), $section));
    }
}
