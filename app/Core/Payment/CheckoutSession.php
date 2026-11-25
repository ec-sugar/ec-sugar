<?php

declare(strict_types=1);

namespace App\Core\Payment;

use Carbon\CarbonImmutable;

/**
 * 決済代行が作った支払いの画面（ref は代行の ID、url はお客さんを送る先）
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $ref,
        public string $url,
        public int $amount,
        public CarbonImmutable $expiresAt,
    ) {}
}
