<?php

declare(strict_types=1);

namespace App\Core\Shop\Events;

/**
 * 店の送料の決まりが変わった（ADR 0049 の 4。送料も定期便の約束に含める）。送料を変えるのと同じトランザクションの中で出す。
 * App は約束した送料で続く申し込み（定期便など）の知らせを、確定の後に決める
 */
final readonly class ShippingPolicyChanged
{
    public function __construct(
        public string $actor,
    ) {}
}
