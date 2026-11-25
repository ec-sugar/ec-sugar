<?php

declare(strict_types=1);

namespace App\Core\Transaction;

use App\Core\Transaction\Enums\TaxRounding;
use InvalidArgumentException;

/**
 * 取引の合計と、税率ごとの内訳（インボイスの記載事項: 税率ごとの対価の額と消費税額）。
 *
 * 消費税は税率ごとに合計してから 1 回だけ丸める。明細ごとに丸めて足さない。
 * 内税なら 税額 = 額 × r / (1 + r)、外税なら 税額 = 額 × r。
 */
final readonly class Totals
{
    public const int MAX_LINES = 500;

    /**
     * @param  array<int, array{base: int, tax: int}>  $byRate  税率（basis point）=> 税抜の対価と税額
     */
    public function __construct(
        public int $subtotal,
        public int $tax,
        public int $total,
        public array $byRate,
    ) {}

    /**
     * @param  list<LineDraft>  $lines
     */
    public static function of(array $lines, bool $pricesIncludeTax, TaxRounding $rounding): self
    {
        if (count($lines) > self::MAX_LINES) {
            throw new InvalidArgumentException('明細は '.self::MAX_LINES.' 行までです');
        }

        /** @var array<int, int> $grouped */
        $grouped = [];

        foreach ($lines as $line) {
            $grouped[$line->taxRateBp] = ($grouped[$line->taxRateBp] ?? 0) + $line->amount;
        }

        return self::ofAmounts($grouped, $pricesIncludeTax, $rounding);
    }

    /**
     * 税率ごとの額（明細の額の合計）から。注文の前（カートの確かめの画面）と、作った注文の内訳（TaxBreakdown）で同じ計算を使う
     *
     * @param  array<int, int>  $grouped  税率（basis point）=> その率の明細の額の合計
     */
    public static function ofAmounts(array $grouped, bool $pricesIncludeTax, TaxRounding $rounding): self
    {
        ksort($grouped);

        $byRate = [];
        $tax = 0;
        $gross = 0;

        foreach ($grouped as $rate => $amount) {
            if ($rate < 0 || $rate > LineDraft::MAX_TAX_RATE_BP) {
                throw new InvalidArgumentException('税率が範囲外です');
            }

            if ($amount < 0) {
                throw new InvalidArgumentException('税率ごとの合計が負になります（値引きが対価を超えています）');
            }

            $rateTax = $pricesIncludeTax
                ? $rounding->divide($amount * $rate, 10_000 + $rate)
                : $rounding->divide($amount * $rate, 10_000);

            $base = $pricesIncludeTax ? $amount - $rateTax : $amount;
            $byRate[$rate] = ['base' => $base, 'tax' => $rateTax];
            $tax += $rateTax;
            $gross += $base + $rateTax;
        }

        return new self($gross - $tax, $tax, $gross, $byRate);
    }
}
