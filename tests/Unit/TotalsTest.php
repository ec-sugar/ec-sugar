<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\TaxRounding;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Totals;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 消費税は税率ごとに合計してから 1 回だけ丸める（インボイスの要件）。
 */
final class TotalsTest extends TestCase
{
    public function test_tax_is_rounded_once_per_rate_with_reduced_rate_mixed_in(): void
    {
        $lines = [
            new LineDraft(LineType::Item, '弁当', 3, 540, 800),      // 軽減税率 8%: 1,620
            new LineDraft(LineType::Item, '割り箸', 1, 110, 1000),   // 10%: 110
            new LineDraft(LineType::Item, '袋', 1, 5, 1000),         // 10%: 5
        ];

        $totals = Totals::of($lines, pricesIncludeTax: true, rounding: TaxRounding::Floor);

        // 8%: 1620 × 8/108 = 120、10%: 115 × 10/110 = 10.45 → 10
        $this->assertSame(['base' => 1500, 'tax' => 120], $totals->byRate[800]);
        $this->assertSame(['base' => 105, 'tax' => 10], $totals->byRate[1000]);
        $this->assertSame([1735, 130, 1605], [$totals->total, $totals->tax, $totals->subtotal]);
    }

    public function test_rounding_once_differs_from_rounding_per_line(): void
    {
        // 15 円 × 3 行（外税 10%）: 明細ごとに丸めると 1 × 3 = 3、合計から丸めると 4.5 → 4
        $lines = array_fill(0, 3, new LineDraft(LineType::Item, '部品', 1, 15, 1000));
        $exclusive = Totals::of($lines, pricesIncludeTax: false, rounding: TaxRounding::Floor);
        $this->assertSame(4, $exclusive->tax);
        $this->assertSame(49, $exclusive->total);

        $this->assertSame(5, Totals::of($lines, false, TaxRounding::HalfUp)->tax);
        $this->assertSame(5, Totals::of($lines, false, TaxRounding::Ceil)->tax);
    }

    public function test_discount_larger_than_the_rate_group_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Totals::of([
            new LineDraft(LineType::Item, '商品', 1, 100, 1000),
            LineDraft::adjustment('discount', '値引き', -101, 1000),
        ], true, TaxRounding::Floor);
    }

    public function test_line_limits_guard_against_integer_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LineDraft(LineType::Item, '高額', LineDraft::MAX_QUANTITY, LineDraft::MAX_AMOUNT, 1000);
    }
}
