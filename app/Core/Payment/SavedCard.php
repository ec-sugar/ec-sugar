<?php

declare(strict_types=1);

namespace App\Core\Payment;

/**
 * 支払いで預けたカード（決済代行の答えを核の形に）。カードの番号は無い
 */
final readonly class SavedCard
{
    public function __construct(
        public string $customerRef,
        public string $methodRef,
        public ?string $brand,
        public ?string $last4,
        public ?int $expMonth,
        public ?int $expYear,
        // カードの指紋（Stripe の card.fingerprint。同じアカウントの中で同じカードなら同じ値。番号ではない。ADR 0080）
        public ?string $fingerprint = null,
    ) {}
}
