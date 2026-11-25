<?php

declare(strict_types=1);

namespace App\Core\Money;

use InvalidArgumentException;

/**
 * 金額を重みの割合で配る（按分）。端数は余りの大きい順に 1 円ずつ（最大剰余法。ADR 0054 の 7 と同じ決まり）。
 * 金額は整数（円）。クーポンの値引き・ポイント・送料・返品の精算が、どれもこの計算を使う。
 *
 * 前は値引き（`CouponService::allocate`）と返品の精算（`ReturnSettlement::split`）が同じ計算を別々に持ち、
 * **負の額の扱いだけが違っていた**（値引きの側は `$amount === 0` だけを 0 にしていたので、負の額が検査を
 * すり抜けて負の按分を計算した）。1 つに集めるときに安全な側（負の額は全部 0）へ揃えた（2026-11-18、ADR 0107）。
 *
 * 重みに負を混ぜてはいけない（配った額も負になる）。呼ぶ側が明細の金額など 0 以上の値を渡す。
 */
final class Allocation
{
    /**
     * @template K of array-key
     *
     * @param  int  $amount  配る額（0 以下なら全部 0。重みの合計を越えると例外）
     * @param  array<K, int>  $weights  重み（明細の金額・税率ごとの小計など）
     * @return array<K, int> 配った額（合計はちょうど $amount。鍵と並びは $weights のまま）
     */
    public static function byWeight(int $amount, array $weights): array
    {
        $total = array_sum($weights);

        if ($amount <= 0 || $total <= 0) {
            return array_map(static fn (): int => 0, $weights);
        }

        if ($amount > $total) {
            throw new InvalidArgumentException('配る額が重みの合計を越えている');
        }

        $shares = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $shares[$key] = intdiv($amount * $weight, $total);
            $remainders[$key] = ($amount * $weight) % $total;
        }

        $left = $amount - array_sum($shares);
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($left === 0) {
                break;
            }

            $shares[$key]++;
            $left--;
        }

        return $shares;
    }
}
