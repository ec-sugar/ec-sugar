<?php

declare(strict_types=1);

namespace App\Core\Theme;

use InvalidArgumentException;

/**
 * 主な色から計算する色と、読みやすさ（コントラスト）の計算（ADR 0097）。値は #rrggbb（小文字）だけを受けて返す。
 *
 * - ボタンの文字の色（on-accent）: 白か黒の、主な色に対してコントラストが高い方（同じなら白）
 * - 押したときの色（accent-hover）: OKLCH の明るさを 0.08 動かす（文字が白になる暗めの色は暗く、文字が黒になる明るい色は明るく）。
 *   色相と彩度はそのまま（OKLab の a・b を変えない）。sRGB の外に出た分は 0〜1 に詰める
 * - コントラストは WCAG 2 の相対輝度の比（1〜21）
 * - 知らせ（保存は止めない）: 主な色と地（canvas）が 3 : 1 に満たない（ボタンの縁が見えにくい）・主な色の上の文字が 4.5 : 1 に満たない。
 *   後者は白か黒の高い方を選ぶので、ふつうの色では起きない（どの色でも高い方は 4.58 : 1 以上）。計算を変えたときの見張りとして残す
 */
final class StyleColors
{
    public const float MIN_EDGE = 3.0;

    public const float MIN_TEXT = 4.5;

    /** 押したときの色の明るさの差（OKLCH の L） */
    public const float HOVER_STEP = 0.08;

    public const string WHITE = '#ffffff';

    public const string BLACK = '#000000';

    public static function onAccent(string $accent): string
    {
        return self::contrast($accent, self::WHITE) >= self::contrast($accent, self::BLACK) ? self::WHITE : self::BLACK;
    }

    public static function hover(string $accent): string
    {
        [$l, $a, $b] = self::toOklab($accent);
        $l = self::onAccent($accent) === self::WHITE ? max(0.0, $l - self::HOVER_STEP) : min(1.0, $l + self::HOVER_STEP);

        return self::fromOklab($l, $a, $b);
    }

    /**
     * 2 つの色のコントラストの比（1〜21）
     */
    public static function contrast(string $one, string $two): float
    {
        $a = self::luminance($one);
        $b = self::luminance($two);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * 読みにくいかもしれない組み合わせの知らせ（止めない）
     *
     * @return list<array{code: string, ratio: float, message: string}>
     */
    public static function warnings(string $accent, string $canvas): array
    {
        $out = [];
        $edge = self::contrast($accent, $canvas);
        if ($edge < self::MIN_EDGE) {
            $out[] = ['code' => 'accent_on_canvas', 'ratio' => round($edge, 2),
                'message' => '主な色が背景の色に近く、ボタンやリンクが見えにくいかもしれません（コントラスト '.number_format($edge, 1).' : 1。3 : 1 以上がおすすめです）。'];
        }

        $text = self::contrast($accent, self::onAccent($accent));
        if ($text < self::MIN_TEXT) {
            $out[] = ['code' => 'text_on_accent', 'ratio' => round($text, 2),
                'message' => '主な色の上の文字が読みにくいかもしれません（コントラスト '.number_format($text, 1).' : 1。4.5 : 1 以上がおすすめです）。'];
        }

        return $out;
    }

    /**
     * WCAG 2 の相対輝度
     */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(self::linear(...), self::channels($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * @return array{float, float, float} 0〜1 の sRGB
     */
    private static function channels(string $hex): array
    {
        if (preg_match('/\A#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})\z/', $hex, $m) !== 1) {
            throw new InvalidArgumentException('色は #rrggbb（小文字）で渡す');
        }

        return [hexdec($m[1]) / 255, hexdec($m[2]) / 255, hexdec($m[3]) / 255];
    }

    private static function linear(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function gamma(float $c): float
    {
        $c = max(0.0, min(1.0, $c));

        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055;
    }

    /**
     * @return array{float, float, float} OKLab の L・a・b
     */
    private static function toOklab(string $hex): array
    {
        [$r, $g, $b] = array_map(self::linear(...), self::channels($hex));
        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    private static function fromOklab(float $lightness, float $a, float $b): string
    {
        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;
        $rgb = [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];

        return '#'.implode('', array_map(static fn (float $c): string => sprintf('%02x', (int) round(self::gamma($c) * 255)), $rgb));
    }
}
