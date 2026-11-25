<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed;

/**
 * フィードの行き先（読みに来るサービス）。どちらも RSS 2.0 の XML（Google の g: の名前空間）で、在庫の言葉と項目の付け方だけが違う。
 * 決まりの出どころは README の「仕様の出どころ」（2026-11-04 に各社の公開の文書から取った）
 */
enum FeedFormat: string
{
    case Google = 'google';
    case Meta = 'meta';

    /**
     * 管理画面に出す名前（つなぐ先のサービスの名前）
     */
    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google ショッピング（Merchant Center）',
            self::Meta => 'Meta のカタログ（Facebook・Instagram）',
        };
    }

    /**
     * 在庫の言葉（Google は in_stock、Meta は「in stock」と空白で書く）。予約を受けている予約の商品（ADR 0073）はどちらも preorder
     */
    public function availability(bool $inStock, bool $preorder = false): string
    {
        if ($inStock && $preorder) {
            return 'preorder';
        }

        return match ($this) {
            self::Google => $inStock ? 'in_stock' : 'out_of_stock',
            self::Meta => $inStock ? 'in stock' : 'out of stock',
        };
    }

    /**
     * g: を付けずに書く項目（Meta の XML の見本は、追加の写真と色・サイズを g: なしで書く）
     */
    public function element(string $name): string
    {
        return $this === self::Meta && in_array($name, ['additional_image_link', 'color', 'size'], true) ? $name : "g:{$name}";
    }
}
