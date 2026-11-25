<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * テーマ API 2 の theme.json（ADR 0028 区切り 2）。登録のときに読んで確かめ、形の違うテーマは登録しない
 */
final class ThemeManifestTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_a_theme_with_a_valid_manifest_gets_its_sections(): void
    {
        $registry = new ThemeRegistry;
        $registry->register(new ThemeDefinition('sample', '見本', 2, 'theme-sample', 'x.css', manifest: dirname(__DIR__).'/Support/Themes/sample/theme.json'));

        $sections = $registry->get('sample')->sections;
        $this->assertNotNull($sections);
        $this->assertSame(['announcement', 'hero', 'features', 'rail', 'shelves'], array_keys($sections->sections));
        // 種類ごとのパターン（書かなければ default の 1 つ）とページの宣言
        $this->assertSame(['full', 'split', 'grid'], array_keys($sections->sections['hero']->patterns));
        $this->assertSame(['default'], array_keys($sections->sections['rail']->patterns));
        $home = $sections->page('home');
        $this->assertNotNull($home);
        $this->assertFalse($home->fixed);
        $this->assertSame(['type' => 'hero', 'pattern' => 'full'], $home->default[1]);
        $this->assertSame(['type' => 'rail', 'pattern' => 'default'], $home->default[3]);

        // テーマ API 1（店の設定の見せ方の項目を読む形）は区切り 2c でやめた
        $this->expectException(InvalidArgumentException::class);
        $registry->register(new ThemeDefinition('legacy', '前のバージョン', 1, 'legacy', 'x.css'));
    }

    public function test_a_broken_manifest_keeps_the_theme_out(): void
    {
        $cases = [
            'theme.json が無い' => [2, null],
            '読めない' => [2, '{"theme_api": 2, "sections": {'],
            'バージョンが登録と違う' => [2, '{"theme_api": 1, "sections": {}}'],
            'バージョンが文字' => [2, '{"theme_api": "2", "sections": {}}'],
            'セクションが無い' => [2, '{"theme_api": 2}'],
            '知らない書き方' => [2, '{"theme_api": 2, "sections": {}, "scripts": ["https://evil.example/x.js"]}'],
            'セクションの形が違う' => [2, '{"theme_api": 2, "sections": {"hero": {"label": "x", "fields": {"a": {"type": "html", "label": "x"}}}}}'],
            '並び' => [2, '[1, 2]'],
            '深すぎる' => [2, str_repeat('[', 40).str_repeat(']', 40)],
            '大きすぎる' => [2, '{"theme_api": 2, "sections": {}, "x": "'.str_repeat('a', 70000).'"}'],
            'API 1' => [1, '{"theme_api": 1, "sections": {}}'],
            // ページの並び（ADR 0039）
            '核の知らないページ' => [2, '{"theme_api": 2, "sections": {"hero": {"label": "x", "fields": {"a": {"type": "text", "label": "x"}}}}, "pages": {"home": {"types": ["hero"]}, "about": {"types": ["hero"]}}}'],
            'トップが無い' => [2, '{"theme_api": 2, "sections": {"hero": {"label": "x", "fields": {"a": {"type": "text", "label": "x"}}}}, "pages": {}}'],
            '宣言に無い種類を置く' => [2, '{"theme_api": 2, "sections": {"hero": {"label": "x", "fields": {"a": {"type": "text", "label": "x"}}}}, "pages": {"home": {"types": ["hero", "html"]}}}'],
            'どのページにも置けない種類' => [2, '{"theme_api": 2, "sections": {"hero": {"label": "x", "fields": {"a": {"type": "text", "label": "x"}}}, "rail": {"label": "y", "fields": {"a": {"type": "text", "label": "x"}}}}, "pages": {"home": {"types": ["hero"]}}}'],
            '最初の並びに無いパターン' => [2, '{"theme_api": 2, "sections": {"hero": {"label": "x", "patterns": {"full": {"label": "全面"}}, "fields": {"a": {"type": "text", "label": "x"}}}}, "pages": {"home": {"types": ["hero"], "default": [{"type": "hero", "pattern": "split"}]}}}'],
            '最初の並びが上限を越える' => [2, '{"theme_api": 2, "sections": {"hero": {"label": "x", "limit": 1, "fields": {"a": {"type": "text", "label": "x"}}}}, "pages": {"home": {"types": ["hero"], "default": [{"type": "hero"}, {"type": "hero"}]}}}'],
            'パターンの名前' => [2, '{"theme_api": 2, "sections": {"hero": {"label": "x", "patterns": {"../x": {"label": "x"}}, "fields": {"a": {"type": "text", "label": "x"}}}}}'],
            'ブロックの上限を越える' => [2, '{"theme_api": 2, "sections": {"hero": {"label": "x", "blocks": {"b": {"label": "x", "fields": {"a": {"type": "text", "label": "x"}}}}, "max_blocks": 21}}}'],
            'ブロックの欄の種類' => [2, '{"theme_api": 2, "sections": {"hero": {"label": "x", "blocks": {"b": {"label": "x", "fields": {"a": {"type": "html", "label": "x"}}}}}}}'],
        ];

        foreach ($cases as $case => [$api, $json]) {
            $registry = new ThemeRegistry;
            $path = $json === null ? sys_get_temp_dir().'/missing-theme.json' : $this->manifest($json);

            try {
                $registry->register(new ThemeDefinition('broken', '壊れた', $api, 'broken', 'x.css', manifest: $path));
                $this->fail("登録してしまった: {$case}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('broken', $e->getMessage(), $case);
                $this->assertFalse($registry->has('broken'), $case);
            }
        }

        // API 2 なのに theme.json を渡さない
        $this->expectException(InvalidArgumentException::class);
        (new ThemeRegistry)->register(new ThemeDefinition('broken', '壊れた', 2, 'broken', 'x.css'));
    }

    /**
     * 動きの強さ（theme.json の motion）: 書かなければ控えめ、決まった値なら入り、ほかの値は登録で止める
     */
    public function test_the_motion_strength_is_read_from_the_manifest(): void
    {
        $sample = json_decode((string) file_get_contents(dirname(__DIR__).'/Support/Themes/sample/theme.json'), true);
        $this->assertArrayNotHasKey('motion', $sample);

        $registry = new ThemeRegistry;
        $registry->register(new ThemeDefinition('plain', '書かない', 2, 'plain', 'x.css', manifest: $this->manifest((string) json_encode($sample))));
        $registry->register(new ThemeDefinition('lively', 'しっかり', 2, 'lively', 'x.css', manifest: $this->manifest((string) json_encode(['motion' => 'rich'] + $sample))));
        $this->assertSame('subtle', $registry->get('plain')->motion);
        $this->assertSame('rich', $registry->get('lively')->motion);

        // 外枠の data-motion に出す値なので、決まった 3 つのほかは書き間違いとして断る（文字でない値・属性を壊す文字も）
        foreach (['wild', 'RICH', '" onload="x', 1, true, null] as $motion) {
            try {
                $registry->register(new ThemeDefinition('broken', '壊れた', 2, 'broken', 'x.css', manifest: $this->manifest((string) json_encode(['motion' => $motion] + $sample))));
                $this->fail('登録してしまった: '.var_export($motion, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('motion', $e->getMessage());
                $this->assertFalse($registry->has('broken'));
            }
        }
    }

    /**
     * 見本の絵（theme.json の screenshot）: theme.json と同じフォルダの png・jpg・webp のファイルの名前だけ。
     * 外のファイルを指す道筋・無いファイル・大きすぎるファイル・ほかの種類は登録で止める。書かなければ無い
     */
    public function test_the_screenshot_is_read_from_the_manifest_and_stays_inside_the_theme(): void
    {
        $sample = json_decode((string) file_get_contents(dirname(__DIR__).'/Support/Themes/sample/theme.json'), true);
        $directory = sys_get_temp_dir().'/theme-shot-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents("{$directory}/shot.jpg", "\xFF\xD8\xFF".str_repeat('a', 100));
        file_put_contents("{$directory}/empty.png", '');
        file_put_contents("{$directory}/large.webp", str_repeat('a', ThemeRegistry::MAX_SCREENSHOT_BYTES + 1));
        file_put_contents("{$directory}/shot.gif", 'GIF89a');
        file_put_contents(dirname($directory).'/outside.png', "\x89PNG");
        $write = function (mixed $screenshot) use ($directory, $sample): string {
            file_put_contents("{$directory}/theme.json", (string) json_encode(['screenshot' => $screenshot] + $sample));

            return "{$directory}/theme.json";
        };

        try {
            $registry = new ThemeRegistry;
            $registry->register(new ThemeDefinition('plain', '書かない', 2, 'plain', 'x.css', manifest: dirname(__DIR__).'/Support/Themes/sample/theme.json'));
            $registry->register(new ThemeDefinition('shot', '絵あり', 2, 'shot', 'x.css', manifest: $write('shot.jpg')));
            $this->assertNull($registry->get('plain')->screenshot);
            $this->assertSame(realpath("{$directory}/shot.jpg"), $registry->get('shot')->screenshot);

            foreach (['../outside.png', '/etc/passwd', 'sub/shot.jpg', 'shot.gif', 'Shot.jpg', 'missing.png', 'empty.png', 'large.webp', '', 1, ['shot.jpg']] as $screenshot) {
                try {
                    $registry->register(new ThemeDefinition('broken', '壊れた', 2, 'broken', 'x.css', manifest: $write($screenshot)));
                    $this->fail('登録してしまった: '.var_export($screenshot, true));
                } catch (InvalidArgumentException $e) {
                    $this->assertStringContainsString('broken', $e->getMessage());
                    $this->assertFalse($registry->has('broken'));
                }
            }
        } finally {
            array_map('unlink', glob("{$directory}/*") ?: []);
            rmdir($directory);
            @unlink(dirname($directory).'/outside.png');
        }
    }

    /**
     * 自由なページ（ADR 0039 区切り 3）: pages.page は店の人が作るページの雛形。書かなければ核の汎用の区画（basic_*）の雛形。
     * テーマは basic_ で始まる区画を宣言できず、雛形に置けない種類も登録で止める
     */
    public function test_the_free_page_template_is_read_or_falls_back_to_the_core_sections(): void
    {
        $sample = json_decode((string) file_get_contents(dirname(__DIR__).'/Support/Themes/sample/theme.json'), true);
        $registry = new ThemeRegistry;
        $registry->register(new ThemeDefinition('sample', '見本', 2, 'theme-sample', 'x.css', manifest: $this->manifest((string) json_encode($sample))));
        $free = $registry->get('sample')->schema()->page('page-01k8z3x5y7a9b1c3d5e7f9g1h3');
        $this->assertNotNull($free);
        $this->assertTrue($free->free);
        $this->assertSame(['hero', 'rail', 'basic_text'], $free->types);
        $this->assertSame([['type' => 'hero', 'pattern' => 'split']], $free->default);
        // 自由なページの名前の形でなければ無い
        $this->assertNull($registry->get('sample')->schema()->page('about'));
        $this->assertNull($registry->get('sample')->schema()->page('page-01K8Z3X5Y7A9B1C3D5E7F9G1H3'));

        // 書かないテーマは核の汎用の区画で
        $plain = $sample;
        unset($plain['pages']['page']);
        $registry->register(new ThemeDefinition('plain', '雛形なし', 2, 'plain', 'x.css', manifest: $this->manifest((string) json_encode($plain))));
        $core = $registry->get('plain')->schema()->page('page-01k8z3x5y7a9b1c3d5e7f9g1h3');
        $this->assertNotNull($core);
        $this->assertSame(['basic_text', 'basic_image_text', 'basic_image', 'basic_button', 'basic_products',
            'basic_testimonials', 'basic_faq', 'basic_features', 'basic_cta', 'basic_gallery', 'basic_product', 'basic_entries', 'basic_story',
            'basic_numbers', 'basic_logos', 'basic_steps', 'basic_table', 'basic_pricing', 'basic_spacer', 'basic_html', 'basic_richtext'], $core->types);
        $this->assertNotNull($registry->get('plain')->schema()->section('basic_image_text'));

        $broken = [
            'basic_ のセクションを宣言した' => ['sections' => $sample['sections'] + ['basic_text' => ['label' => '横取り', 'fields' => ['x' => ['type' => 'text', 'label' => 'x']]]]] + $sample,
            '雛形に宣言の無い種類' => ['pages' => ['page' => ['types' => ['nope']]] + $sample['pages']] + $sample,
            '雛形に知らない書き方' => ['pages' => ['page' => ['types' => ['hero'], 'slug' => 'x']] + $sample['pages']] + $sample,
            '知らないページ' => ['pages' => ['about' => ['types' => ['hero']]] + $sample['pages']] + $sample,
        ];

        foreach ($broken as $case => $manifest) {
            try {
                $registry->register(new ThemeDefinition('broken', '壊れた', 2, 'broken', 'x.css', manifest: $this->manifest((string) json_encode($manifest))));
                $this->fail("登録してしまった: {$case}");
            } catch (InvalidArgumentException) {
                $this->assertFalse($registry->has('broken'), $case);
            }
        }
    }

    /**
     * 区画の見本（example・example_blocks）: 店の人の値と同じ決まりで確かめ、合わなければ登録で止める。
     * 写真・選んだ商品・カテゴリは見本に書けない（見本で店のデータを勝手に選ばない）
     */
    public function test_section_examples_are_checked_when_the_theme_is_registered(): void
    {
        $sample = json_decode((string) file_get_contents(dirname(__DIR__).'/Support/Themes/sample/theme.json'), true);
        $registry = new ThemeRegistry;
        $registry->register(new ThemeDefinition('sample', '見本', 2, 'theme-sample', 'x.css', manifest: $this->manifest((string) json_encode($sample))));
        $sections = $registry->get('sample')->schema()->sections;
        $this->assertSame(['heading' => '見出しを入れてください', 'body' => 'ここに文を入れてください。', 'image' => null, 'link' => '/products'], $sections['hero']->example['values'] ?? null);
        $this->assertSame([['type' => 'button', 'values' => ['label' => 'くわしく見る', 'link' => '/products']]], $sections['hero']->exampleBlocks);
        $this->assertSame('dark', $sections['features']->example['items'][1]['tone'] ?? null);
        // 書かない種類は今まで通り空
        $this->assertNull($sections['shelves']->example);

        $with = static function (string $section, array $declaration) use ($sample): array {
            $sample['sections'][$section] = $declaration + $sample['sections'][$section];

            return $sample;
        };
        $broken = [
            '欄の形が違う' => [$with('hero', ['example' => ['heading' => ['見出し']]]), 'sections.hero.example.heading'],
            '文が長すぎる' => [$with('hero', ['example' => ['heading' => str_repeat('あ', 61)]]), 'sections.hero.example.heading'],
            'リンクの形が違う' => [$with('hero', ['example' => ['link' => 'javascript:alert(1)']]), 'sections.hero.example.link'],
            '写真に値' => [$with('hero', ['example' => ['image' => '01k8z3x5y7a9b1c3d5e7f9g1h3']]), 'sections.hero.example.image'],
            'ブロックの写真に値' => [$with('hero', ['example_blocks' => [['type' => 'photo', 'values' => ['image' => '01k8z3x5y7a9b1c3d5e7f9g1h3']]]]), 'sections.hero.example_blocks.0.values.image'],
            '選んだ商品' => [$with('rail', ['example' => ['products' => ['source' => 'pick', 'ids' => ['01k8z3x5y7a9b1c3d5e7f9g1h3']]]]), 'sections.rail.example.products'],
            '知らない欄' => [$with('hero', ['example' => ['subtitle' => 'x']]), 'sections.hero.example.subtitle'],
            '知らないブロック' => [$with('hero', ['example_blocks' => [['type' => 'video', 'values' => []]]]), 'sections.hero.example_blocks.0.type'],
            'ブロックの数の上限' => [$with('hero', ['example_blocks' => array_fill(0, 4, ['type' => 'button', 'values' => []])]), 'sections.hero.example_blocks'],
            '繰り返しの数の上限' => [$with('features', ['example' => array_fill(0, 4, ['title' => 'x'])]), 'sections.features.example.items'],
            '選択肢に無い値' => [$with('features', ['example' => ['items' => [['tone' => 'neon']]]]), 'sections.features.example.items.0.tone'],
            'ブロックの無いセクションに example_blocks' => [$with('rail', ['example_blocks' => []]), 'sections.rail.example_blocks'],
        ];

        foreach ($broken as $case => [$manifest, $path]) {
            try {
                $registry->register(new ThemeDefinition('broken', '壊れた', 2, 'broken', 'x.css', manifest: $this->manifest((string) json_encode($manifest))));
                $this->fail("登録してしまった: {$case}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("theme.json の {$path}:", $e->getMessage(), $case);
                $this->assertFalse($registry->has('broken'), $case);
            }
        }
    }

    private function manifest(string $json): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'theme');
        file_put_contents($path, $json);
        $this->files[] = $path;

        return $path;
    }
}
