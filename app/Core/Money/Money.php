<?php

declare(strict_types=1);

namespace App\Core\Money;

/**
 * 金額の見せ方（ショップ・メール・管理画面で同じ形）。金額は整数（円）。円は半角の「¥」と 3 桁ごとのカンマ。
 * 円でない通貨は「1,234 USD」の形（まだ円だけを扱うが、取り違えて ¥ を付けない）
 */
final class Money
{
    public static function format(int $amount, string $currency = 'JPY'): string
    {
        // 負の金額は符号を前に（-¥550。台帳の残高や返金で使う）
        $sign = $amount < 0 ? '-' : '';

        return strtoupper($currency) === 'JPY' ? $sign.'¥'.number_format(abs($amount)) : $sign.number_format(abs($amount)).' '.strtoupper($currency);
    }
}
