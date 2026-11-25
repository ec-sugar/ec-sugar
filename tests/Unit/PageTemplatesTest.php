<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Section\InvalidSectionSchemaException;
use App\Core\Section\PageTemplates;
use App\Core\Section\SectionRole;
use App\Core\Section\SectionSchema;
use PHPUnit\Framework\TestCase;

/**
 * ページの雛形: 核の区画とテーマの役目で書き、テーマの宣言（role）から合う種類を選ぶ。役目の区画が無い・置けないときは核の区画で代わりにし、
 * 代わりも置けなければ飛ばす。作り直すときは外枠が読む区画を残し、残した区画も 1 つのページに置ける数に数える。見本の文に作り話の数を書かない
 */
final class PageTemplatesTest extends TestCase
{
    private const array FIELD = ['heading' => ['type' => 'text', 'label' => '見出し']];

    public function test_there_are_seven_templates(): void
    {
        $this->assertSame(['shop_top', 'single_product', 'brand_story', 'seasonal', 'faq', 'how_to', 'service'], PageTemplates::names());
    }

    public function test_a_theme_names_the_role_of_its_sections_and_unknown_roles_are_refused(): void
    {
        $schema = $this->schema();
        $home = $schema->page('home');
        $this->assertNotNull($home);
        $this->assertSame(SectionRole::Hero, $schema->section('cover')?->role);
        // 同じ役目が 2 つあれば、ページの types の順で最初のもの
        $this->assertSame('rail', $schema->typeFor(SectionRole::Products, $home));
        $this->assertNull($schema->typeFor(SectionRole::Categories, $home));
        // 核の区画は役目を持たない
        $this->assertNull($schema->section('basic_products')?->role);

        foreach (['shelf', 7, ''] as $role) {
            try {
                SectionSchema::fromManifest(['cover' => ['label' => '表紙', 'role' => $role, 'fields' => self::FIELD]], ['home' => ['types' => ['cover']]]);
                $this->fail('知らない役目を受け付けた: '.json_encode($role));
            } catch (InvalidSectionSchemaException $e) {
                $this->assertSame('sections.cover.role', $e->path);
            }
        }
    }

    public function test_roles_choose_the_theme_section_or_fall_back_to_a_core_section_or_are_skipped(): void
    {
        $schema = $this->schema();
        $home = $schema->page('home');
        $this->assertNotNull($home);

        // トップ: ヒーローと商品の並びはテーマの区画、カテゴリは代わりの入口（核）、安心の一行は代わりの特長も置けないので飛ばす
        $top = PageTemplates::resolve('shop_top', $schema, $home);
        $this->assertNotNull($top);
        $this->assertSame(
            [['cover', 'wide', 'theme'], ['basic_entries', 'three', 'fallback'], ['rail', 'default', 'theme'], ['basic_story', 'longform', 'core'], ['basic_testimonials', 'cards', 'core']],
            array_map(static fn (array $r): array => [$r['type'], $r['pattern'], $r['from']], $top['rows']),
        );
        $this->assertSame(['安心の一行'], $top['skipped']);
        // テーマの区画の値はテーマの見本、核の区画は見本に雛形の上書きを重ねたもの
        $this->assertSame('表紙の見本', $top['rows'][0]['values']['heading']);
        $this->assertSame('私たちについて', $top['rows'][3]['values']['kicker']);
        $this->assertSame('ここにお店の紹介の見出しを書きます', $top['rows'][3]['values']['heading']);
        $this->assertSame('story-longform', $top['rows'][3]['wireframe']);

        // 自由なページ（置ける種類が少ない）: 置けない核の区画は飛ばす
        $faq = PageTemplates::resolve('faq', $schema, $schema->freePage());
        $this->assertNotNull($faq);
        $this->assertSame(['basic_text'], array_column($faq['rows'], 'type'));
        $this->assertSame(['よくある質問', 'CTA バナー'], $faq['skipped']);

        $this->assertNull(PageTemplates::resolve('nope', $schema, $home));
    }

    /**
     * 作り直すとき、外枠が読む区画（お知らせの帯など）を残す: 最初に描く区画より前にあったものは先頭へ、後ろのものは最後へ。
     * 残した区画も 1 つのページに置ける数に数える（表紙を 1 つまでにしたテーマで、残した表紙があれば雛形の表紙は飛ばす）
     */
    public function test_rebuilding_keeps_the_frame_sections_and_counts_them_toward_the_limits(): void
    {
        $schema = $this->schema();
        $home = $schema->page('home');
        $this->assertNotNull($home);
        $placed = static fn (string $id, string $type): array => ['id' => $id, 'type' => $type, 'pattern' => 'default', 'hidden' => false, 'values' => [], 'items' => [], 'blocks' => []];
        $current = [$placed('notice', 'notice'), $placed('old', 'basic_text'), $placed('foot', 'foot')];
        $frames = ['notice', 'foot'];

        $built = PageTemplates::build('seasonal', $schema, $home, $current, static fn (array $s): bool => in_array($s['type'], $frames, true));
        $this->assertNotNull($built);
        $this->assertSame(['notice', 'cover', 'basic_text', 'basic_products', 'basic_image_text', 'basic_products', 'basic_cta', 'foot'], array_column($built, 'type'));
        // 残した区画は ID のまま（値もそのまま）、雛形の区画は ID 無し（新しく足す区画）
        $this->assertSame('notice', $built[0]['id']);
        $this->assertSame('foot', $built[7]['id']);
        $this->assertArrayNotHasKey('id', $built[1]);

        // 表紙（1 つまで）を残すときは、雛形の表紙を足さない
        $built = PageTemplates::build('brand_story', $schema, $home, [$placed('cover', 'cover')], static fn (): bool => true);
        $this->assertNotNull($built);
        $this->assertSame(['cover', 'basic_story', 'basic_numbers', 'basic_story', 'basic_gallery', 'basic_cta'], array_column($built, 'type'));
    }

    /**
     * 雛形の見本の文: 作り話の実績・数（「創業 30 年」「累計 10 万個」のような数と単位、西暦）を書かない。写真と商品は選ばない
     */
    public function test_the_template_texts_do_not_invent_numbers_photos_or_products(): void
    {
        $schema = $this->schema();
        $home = $schema->page('home');
        $this->assertNotNull($home);

        foreach (PageTemplates::names() as $name) {
            $resolved = PageTemplates::resolve($name, $schema, $home);
            $this->assertNotNull($resolved);

            foreach ($resolved['rows'] as $row) {
                $texts = [];
                array_walk_recursive($row, static function (mixed $value) use (&$texts): void {
                    if (is_string($value)) {
                        $texts[] = $value;
                    }
                });

                foreach ($texts as $text) {
                    $this->assertDoesNotMatchRegularExpression('/[1-9][0-9]*\s*(年|個|%|万|人|件|店)|[12][0-9]{3}/u', $text, "{$name}: {$text}");
                }

                foreach (['image', 'product'] as $field) {
                    $this->assertNull($row['values'][$field] ?? null, "{$name} {$row['type']}");
                }
            }
        }
    }

    /**
     * 役目の区画を持つテーマ。トップには役目の区画と核の区画の一部、自由なページは見出しと文だけ
     */
    private function schema(): SectionSchema
    {
        return SectionSchema::fromManifest([
            'notice' => ['label' => 'お知らせ', 'limit' => 1, 'fields' => self::FIELD],
            'cover' => ['label' => '表紙', 'role' => 'hero', 'limit' => 1, 'fields' => self::FIELD,
                'patterns' => ['wide' => ['label' => '広い'], 'narrow' => ['label' => '狭い']], 'example' => ['heading' => '表紙の見本']],
            'rail' => ['label' => '新着', 'role' => 'products', 'fields' => self::FIELD],
            'picks' => ['label' => 'おすすめ', 'role' => 'products', 'fields' => self::FIELD],
            'foot' => ['label' => 'フッター', 'limit' => 1, 'fields' => self::FIELD],
        ], [
            'home' => ['types' => ['notice', 'cover', 'rail', 'picks', 'foot', 'basic_text', 'basic_entries', 'basic_story', 'basic_testimonials', 'basic_numbers',
                'basic_gallery', 'basic_cta', 'basic_products', 'basic_image_text']],
            'page' => ['types' => ['basic_text'], 'default' => [['type' => 'basic_text']]],
        ]);
    }
}
