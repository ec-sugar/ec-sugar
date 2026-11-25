<?php

declare(strict_types=1);

namespace App\Core\Plugin\OrderCharge;

/**
 * App の料金の口（OrderChargeHandler）に渡すカート（ADR 0078）。今買える行の商品と数だけ（値段・お客さんの情報は渡さない）。
 * gift は贈り物として送る注文か（ADR 0055）
 */
final readonly class ChargeCart
{
    /**
     * @param  list<array{listingId: string, title: string, quantity: int}>  $items
     */
    public function __construct(
        public array $items,
        public bool $gift = false,
    ) {}

    /**
     * @return list<string> 商品の ID（重ねない）
     */
    public function listingIds(): array
    {
        return array_values(array_unique(array_column($this->items, 'listingId')));
    }

    /**
     * 品の数の合計
     */
    public function quantity(): int
    {
        return array_sum(array_column($this->items, 'quantity'));
    }
}
