<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use RuntimeException;

/**
 * 注文を確定できない（お客さんに見せる理由つき）。changed なら確認の画面に戻して見直してもらう、そうでなければカートへ。
 * limitedBy は回数の上限で断った時の決まりの名前（UnpaidOrderLimits::RULE_*。確定の経路がロールバックの後に断った回数を数える）
 */
final class OrderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $changed = false, public readonly ?string $limitedBy = null)
    {
        parent::__construct($message);
    }
}
