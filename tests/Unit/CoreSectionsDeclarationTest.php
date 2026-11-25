<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Section\CoreSections;
use App\Core\Section\FieldType;
use App\Core\Section\InvalidSectionSchemaException;
use App\Core\Section\SectionIcons;
use App\Core\Section\SectionSchema;
use App\Core\Section\Wireframe;
use PHPUnit\Framework\TestCase;

/**
 * 核の共通の区画の宣言: 種類・パターン・ブロック・上限・見本・絵。どのページにも置ける。
 * 特長の絵は核が持つ決まった数の SVG の形だけで、部品は文字の属性だけを出す
 */
final class CoreSectionsDeclarationTest extends TestCase
{
    /** 種類 => [パターン, ブロックの種類, ブロックの上限] */
    private const array EXPECTED = [
        'basic_testimonials' => [['cards', 'quote', 'scroll'], ['voice'], 12],
        'basic_faq' => [['list', 'side'], ['question'], 20],
        'basic_features' => [['three', 'four', 'list'], ['feature'], 12],
        'basic_cta' => [['band', 'photo'], [], 0],
        'basic_gallery' => [['grid', 'masonry', 'scroll'], ['photo'], 20],
        'basic_product' => [['split', 'full'], [], 0],
        'basic_entries' => [['two', 'three'], ['entry'], 6],
        'basic_story' => [['longform', 'timeline'], ['milestone'], 12],
        // （見本帳の「次に」）
        'basic_numbers' => [['row', 'photo'], ['stat'], 6],
        'basic_logos' => [['row', 'grid'], ['logo'], 12],
        'basic_steps' => [['row', 'line'], ['step'], 8],
        'basic_table' => [['table', 'photo'], ['row'], 20],
        'basic_spacer' => [['space', 'line'], [], 0],
        //
        'basic_pricing' => [['cards', 'featured', 'table'], ['plan'], 4],
    ];

    public function test_the_core_sections_are_declared_with_patterns_blocks_and_wireframes(): void
    {
        foreach (self::EXPECTED as $type => [$patterns, $blocks, $maxBlocks]) {
            $spec = CoreSections::section($type);
            $this->assertNotNull($spec, $type);
            $this->assertSame($patterns, array_keys($spec->patterns), $type);
            $this->assertSame($blocks, array_keys($spec->blocks), $type);
            $this->assertSame($maxBlocks, $spec->maxBlocks, $type);

            // どのパターンにも核の語彙のワイヤーの絵
            foreach ($patterns as $pattern) {
                $this->assertTrue(Wireframe::known($spec->wireframes[$pattern] ?? ''), "{$type}.{$pattern}");
            }

            // 見本がある（足したらすぐ形になる）。写真・選んだ商品は見本に入れない
            $this->assertTrue(($spec->example['values'] ?? []) !== [] || $spec->exampleBlocks !== [], $type);
        }

        // 1 つの商品を大きく: 商品は選ぶだけ・1 つだけ・1 つのページに 1 つまで。見本に商品は入れない
        $product = CoreSections::section(CoreSections::PRODUCT);
        $this->assertNotNull($product);
        $this->assertSame(1, $product->limit);
        $this->assertSame(FieldType::Products, $product->fields['product']->type);
        $this->assertSame(['pick'], $product->fields['product']->sources);
        $this->assertSame(1, $product->fields['product']->max);
        $this->assertNull($product->example['values']['product'] ?? null);

        // 行き先の欄はどれも核のリンクの欄（店の中の道筋か https だけ）
        $this->assertSame(FieldType::Link, CoreSections::section('basic_cta')?->fields['button_link']->type);
        $this->assertSame(FieldType::Link, CoreSections::section('basic_faq')?->fields['button_link']->type);
        $this->assertSame(FieldType::Link, CoreSections::section('basic_entries')?->blocks['entry']->fields['link']->type);
        $logos = CoreSections::section('basic_logos');
        $this->assertNotNull($logos);
        $this->assertSame(FieldType::Link, $logos->blocks['logo']->fields['link']->type);
        $this->assertSame(FieldType::Image, $logos->blocks['logo']->fields['image']->type);

        // 比べる表: 列の見出しは区画の欄、行はブロック（核の文の欄だけで作る。表の欄の種類は足さない）
        $table = CoreSections::section(CoreSections::TABLE);
        $this->assertNotNull($table);
        for ($i = 1; $i <= CoreSections::TABLE_COLUMNS; $i++) {
            $this->assertSame(FieldType::Text, $table->fields["col{$i}"]->type);
            $this->assertSame(FieldType::Text, $table->blocks['row']->fields["c{$i}"]->type);
        }

        // 余白・区切り: 大きさは決まった 3 つ
        $this->assertSame(['s', 'm', 'l'], array_keys(CoreSections::section('basic_spacer')->fields['size']->options ?? []));

        // 見本に作り話の数を書かない（数字の見本は 00）
        foreach (CoreSections::section('basic_numbers')->exampleBlocks ?? [] as $block) {
            $this->assertSame('00', $block['values']['value']);
        }

        // 値段の表: プランは 4 つまで。ボタンの行き先は核のリンクの欄、値段は文字（注文と結びつかない）、おすすめの印は核の選択肢。
        // 見本はおすすめが 1 つだけで、ブロックをまたぐ決まりを通る
        $pricing = CoreSections::section(CoreSections::PRICING);
        $this->assertNotNull($pricing);
        $plan = $pricing->blocks['plan'];
        $this->assertSame(FieldType::Link, $plan->fields['button_link']->type);
        $this->assertSame(FieldType::Text, $plan->fields['price']->type);
        $this->assertSame(FieldType::Choice, $plan->fields['featured']->type);
        $this->assertSame(['recommended', 'popular', 'best'], array_keys($plan->fields['featured']->options));
        $this->assertSame(1, count(array_filter($pricing->exampleBlocks, static fn (array $b): bool => ($b['values']['featured'] ?? null) !== null)));
        $this->assertSame([], CoreSections::blockRules(CoreSections::PRICING, $pricing->exampleBlocks));

        // 特長の絵は核の選択肢（Lucide の決まった数）から
        $icon = CoreSections::section('basic_features')?->blocks['feature']->fields['icon'];
        $this->assertNotNull($icon);
        $this->assertSame(FieldType::Choice, $icon->type);
        $this->assertSame(SectionIcons::OPTIONS, $icon->options);
    }

    /**
     * 値段の表のブロックをまたぐ決まり: おすすめの印は 1 つのプランだけ（2 つ目から断る）、含むものは空でない行が決まった数まで。
     * ほかの種類には決まりが無い
     */
    public function test_the_pricing_rules_span_the_plans(): void
    {
        $plan = static fn (?string $featured = null, ?string $includes = null): array => ['type' => 'plan', 'values' => ['name' => 'x', 'featured' => $featured, 'includes' => $includes]];
        $lines = static fn (int $n): string => implode("\n", array_map(static fn (int $i): string => "行 {$i}", range(1, $n)));

        $this->assertSame([], CoreSections::blockRules(CoreSections::PRICING, [$plan('recommended'), $plan(), $plan()]));
        $this->assertSame(['blocks.2.values.featured', 'blocks.3.values.featured'],
            array_keys(CoreSections::blockRules(CoreSections::PRICING, [$plan(), $plan('best'), $plan('popular'), $plan('recommended')])));

        // 空の行は数えない
        $this->assertSame([], CoreSections::blockRules(CoreSections::PRICING, [$plan(null, $lines(CoreSections::PRICING_LINES)."\n\n  \n")]));
        $this->assertSame(['blocks.0.values.includes'], array_keys(CoreSections::blockRules(CoreSections::PRICING, [$plan(null, $lines(CoreSections::PRICING_LINES + 1))])));
        $this->assertSame(['行 1', '行 2'], CoreSections::lines("  行 1 \n\n行 2\n"));

        // ほかの種類には決まりが無い（特長に同じ形の値があっても）
        $this->assertSame([], CoreSections::blockRules('basic_features', [$plan('recommended'), $plan('recommended')]));
    }

    /**
     * トップにも核の区画を置ける（前は自由なページの雛形だけ）。知らない basic_ の名前は断る
     */
    public function test_any_page_may_place_the_core_sections(): void
    {
        $sections = ['hero' => ['label' => 'ヒーロー', 'fields' => ['heading' => ['type' => 'text', 'label' => '見出し']]]];
        $schema = SectionSchema::fromManifest($sections, ['home' => ['types' => ['hero', 'basic_faq', 'basic_product'], 'default' => [['type' => 'hero']]]]);
        $this->assertTrue($schema->page('home')?->allows('basic_faq'));
        $this->assertNotNull($schema->section('basic_product'));

        $this->expectException(InvalidSectionSchemaException::class);
        SectionSchema::fromManifest($sections, ['home' => ['types' => ['hero', 'basic_nope']]]);
    }

    /**
     * 特長の絵: 選択肢と形がそろい、形は決まった要素と、文字の入らない属性の値だけ（部品は {{ }} で出すが、そもそも記号を持たない）
     */
    public function test_the_feature_icons_are_fixed_shapes(): void
    {
        $this->assertSame(array_keys(SectionIcons::OPTIONS), array_keys(SectionIcons::NODES));
        $this->assertLessThanOrEqual(SectionSchema::MAX_OPTIONS, count(SectionIcons::OPTIONS));

        $allowed = ['path' => ['d'], 'circle' => ['cx', 'cy', 'r'], 'rect' => ['x', 'y', 'width', 'height', 'rx'], 'polyline' => ['points']];
        foreach (SectionIcons::NODES as $name => $nodes) {
            $this->assertMatchesRegularExpression('/\A[a-z][a-z0-9_]{0,31}\z/', $name);
            $this->assertNotSame([], $nodes, $name);

            foreach ($nodes as [$tag, $attributes]) {
                $this->assertArrayHasKey($tag, $allowed, $name);
                $this->assertSame([], array_diff(array_keys($attributes), $allowed[$tag]), "{$name} {$tag}");

                foreach ($attributes as $value) {
                    $this->assertMatchesRegularExpression('/\A[0-9A-Za-z .,\-]+\z/', $value, "{$name} {$tag}");
                }
            }
        }

        // サービス向けの絵も選べる
        foreach (['wrench', 'message_circle', 'calendar_days', 'zap', 'lock', 'headset', 'sliders_horizontal', 'rocket', 'handshake', 'clipboard_check'] as $name) {
            $this->assertArrayHasKey($name, SectionIcons::OPTIONS);
        }

        $this->assertNull(SectionIcons::nodes('skull'));
        $this->assertNull(SectionIcons::nodes(null));
        $this->assertNotNull(SectionIcons::nodes('truck'));
    }

    /**
     * 核の区画の部品も、テーマの画面と同じく表示だけ（エスケープしない出力・PHP・script・style 属性・外部の URL を使わない）
     */
    public function test_the_core_section_views_only_display(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views/storefront';
        $files = [...glob("{$root}/sections/*.blade.php") ?: [], "{$root}/components/section-icon.blade.php"];
        $this->assertGreaterThanOrEqual(13, count($files));

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach (['/\{!!/', '/@php|<\?php|<\?=/', '/<script\b/i', '/\sstyle=|<style\b/i', '/https?:\/\//'] as $forbidden) {
                $this->assertSame(0, preg_match($forbidden, $source), "{$file}: {$forbidden}");
            }

            // 区画の部品は押して選ぶ印を出す
            if (str_contains($file, '/sections/')) {
                $this->assertStringContainsString('data-section="{{ $section->id }}"', $source, $file);
            }
        }
    }
}
