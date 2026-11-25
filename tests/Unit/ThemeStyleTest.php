<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Section\InvalidSectionSchemaException;
use App\Core\Theme\StyleColors;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use App\Core\Theme\ThemeStyle;
use App\Core\Theme\ThemeStyleSheet;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * テーマの見た目の宣言（theme.json の style）と店の値の確かめ、主な色から計算する色と CSS（ADR 0097）。
 * 知らない書き方は登録しない。CSS に出るのは確かめた #rrggbb と宣言の名前だけ
 */
final class ThemeStyleTest extends TestCase
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

    public function test_a_valid_declaration_is_read_with_the_first_choices_as_default(): void
    {
        $style = ThemeStyle::fromManifest(self::declaration());

        $this->assertSame(['mono', 'indigo'], array_keys($style->palettes));
        $this->assertSame('#283593', $style->palettes['indigo']['colors']['accent']);
        // 大文字の色は小文字にそろえる
        $this->assertSame('#e8ebf5', $style->palettes['indigo']['colors']['sage-band']);
        $this->assertTrue($style->accent);
        $this->assertSame(['palette' => 'mono', 'accent' => null, 'font' => 'gothic', 'radius' => 'square'], $style->defaults());
        $this->assertSame(['mono', 'indigo'], array_column($style->toArray()['palettes'], 'name'));

        // 字と角は書かなくてよい（選べないだけ）。主な色は書かなければ自由にしない
        $plain = ThemeStyle::fromManifest(array_intersect_key(self::declaration(), ['tokens' => true, 'palettes' => true]));
        $this->assertFalse($plain->accent);
        $this->assertSame(['palette' => 'mono', 'accent' => null, 'font' => null, 'radius' => null], $plain->defaults());
    }

    public function test_a_broken_declaration_is_refused_with_its_path(): void
    {
        $palette = static fn (array $colors): array => ['label' => '組', 'colors' => $colors + ['canvas' => '#ffffff', 'ink' => '#000000', 'accent' => '#283593']];
        $base = self::declaration();
        $cases = [
            'style.palettes.mono.colors.ink' => ['palettes' => ['mono' => $palette(['ink' => '#fff']), 'b' => $palette([])]],
            'style.palettes.mono.colors.accent' => ['palettes' => ['mono' => $palette(['accent' => 'red']), 'b' => $palette([])]],
            // 16 進でない・後ろに CSS を続ける・閉じかっこ
            'style.palettes.mono.colors.band' => ['palettes' => ['mono' => $palette(['band' => '#12345g']), 'b' => $palette([])]],
            'style.palettes.x.colors.band' => ['palettes' => ['x' => $palette(['band' => '#123456;}body{x:y']), 'b' => $palette([])]],
            // 知らない色の名前（核の名前でも tokens でもない）・計算する色の名前
            'style.palettes.mono.colors.sage-band' => ['palettes' => ['mono' => $palette(['sage-band' => '#ffffff']), 'b' => $palette([])]],
            'style.palettes.mono.colors.on-accent' => ['palettes' => ['mono' => $palette(['on-accent' => '#ffffff']), 'b' => $palette([])]],
            // 組に要る色が無い
            'style.palettes.mono.colors' => ['palettes' => ['mono' => ['label' => '組', 'colors' => ['canvas' => '#ffffff', 'ink' => '#000000']], 'b' => $palette([])]],
            // 名前の形（大文字・数字で始まる・記号）
            'style.palettes.Mono' => ['palettes' => ['Mono' => $palette([]), 'b' => $palette([])]],
            'style.palettes.1x' => ['palettes' => ['1x' => $palette([]), 'b' => $palette([])]],
            'style.fonts.a"b' => ['fonts' => ['a"b' => ['label' => '字']]] + $base,
            'style.radius.x]' => ['radius' => ['x]' => ['label' => '角']]] + $base,
            'style.tokens.0' => ['tokens' => ['Sage']] + $base,
            'style.tokens.1' => ['tokens' => ['sage-band', 'accent']] + $base,
            // 数の上限（組は 2〜6、字は 4、角は 3、足す色は 8）
            'style.palettes' => ['palettes' => ['only' => $palette([])]],
            'style.fonts' => ['fonts' => array_fill_keys(['a', 'b', 'c', 'd', 'e'], ['label' => '字'])] + $base,
            'style.radius' => ['radius' => array_fill_keys(['a', 'b', 'c', 'd'], ['label' => '角'])] + $base,
            'style.tokens' => ['tokens' => ['t-a', 't-b', 't-c', 't-d', 't-e', 't-f', 't-g', 't-h', 't-i']] + $base,
            // 知らない書き方・形の違う値
            'style' => ['palettes' => $base['palettes'], 'css' => ':root{}'],
            'style.accent' => ['accent' => 'yes'] + $base,
            'style.palettes.mono.label' => ['palettes' => ['mono' => ['label' => str_repeat('あ', 21), 'colors' => $palette([])['colors']], 'b' => $palette([])]],
        ];

        foreach ($cases as $path => $declaration) {
            try {
                ThemeStyle::fromManifest($declaration);
                $this->fail("断るはず: {$path}");
            } catch (InvalidSectionSchemaException $e) {
                $this->assertSame($path, $e->path, $path.' / '.$e->getMessage());
            }
        }

        // 6 つまでは通る
        $six = array_combine(['a', 'b', 'c', 'd', 'e', 'f'], array_fill(0, 6, $palette([])));
        $this->assertCount(6, ThemeStyle::fromManifest(['palettes' => $six])->palettes);
        $this->expectException(InvalidSectionSchemaException::class);
        ThemeStyle::fromManifest(['palettes' => $six + ['g' => $palette([])]]);
    }

    public function test_the_registry_refuses_a_theme_with_a_broken_style(): void
    {
        $sample = json_decode((string) file_get_contents(base_path('tests/Support/Themes/sample/theme.json')), true);
        $this->assertIsArray($sample);
        $registry = new ThemeRegistry;
        $registry->register(new ThemeDefinition('styled', '見た目つき', 2, 'styled', 'x.css', manifest: $this->manifest((string) json_encode($sample))));
        $this->assertNotNull($registry->get('styled')->style);
        $this->assertSame('indigo', array_keys($registry->get('styled')->style->palettes)[1]);

        // 書かないテーマは何も選べない
        unset($sample['style']);
        $registry->register(new ThemeDefinition('unstyled', '見た目なし', 2, 'unstyled', 'x.css', manifest: $this->manifest((string) json_encode($sample))));
        $this->assertNull($registry->get('unstyled')->style);

        $sample['style'] = ['palettes' => ['a' => ['label' => 'A', 'colors' => ['canvas' => '#fff', 'ink' => '#000000', 'accent' => '#000000']], 'b' => ['label' => 'B', 'colors' => ['canvas' => '#ffffff', 'ink' => '#000000', 'accent' => '#000000']]]];

        try {
            $registry->register(new ThemeDefinition('broken', '壊れた', 2, 'broken', 'x.css', manifest: $this->manifest((string) json_encode($sample))));
            $this->fail('壊れた見た目のテーマを登録した');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('theme.json の style.palettes.a.colors.canvas:', $e->getMessage());
            $this->assertFalse($registry->has('broken'));
        }
    }

    public function test_shop_values_are_checked_against_the_declaration(): void
    {
        $style = ThemeStyle::fromManifest(self::declaration());

        $this->assertSame(['palette' => 'indigo', 'accent' => '#1e3a8a', 'font' => 'mincho', 'radius' => 'soft'],
            $style->validate(['palette' => 'indigo', 'accent' => '#1E3A8A', 'font' => 'mincho', 'radius' => 'soft']));
        $this->assertSame(['palette' => 'mono', 'accent' => null, 'font' => 'gothic', 'radius' => 'square'],
            $style->validate(['palette' => 'mono', 'accent' => null, 'font' => 'gothic', 'radius' => 'square']));

        $refused = [
            'style.palette' => ['palette' => 'nope', 'font' => 'gothic', 'radius' => 'square'],
            'style.accent' => ['palette' => 'mono', 'accent' => '#fff', 'font' => 'gothic', 'radius' => 'square'],
            'style.font' => ['palette' => 'mono', 'font' => 'serif', 'radius' => 'square'],
            'style.radius' => ['palette' => 'mono', 'font' => 'gothic', 'radius' => 'round'],
            'style' => ['palette' => 'mono', 'font' => 'gothic', 'radius' => 'square', 'css' => 'x'],
        ];
        foreach (['red', '#12345g', '#123456;}', '#123456</style>', ['#123456'], 123456, '#1234567'] as $i => $accent) {
            $refused["style.accent#{$i}"] = ['palette' => 'mono', 'accent' => $accent, 'font' => 'gothic', 'radius' => 'square'];
        }

        foreach ($refused as $key => $input) {
            try {
                $style->validate($input);
                $this->fail("断るはず: {$key}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey(explode('#', $key)[0], $e->errors(), $key);
            }
        }

        // 主な色を許さないテーマでは null だけ。字と角を宣言しないテーマでは null だけ
        $plain = ThemeStyle::fromManifest(array_intersect_key(self::declaration(), ['tokens' => true, 'palettes' => true]));
        $this->assertSame(['palette' => 'indigo', 'accent' => null, 'font' => null, 'radius' => null], $plain->validate(['palette' => 'indigo']));
        foreach (['accent' => '#123456', 'font' => 'gothic', 'radius' => 'square'] as $key => $value) {
            try {
                $plain->validate(['palette' => 'indigo', $key => $value]);
                $this->fail("断るはず: {$key}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey("style.{$key}", $e->errors());
            }
        }
    }

    public function test_names_gone_from_the_declaration_fall_back_to_the_default(): void
    {
        $style = ThemeStyle::fromManifest(self::declaration());

        $this->assertSame(['palette' => 'mono', 'accent' => null, 'font' => 'gothic', 'radius' => 'square'],
            $style->resolve(['palette' => 'gone', 'accent' => 'red', 'font' => 'gone', 'radius' => ['x']]));
        $this->assertSame(['palette' => 'indigo', 'accent' => '#1e3a8a', 'font' => 'mincho', 'radius' => 'soft'],
            $style->resolve(['palette' => 'indigo', 'accent' => '#1e3a8a', 'font' => 'mincho', 'radius' => 'soft']));
        $this->assertSame($style->defaults(), $style->resolve(null));
        // 主な色を許さなくなったテーマでは、しまってある主な色を使わない
        $plain = ThemeStyle::fromManifest(array_intersect_key(self::declaration(), ['tokens' => true, 'palettes' => true]));
        $this->assertNull($plain->resolve(['palette' => 'indigo', 'accent' => '#1e3a8a'])['accent']);
    }

    public function test_the_css_holds_only_checked_colors_in_a_fixed_shape(): void
    {
        $style = ThemeStyle::fromManifest(self::declaration());

        $sheet = new ThemeStyleSheet($style, $style->resolve(['palette' => 'indigo', 'accent' => '#1e3a8a']));
        $this->assertFalse($sheet->isDefault());
        $css = $sheet->css();
        $this->assertMatchesRegularExpression('/\A:root\{(--color-[a-z][a-z0-9-]*:#[0-9a-f]{6};)+\}\n\z/', $css);
        $this->assertStringContainsString('--color-canvas:#ffffff;', $css);
        $this->assertStringContainsString('--color-sage-band:#e8ebf5;', $css);
        $this->assertStringContainsString('--color-accent:#1e3a8a;--color-on-accent:#ffffff;--color-accent-hover:', $css);
        $this->assertSame(16, strlen($sheet->mark()));

        // 組の主な色のままでも、計算した色は書く。印は中身で変わる
        $palette = new ThemeStyleSheet($style, $style->resolve(['palette' => 'indigo']));
        $this->assertStringContainsString('--color-accent:#283593;--color-on-accent:#ffffff;', $palette->css());
        $this->assertNotSame($sheet->mark(), $palette->mark());
        $this->assertTrue((new ThemeStyleSheet($style, $style->defaults()))->isDefault());
    }

    public function test_the_text_on_the_accent_is_white_or_black_whichever_reads_better(): void
    {
        $this->assertSame(StyleColors::WHITE, StyleColors::onAccent('#283593'));
        $this->assertSame(StyleColors::WHITE, StyleColors::onAccent('#18181b'));
        $this->assertSame(StyleColors::BLACK, StyleColors::onAccent('#ffeb3b'));
        $this->assertSame(StyleColors::BLACK, StyleColors::onAccent('#ffffff'));
        // 境目の近く（相対輝度 0.179 前後で入れ替わる）
        $this->assertSame(StyleColors::WHITE, StyleColors::onAccent('#757575'));
        $this->assertSame(StyleColors::BLACK, StyleColors::onAccent('#777777'));

        // 押したときの色: 文字が白の色は暗く、黒の色は明るく（色相はそのまま）
        $this->assertLessThan(StyleColors::luminance('#283593'), StyleColors::luminance(StyleColors::hover('#283593')));
        $this->assertGreaterThan(StyleColors::luminance('#ffeb3b'), StyleColors::luminance(StyleColors::hover('#ffeb3b')));
        $this->assertMatchesRegularExpression('/\A#[0-9a-f]{6}\z/', StyleColors::hover('#000000'));
        $this->assertSame('#ffffff', StyleColors::hover('#ffffff'));

        $this->assertEqualsWithDelta(21.0, StyleColors::contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, StyleColors::contrast('#283593', '#283593'), 0.0001);
    }

    public function test_hard_to_read_colors_are_reported_at_the_boundary_but_not_refused(): void
    {
        // 主な色と地: 3 : 1 の境（#949494 は白の上で 3.03、#959595 は 2.99）
        $this->assertGreaterThanOrEqual(3.0, StyleColors::contrast('#949494', '#ffffff'));
        $this->assertLessThan(3.0, StyleColors::contrast('#959595', '#ffffff'));
        $this->assertSame([], StyleColors::warnings('#949494', '#ffffff'));
        $this->assertSame(['accent_on_canvas'], array_column(StyleColors::warnings('#959595', '#ffffff'), 'code'));
        $this->assertSame(['accent_on_canvas'], array_column(StyleColors::warnings('#fafafa', '#ffffff'), 'code'));

        // 主な色の上の文字は白か黒の高い方なので、どの色でも 4.5 : 1 を下回らない（いちばん低い所でも 4.58 : 1）
        foreach (['#757575', '#767676', '#777777', '#ff0000', '#00ff00', '#0000ff', '#808080'] as $accent) {
            $this->assertGreaterThanOrEqual(StyleColors::MIN_TEXT, StyleColors::contrast($accent, StyleColors::onAccent($accent)), $accent);
            $this->assertNotContains('text_on_accent', array_column(StyleColors::warnings($accent, '#000000'), 'code'), $accent);
        }

        // 知らせは保存を止めない（確かめは名前と形だけ）
        $style = ThemeStyle::fromManifest(self::declaration());
        $values = $style->validate(['palette' => 'mono', 'accent' => '#fafafa', 'font' => 'gothic', 'radius' => 'square']);
        $this->assertSame(['accent_on_canvas'], array_column((new ThemeStyleSheet($style, $values))->warnings(), 'code'));
    }

    /**
     * 公式テーマ 4 つ: 見た目を宣言し、最初の組はテーマの CSS の @theme と同じ色（何も選ばない店の見た目を変えない）。
     * どの組も文字が地の上で読める（4.5 : 1）・主な色の知らせが出ない。外枠と「準備中です」の画面は、テーマの CSS の後に核の部品を置き、<html> に字と角の名前を出す
     */
    public function test_the_official_themes_declare_palettes_whose_first_one_matches_their_css(): void
    {
        foreach (['default', 'kinari', 'fashion', 'botanica'] as $theme) {
            $manifest = json_decode((string) file_get_contents(base_path("plugins/theme-{$theme}/theme.json")), true);
            $this->assertIsArray($manifest, $theme);
            $style = ThemeStyle::fromManifest($manifest['style'] ?? null);
            $this->assertGreaterThanOrEqual(3, count($style->palettes), $theme);
            $this->assertTrue($style->accent, $theme);
            $this->assertNotSame([], $style->fonts, $theme);
            $this->assertNotSame([], $style->radius, $theme);

            $css = (string) file_get_contents(base_path("plugins/theme-{$theme}/resources/css/theme.css"));
            $this->assertSame(1, preg_match('/@theme\s*\{(.*?)\n\}/s', $css, $block), $theme);
            preg_match_all('/--color-([a-z][a-z0-9-]*):\s*(#[0-9a-f]{6});/', $block[1], $declared, PREG_SET_ORDER);
            $themeColors = array_column($declared, 2, 1);
            $first = $style->palettes[(string) array_key_first($style->palettes)]['colors'];
            $this->assertSame(array_intersect_key($themeColors, $first), $first, "{$theme}: 最初の組はテーマの CSS と同じ色");

            foreach ($style->palettes as $name => $palette) {
                $colors = $palette['colors'];
                foreach (['ink', 'ink-muted', 'ink-soft'] as $text) {
                    if (isset($colors[$text])) {
                        $this->assertGreaterThanOrEqual(4.5, StyleColors::contrast($colors[$text], $colors['canvas']), "{$theme}.{$name}.{$text}");
                    }
                }
                $this->assertSame([], StyleColors::warnings($colors['accent'], $colors['canvas']), "{$theme}.{$name}");
            }

            // 字と角の名前は、テーマの CSS が受ける（既定でない名前に [data-sugar-…] の規則がある）
            foreach (['font' => $style->fonts, 'radius' => $style->radius] as $kind => $choices) {
                foreach (array_slice(array_keys($choices), 1) as $choice) {
                    $this->assertStringContainsString(":root[data-sugar-{$kind}=\"{$choice}\"]", $css, "{$theme}: {$kind} {$choice}");
                }
            }

            foreach (['layout', 'password'] as $view) {
                $source = (string) file_get_contents(base_path("plugins/theme-{$theme}/resources/views/{$view}.blade.php"));
                $this->assertMatchesRegularExpression('/@vite\(\[\$theme->stylesheet[^\n]*\n(?:\s*\{\{--[^\n]*--\}\}\n)?\s*<x-storefront::theme-style \/>/', $source, "{$theme}/{$view}");
                $this->assertStringContainsString('data-sugar-font="{{ $themeStyle->font ?? \'\' }}" data-sugar-radius="{{ $themeStyle->radius ?? \'\' }}"', $source, "{$theme}/{$view}");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function declaration(): array
    {
        return [
            'tokens' => ['sage-band'],
            'palettes' => [
                'mono' => ['label' => '白と黒', 'colors' => ['canvas' => '#ffffff', 'band' => '#f6f6f7', 'ink' => '#18181b', 'accent' => '#18181b']],
                'indigo' => ['label' => '藍', 'colors' => ['canvas' => '#ffffff', 'band' => '#f3f5fa', 'ink' => '#1a1f36', 'accent' => '#283593', 'sage-band' => '#E8EBF5']],
            ],
            'accent' => true,
            'fonts' => ['gothic' => ['label' => 'ゴシック'], 'mincho' => ['label' => '明朝']],
            'radius' => ['square' => ['label' => '四角'], 'soft' => ['label' => '少し丸く']],
        ];
    }

    private function manifest(string $json): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'theme');
        file_put_contents($path, $json);
        $this->files[] = $path;

        return $path;
    }
}
