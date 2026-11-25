<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed;

/**
 * フィードの 1 行（種類のある商品は種類ごとに 1 行）。行き先に関係ない形で持ち、書く時に FeedWriter が行き先の言葉にする。
 * 文は XML に書けない文字を外し、長さを各社の上限に収めたもの（FeedBuilder が作る）
 */
final readonly class FeedItem
{
    /**
     * @param  list<string>  $additionalImageLinks  2 枚目からの写真（10 枚まで）
     * @param  array<string, string>  $attributes  色・サイズでない軸（軸の名前 => 値）。Meta にだけ出す
     * @param  ?string  $availabilityDate  予約の商品の発売日（Y-m-d。ADR 0073）。予約でなければ null
     */
    public function __construct(
        public string $id,
        public ?string $itemGroupId,
        public string $title,
        public string $description,
        public string $link,
        public string $imageLink,
        public array $additionalImageLinks,
        public bool $inStock,
        public int $price,
        public string $currency,
        public ?string $brand,
        public string $condition,
        public ?string $mpn,
        public ?string $color,
        public ?string $size,
        public ?string $googleProductCategory,
        public array $attributes = [],
        public ?string $availabilityDate = null,
    ) {}

    /**
     * 発売日の形（「2026-11-20T00:00+09:00」。ISO 8601。日本の暦の 0 時）。予約でなければ null
     */
    public function availabilityDateText(): ?string
    {
        return $this->availabilityDate === null ? null : $this->availabilityDate.'T00:00+09:00';
    }

    /**
     * 価格の形（「5400 JPY」。金額は通貨の整数のまま。核の金額の決まりと同じ）
     */
    public function priceText(): string
    {
        return $this->price.' '.strtoupper($this->currency);
    }
}
