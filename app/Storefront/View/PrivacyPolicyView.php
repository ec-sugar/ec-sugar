<?php

declare(strict_types=1);

namespace App\Storefront\View;

use App\Storefront\Catalog\DescriptionBlock;

/**
 * プライバシーポリシー（テーマ API の一部。ADR 0094）。ショップの /privacy の画面 privacy に $privacy で渡す。
 * 本文は店の人が書いた素の文で、HTML ではない。核が決まった短い書き方を読んで、商品の説明と同じブロック（DescriptionBlock）にする。
 * 描くのは核の部品 <x-storefront::description-blocks :blocks="$privacy->blocks" :heading="2" />（文はどれも {{ }} で出る）
 *
 * 書き方（管理画面の「設定 › プライバシーポリシー」の案内と同じ）:
 * - 空の行で段落を分ける。段落の中の改行はそのまま（white-space: pre-line）
 * - 「# 」か「## 」で始まる行は大きい見出し、「### 」で始まる行は小さい見出し
 * - 「- 」「* 」「・」で始まる行は箇条書き（続く行は同じ並び）
 * - 「1. 」などの番号は書いたままの文として出す（番号つきの並びにすると、途中で切れた時に番号が 1 から振り直されるため）
 * ほかの記号（リンク・太字など）は読まず、書いたままの文字で出す
 */
final readonly class PrivacyPolicyView
{
    public const string TITLE = 'プライバシーポリシー';

    /**
     * @param  list<DescriptionBlock>  $blocks
     */
    public function __construct(
        public string $title,
        public string $url,
        public array $blocks,
    ) {}

    public static function fromText(string $text, string $url): self
    {
        return new self(self::TITLE, $url, self::blocks($text));
    }

    /**
     * @return list<DescriptionBlock>
     */
    public static function blocks(string $text): array
    {
        $blocks = [];
        /** @var list<string> $paragraph */
        $paragraph = [];
        /** @var list<string> $items */
        $items = [];

        $flush = static function () use (&$blocks, &$paragraph, &$items): void {
            if ($paragraph !== []) {
                $blocks[] = ['type' => 'paragraph', 'text' => implode("\n", $paragraph)];
                $paragraph = [];
            }

            if ($items !== []) {
                $blocks[] = ['type' => 'list', 'style' => 'bullet', 'items' => $items];
                $items = [];
            }
        };

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $line = rtrim($line);

            if (trim($line) === '') {
                $flush();

                continue;
            }

            if (preg_match('/\A\s*(#{1,3})\s+(\S.*)\z/u', $line, $heading) === 1) {
                $flush();
                $blocks[] = ['type' => 'heading', 'level' => strlen($heading[1]) === 3 ? 3 : 2, 'text' => trim($heading[2])];

                continue;
            }

            if (preg_match('/\A\s*(?:[-*]\s+|・\s*)(\S.*)\z/u', $line, $item) === 1) {
                if ($paragraph !== []) {
                    $flush();
                }
                $items[] = trim($item[1]);

                continue;
            }

            if ($items !== []) {
                $flush();
            }
            $paragraph[] = $line;
        }

        $flush();

        return array_values(array_filter(array_map(static fn (array $block): ?DescriptionBlock => DescriptionBlock::from($block, []), $blocks)));
    }
}
