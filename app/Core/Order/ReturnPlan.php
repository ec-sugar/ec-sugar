<?php

declare(strict_types=1);

namespace App\Core\Order;

/**
 * 返品・一部返金の確かめの中身（ADR 0048）。返す行・税率ごとの額と消費税・合計。確かめの画面に出し、実行の時はロックの中で作り直して合計を照らす。
 * goodsFull は選んだ品（商品・オプション・送料の行）の明細から決めた額、goodsLimit は品の返す額の上限（＝既定。品の額と返せる残りの小さい方）
 */
final readonly class ReturnPlan
{
    /**
     * @param  list<array{kind: string, position: int|null, description: string, quantity: int, amount: int, rate_bp: int, restock: bool}>  $lines
     * @param  list<array{rate_bp: int, gross: int, tax: int}>  $taxes
     */
    public function __construct(
        public array $lines,
        public array $taxes,
        public int $total,
        public int $available,
        public int $goodsFull = 0,
        public int $goodsLimit = 0,
    ) {}

    /**
     * @return array{lines: list<array{kind: string, position: int|null, description: string, quantity: int, amount: int, rate_bp: int, restock: bool}>, taxes: list<array{rate_bp: int, gross: int, tax: int}>, total: int, available: int, goods: array{full: int, limit: int, amount: int}}
     */
    public function toArray(): array
    {
        $goods = array_sum(array_column(array_filter($this->lines, static fn (array $l): bool => $l['kind'] !== 'amount'), 'amount'));

        return ['lines' => $this->lines, 'taxes' => $this->taxes, 'total' => $this->total, 'available' => $this->available,
            'goods' => ['full' => $this->goodsFull, 'limit' => $this->goodsLimit, 'amount' => $goods]];
    }
}
