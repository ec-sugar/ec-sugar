<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use InvalidArgumentException;

/**
 * App がショップの商品の画面に置く、開いて読む並び（よくある質問・レビューなど）。見出しと、項目（見出しと本文）の並びだけ。
 * HTML は持たない（描くのは核の部品 <x-storefront::product-panel>。本文は文字としてエスケープし、改行だけを生かす）。
 * rating は並びの上に出す平均の星と件数（レビュー。2026-11-04）。open は項目を最初から開いて出す（読ませたい並び。閉じることはできる）
 */
final readonly class ProductPanel
{
    public const int TITLE_MAX = 40;

    public const int ITEMS_MAX = 50;

    /**
     * @param  list<ProductPanelItem>  $items
     */
    public function __construct(
        public string $plugin,
        public string $title,
        public array $items,
        public ?ProductPanelRating $rating = null,
        public bool $open = false,
    ) {
        if ($title === '' || ! mb_check_encoding($title, 'UTF-8') || mb_strlen($title) > self::TITLE_MAX) {
            throw new InvalidArgumentException("App {$plugin} の商品の画面の見出しは 1〜".self::TITLE_MAX.' 文字');
        }

        // 項目の形（ProductPanelItem の並び）は型で決まる。ここでは数だけを見る
        if ($items === [] || count($items) > self::ITEMS_MAX) {
            throw new InvalidArgumentException("App {$plugin} の商品の画面の項目は 1〜".self::ITEMS_MAX.' 個');
        }
    }
}
