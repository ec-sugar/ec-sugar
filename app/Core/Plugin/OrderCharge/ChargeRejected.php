<?php

declare(strict_types=1);

namespace App\Core\Plugin\OrderCharge;

use RuntimeException;

/**
 * App の料金の欄の値を断った（ADR 0078）。errors は欄の鍵 => 画面に出す理由（注文の入力の欄の下に出る）
 */
final class ChargeRejected extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
