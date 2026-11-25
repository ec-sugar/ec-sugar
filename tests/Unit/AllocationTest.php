<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Money\Allocation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 金額の按分（ADR 0107 で、値引きと返品の精算が別々に持っていた同じ計算を 1 つに集めた）。
 */
final class AllocationTest extends TestCase
{
    public function test_it_splits_by_weight_and_gives_the_remainder_to_the_largest(): void
    {
        // 端数 1 円は余りの大きい順（同じ重みなら最初の鍵）
        $this->assertSame([1 => 34, 2 => 33, 3 => 33], Allocation::byWeight(100, [1 => 1000, 2 => 1000, 3 => 1000]));
        // 1 円を 100:300 に配ると、余りの大きい方（300 側）へ
        $this->assertSame([5 => 0, 7 => 1], Allocation::byWeight(1, [5 => 100, 7 => 300]));
        // 鍵と並びは重みのまま
        $this->assertSame(['a' => 50, 'b' => 50], Allocation::byWeight(100, ['a' => 500, 'b' => 500]));
    }

    public function test_nothing_to_split_gives_nothing(): void
    {
        $this->assertSame([1 => 0], Allocation::byWeight(0, [1 => 500]));
        $this->assertSame([1 => 0, 2 => 0], Allocation::byWeight(0, [1 => 500, 2 => 300]));
        // 重みが全部 0（送料だけの注文で商品の重みが無い、など）
        $this->assertSame([1 => 0], Allocation::byWeight(100, [1 => 0]));
        $this->assertSame([], Allocation::byWeight(100, []));
    }

    public function test_a_negative_amount_gives_nothing_instead_of_negative_shares(): void
    {
        // 前の値引きの実装は負を通して負の按分を返していた（ADR 0107 で安全側へ揃えた）
        $this->assertSame([1 => 0, 2 => 0], Allocation::byWeight(-100, [1 => 500, 2 => 500]));
        $this->assertSame([1 => 0], Allocation::byWeight(-1, [1 => 500]));
    }

    public function test_it_refuses_more_than_the_weights(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Allocation::byWeight(501, [1 => 500]);
    }

    public function test_the_shares_always_add_up_to_the_amount(): void
    {
        mt_srand(20261118);

        for ($i = 0; $i < 300; $i++) {
            $weights = [];
            $count = mt_rand(1, 8);

            for ($k = 0; $k < $count; $k++) {
                $weights[$k] = mt_rand(0, 50_000);
            }

            $total = array_sum($weights);

            if ($total === 0) {
                continue;
            }

            $amount = mt_rand(1, $total);
            $shares = Allocation::byWeight($amount, $weights);

            $this->assertSame($amount, array_sum($shares), '配った合計が額とちょうど同じ');
            $this->assertSame(array_keys($weights), array_keys($shares), '鍵と並びが変わらない');

            foreach ($shares as $key => $share) {
                $this->assertGreaterThanOrEqual(0, $share);
                $this->assertLessThanOrEqual($weights[$key], $share, '重みを越えて配らない');
            }
        }
    }
}
