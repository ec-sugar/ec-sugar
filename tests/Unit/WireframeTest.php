<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Section\CoreSections;
use App\Core\Section\InvalidSectionSchemaException;
use App\Core\Section\SectionSchema;
use App\Core\Section\Wireframe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 区画のパターンのワイヤーの絵。名前は核の語彙だけ（テーマは絵を持ち込まない。ADR 0020）。知らない名前は登録で断り、書かなければ汎用の絵
 */
final class WireframeTest extends TestCase
{
    private const array HERO = ['heading' => ['type' => 'text', 'label' => '見出し']];

    public function test_a_pattern_chooses_a_wireframe_by_name_and_a_section_gives_the_rest(): void
    {
        $schema = SectionSchema::fromArray([
            'hero' => ['label' => 'ヒーロー', 'fields' => self::HERO, 'patterns' => [
                'full' => ['label' => '全面', 'wireframe' => 'hero-full'],
                'split' => ['label' => '左右', 'wireframe' => 'hero-split'],
                'plain' => ['label' => '飾りなし'],
            ]],
            // 区画に書いた絵は、絵を書いていないパターンの絵
            'band' => ['label' => '帯', 'wireframe' => 'announcement-bar', 'fields' => self::HERO, 'patterns' => [
                'dark' => ['label' => '濃い', 'wireframe' => 'promises-dark'],
                'light' => ['label' => '明るい'],
            ]],
            // パターンを宣言しない区画は、区画に書くだけ
            'text' => ['label' => '文', 'wireframe' => 'text-left', 'fields' => self::HERO],
            // 書かなければ何も渡さない（編集の画面が汎用の絵と名前で出す）
            'other' => ['label' => 'ほか', 'fields' => self::HERO],
        ]);

        $this->assertSame(['full' => 'hero-full', 'split' => 'hero-split'], $schema->section('hero')?->wireframes);
        $this->assertSame(['full' => 'hero-full', 'split' => 'hero-split'], $schema->toArray()['hero']['wireframes']);
        // パターンの名前と画面の名前は今までどおり
        $this->assertSame(['full' => '全面', 'split' => '左右', 'plain' => '飾りなし'], $schema->toArray()['hero']['patterns']);
        $this->assertSame(['dark' => 'promises-dark', 'light' => 'announcement-bar'], $schema->section('band')?->wireframes);
        $this->assertSame(['default' => 'text-left'], $schema->toArray()['text']['wireframes']);
        $this->assertArrayNotHasKey('wireframes', $schema->toArray()['other']);
    }

    /**
     * @param  array<mixed>  $sections
     */
    #[DataProvider('unknown')]
    public function test_an_unknown_wireframe_is_refused_where_it_is_written(array $sections, string $path): void
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
    public static function unknown(): iterable
    {
        $hero = static fn (mixed $wireframe): array => ['hero' => ['label' => 'a', 'fields' => self::HERO, 'patterns' => ['full' => ['label' => '全面', 'wireframe' => $wireframe]]]];

        yield '知らない名前' => [$hero('hero-huge'), 'sections.hero.patterns.full.wireframe'];
        yield '大文字' => [$hero('Hero-Full'), 'sections.hero.patterns.full.wireframe'];
        yield 'SVG を持ち込む' => [$hero('<svg><script>alert(1)</script></svg>'), 'sections.hero.patterns.full.wireframe'];
        yield '道筋' => [$hero('../hero-full'), 'sections.hero.patterns.full.wireframe'];
        yield '空' => [$hero(''), 'sections.hero.patterns.full.wireframe'];
        yield '文字でない' => [$hero(['hero-full']), 'sections.hero.patterns.full.wireframe'];
        yield 'null' => [$hero(null), 'sections.hero.patterns.full.wireframe'];
        yield 'セクションに知らない名前' => [['hero' => ['label' => 'a', 'fields' => self::HERO, 'wireframe' => 'nope']], 'sections.hero.wireframe'];
        yield 'パターンに知らない書き方' => [['hero' => ['label' => 'a', 'fields' => self::HERO, 'patterns' => ['full' => ['label' => '全面', 'svg' => 'x']]]], 'sections.hero.patterns.full'];
    }

    /**
     * 絵の一覧は核の 2 か所（ここと管理画面の wireframes.js）でそろえる
     */
    public function test_the_vocabulary_matches_the_admin_drawings(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/admin/components/sections/wireframes.js');
        $this->assertSame(1, preg_match('/const shapes = \{(.*?)\n\};/s', $source, $block));
        preg_match_all("/^\s{4}'([a-z0-9-]+)': /m", $block[1], $names);

        $this->assertNotSame([], $names[1]);
        $this->assertSame(array_keys(Wireframe::NAMES), $names[1]);
    }

    /**
     * 公式テーマ 4 つと核の汎用の区画は、すべてのパターンに絵がある（テーマを替えても「＋ 区画を足す」が汎用の絵にならない）
     */
    public function test_every_pattern_of_the_official_themes_and_the_core_sections_has_a_wireframe(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['default', 'kinari', 'fashion', 'botanica'] as $theme) {
            $manifest = json_decode((string) file_get_contents("{$root}/plugins/theme-{$theme}/theme.json"), true, flags: JSON_THROW_ON_ERROR);
            $schema = SectionSchema::fromManifest($manifest['sections'], $manifest['pages'] ?? null);

            foreach ($schema->sections as $key => $spec) {
                $this->assertSame(array_keys($spec->patterns), array_keys($spec->wireframes), "{$theme}.{$key}");
            }
        }

        foreach (CoreSections::specs() as $key => $spec) {
            $this->assertSame(array_keys($spec->patterns), array_keys($spec->wireframes), $key);
        }
    }
}
