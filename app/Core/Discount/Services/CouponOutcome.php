<?php

declare(strict_types=1);

namespace App\Core\Discount\Services;

use App\Core\Discount\Models\Coupon;

/**
 * クーポンを照らした答え。使えなければ reason（お客さんに出す文）。discount は商品の値引きの額（正の円）、
 * shippingWaived は送料無料のクーポン。eligible は値引きの対象の行の鍵（割り振りに使う）
 */
final readonly class CouponOutcome
{
    /**
     * @param  list<string>  $eligible
     */
    private function __construct(
        public Coupon $coupon,
        public ?string $reason,
        public int $discount = 0,
        public bool $shippingWaived = false,
        public array $eligible = [],
    ) {}

    /**
     * @param  list<string>  $eligible
     */
    public static function applies(Coupon $coupon, int $discount, bool $shippingWaived, array $eligible): self
    {
        return new self($coupon, null, $discount, $shippingWaived, $eligible);
    }

    public static function rejected(Coupon $coupon, string $reason): self
    {
        return new self($coupon, $reason);
    }

    public function ok(): bool
    {
        return $this->reason === null;
    }
}
