<?php

declare(strict_types=1);

namespace App\Core\Discount\Services;

/**
 * 1 つの注文に効かせる値引き（ADR 0065。1 つの注文に値引きは 1 つ）。
 * applied は効かせるクーポンか自動の割引の答え（無ければ null。使える答えだけ）、couponNotice は入れたコードを使わない理由（使えない・自動の割引の方が得）、
 * hint は次に届く自動の割引の一言（「あと 1 点で「まとめ買い 10%」」。無ければ null）
 */
final readonly class DiscountChoice
{
    public function __construct(
        public ?CouponOutcome $applied,
        public ?string $couponNotice = null,
        public ?string $hint = null,
    ) {}
}
