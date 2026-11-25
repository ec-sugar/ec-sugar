<?php

declare(strict_types=1);

namespace App\Core\Shop;

/**
 * 1 つの便の送料の決め方: 額と、小計がこの金額以上なら無料。金額は円の整数（税込み）。
 * 送料の表（ADR 0064）からお届け先の都道府県と商品の方法で決めたもの（ShippingCalculator::policyFor）で、定期便の約束（ADR 0049 の 4）に持つ形。
 * method は配送の方法の名前（約束として持つ値には無い）
 */
final readonly class ShippingPolicy
{
    public function __construct(
        public int $fee = 0,
        public ?int $freeOver = null,
        public ?string $method = null,
    ) {}

    public function feeFor(int $subtotal): int
    {
        return $this->freeOver !== null && $subtotal >= $this->freeOver ? 0 : $this->fee;
    }

    /**
     * あといくらで送料無料か（無料にならない店・もう無料なら null）
     */
    public function remainingForFree(int $subtotal): ?int
    {
        return $this->fee > 0 && $this->freeOver !== null && $subtotal < $this->freeOver ? $this->freeOver - $subtotal : null;
    }
}
