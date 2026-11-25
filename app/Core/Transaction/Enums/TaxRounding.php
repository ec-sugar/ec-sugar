<?php

declare(strict_types=1);

namespace App\Core\Transaction\Enums;

/**
 * 消費税の端数処理。インボイス制度に合わせ、税率ごとに 1 回だけ丸める（明細ごとには丸めない）。
 */
enum TaxRounding: string
{
    case Floor = 'floor';  // 切り捨て（既定）
    case Ceil = 'ceil';    // 切り上げ
    case HalfUp = 'half';  // 四捨五入

    public function divide(int $numerator, int $denominator): int
    {
        // 負の額（税率グループの合計が負）は Totals で弾くので、ここでは非負だけ扱う
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        return match ($this) {
            self::Floor => $quotient,
            self::Ceil => $remainder > 0 ? $quotient + 1 : $quotient,
            self::HalfUp => $remainder * 2 >= $denominator ? $quotient + 1 : $quotient,
        };
    }
}
