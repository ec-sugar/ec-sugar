<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 管理画面のトークン（resources/admin/admin.css）で、文字と地の色の組み合わせが読めること（WCAG 2.1 AA、4.5:1）。
 * ライトとダークの両方。色を変えて基準を割ったら落ちる。
 */
final class AdminContrastTest extends TestCase
{
    // [文字, 地]。画面で実際に重ねている組み合わせ
    private const array PAIRS = [
        ['ink', 'canvas'], ['ink', 'surface'], ['ink', 'subtle'],
        ['ink-muted', 'surface'], ['ink-muted', 'subtle'], ['ink-muted', 'canvas'],
        ['ink-soft', 'surface'], ['ink-soft', 'canvas'], ['ink-soft', 'subtle'], ['ink-soft', 'hover'],
        // メニューの選択中（白い板の上の灰の面に、太字の文字と藍のアイコン）
        ['ink', 'selected'], ['accent', 'selected'],
        ['accent', 'surface'], ['accent', 'canvas'], ['accent', 'accent-soft'], ['on-accent', 'accent'], ['on-accent', 'accent-hover'],
        ['surface', 'ink'],
        // ヘルプの検索の結果で、当たった言葉の印（薄い藍の面に本文の色）
        ['ink', 'accent-soft'],
        ['on-brand', 'brand'], ['on-brand-soft', 'brand'],
        ['danger', 'surface'], ['danger', 'danger-soft'], ['danger-ink', 'danger-soft'],
        ['ok-ink', 'ok-soft'], ['warn-ink', 'warn-soft'], ['info-ink', 'info-soft'],
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function schemes(): array
    {
        return ['ライト' => ['light'], 'ダーク' => ['dark']];
    }

    #[DataProvider('schemes')]
    public function test_text_on_every_surface_meets_wcag_aa(string $scheme): void
    {
        $tokens = self::tokens($scheme);

        foreach (self::PAIRS as [$text, $ground]) {
            $this->assertArrayHasKey($text, $tokens, "{$scheme}: --color-{$text} が無い");
            $this->assertArrayHasKey($ground, $tokens, "{$scheme}: --color-{$ground} が無い");

            $ratio = self::contrast($tokens[$text], $tokens[$ground]);
            $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('%s: %s on %s = %.2f:1', $scheme, $text, $ground, $ratio));
        }
    }

    /**
     * @return array<string, string> トークン名（--color- を除く）→ #rrggbb。ダークはライトの上に上書き
     */
    private static function tokens(string $scheme): array
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/admin/admin.css');
        $light = self::colors((string) preg_replace('/@media \(prefers-color-scheme: dark\).*\z/s', '', $css));

        if ($scheme === 'light') {
            return $light;
        }

        preg_match('/@media \(prefers-color-scheme: dark\)\s*\{\s*:root\s*\{(.*?)\}\s*\}/s', $css, $dark);

        return self::colors($dark[1] ?? '') + $light;
    }

    /**
     * @return array<string, string>
     */
    private static function colors(string $css): array
    {
        preg_match_all('/--color-([a-z-]+):\s*(#[0-9a-f]{6})\s*;/i', $css, $matches, PREG_SET_ORDER);

        $colors = [];

        foreach ($matches as [, $name, $hex]) {
            $colors[$name] = strtolower($hex);
        }

        return $colors;
    }

    private static function contrast(string $a, string $b): float
    {
        [$lighter, $darker] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channel = static function (string $part): float {
            $c = hexdec($part) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));
    }
}
