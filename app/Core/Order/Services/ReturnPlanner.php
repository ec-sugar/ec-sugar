<?php

declare(strict_types=1);

namespace App\Core\Order\Services;

use App\Core\Discount\Models\CouponRedemption;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\FulfillmentItem;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Money\Allocation;
use App\Core\Money\Money;
use App\Core\Order\ReturnPlan;
use App\Core\Order\ReturnRequest;
use App\Core\Payment\Models\RefundLine;
use App\Core\Payment\Services\ReturnSettlement;
use App\Core\Points\Models\OrderPoints;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\Services\TransactionException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * 返品・一部返金で返す額を明細から決める（ADR 0048）。書き込みはしない（確かめの画面と、実行の時のロックの中で同じ計算）。
 *
 * - 商品の行: 値段 × 数ではなく、値引き（クーポン・自動の割引）と使ったポイントの割り振りを引いた「実の額」を数で割る。
 *   何回かに分けて返しても、全部返すと実の額にちょうど届く（返す前と後の累計の差で出す。1 円の端数を残さない）
 * - オプションの行: 選んだ時だけ、返す数の分を同じ決め方で（親の商品と同じ率）
 * - 送料: 選んだ時だけ、まだ返していない分の全部（使ったポイントの割り振りを引き、贈り物の送料の差額の返金も引く）
 * - 品（商品・オプション・送料の行）の返す額は、既定で「品の額と返せる残りの小さい方」（金額だけの返金の後でも、品を返して在庫に戻せる）。
 *   店の人は 0 から既定までに下げられる（0 なら品だけ返し、お金は動かさない）
 * - 金額だけ: 税率ごとの、まだ返していない額の割合で配る
 * - 消費税は税率ごとに「返す前の残りの税 − 返した後の残りの税」（ReturnSettlement::taxes）
 * - 返せるのは発送した数まで、合計は受け取った額のうち返せる分まで。0 円の品だけを返す時は合計 0 円（返金の記録は作らず、返品と在庫の戻しだけ）
 */
final class ReturnPlanner
{
    public const string NOTHING = '返品する商品か、返金する金額を選んでください。';

    public const string OVER = 'この注文で受け取った額のうち、まだ返金していない額を超えて返金することはできません。';

    public const string GOODS_OVER = '商品の返金額は %s までです（商品の額と、まだ返金できる残りの額のうち小さい方）。商品の額より多く返金するときは、「金額だけの返金」に入力してください。';

    public const string GOODS_NEGATIVE = '商品の返金額は 0 円以上で入力してください。';

    public function __construct(private readonly ReturnSettlement $books) {}

    /**
     * @throws TransactionException 選んだものが返せない（見せてよい文）
     */
    public function plan(Transaction $transaction, ReturnRequest $request): ReturnPlan
    {
        $facts = $this->facts($transaction);
        $lines = [];

        foreach ($request->items as $position => $item) {
            $line = $facts['lines']->get($position);

            if (! $line instanceof TransactionLine || $line->line_type !== LineType::Item) {
                throw new TransactionException('この注文に無い商品の行です。画面を読み込み直してください。');
            }

            $returned = $facts['returned'][$position] ?? 0;
            $left = ($facts['shipped'][$line->id] ?? 0) - $returned;

            if ($item['quantity'] < 1 || $item['quantity'] > $left) {
                throw new TransactionException("「{$line->description}」は {$left} 点まで返品できます（発送した数 − 返品済みの数）。");
            }

            if ($item['restock'] && ! self::restockable($line)) {
                throw new TransactionException("「{$line->description}」は在庫に戻せません（在庫を持たない商品です）。");
            }

            $lines[] = [
                'kind' => 'item', 'position' => $position, 'description' => $line->description, 'quantity' => $item['quantity'],
                'amount' => self::share($facts['net'][$position] ?? 0, $line->quantity, $returned, $item['quantity']),
                'rate_bp' => $line->tax_rate_bp, 'restock' => $item['restock'],
            ];

            if (! $item['addons']) {
                continue;
            }

            foreach ($facts['lines'] as $addon) {
                if ($addon->kind !== 'addon' || $addon->parent_position !== $position) {
                    continue;
                }

                $covered = $facts['addonUnits'][$addon->position] ?? 0;
                $units = min($item['quantity'], $line->quantity - $covered);

                if ($units > 0) {
                    $lines[] = [
                        'kind' => 'addon', 'position' => $addon->position, 'description' => $addon->description, 'quantity' => $units,
                        'amount' => self::share($facts['net'][$addon->position] ?? 0, $line->quantity, $covered, $units),
                        'rate_bp' => $addon->tax_rate_bp, 'restock' => false,
                    ];
                }
            }
        }

        if ($request->shipping) {
            foreach ($facts['shipping'] as $position => $left) {
                if ($left > 0) {
                    $line = $facts['lines']->get($position);
                    $lines[] = ['kind' => 'shipping', 'position' => $position, 'description' => $line->description ?? '送料', 'quantity' => 0, 'amount' => $left, 'rate_bp' => (int) $line?->tax_rate_bp, 'restock' => false];
                }
            }
        }

        // 品の返す額を返せる残りまでに収める（店の人が下げた時はその額に）。金額だけの返金は、その後の残りに配る
        [$lines, $goodsFull, $goodsLimit] = $this->fit($transaction, $lines, $request);

        if ($request->amount > 0) {
            $lines = [...$lines, ...$this->amountLines($transaction, $request->amount, $lines)];
        }

        $total = array_sum(array_column($lines, 'amount'));

        // 0 円は、0 円の品を返す（在庫に戻す）記録だけのとき（返金はしない）
        if ($total < 1 && $request->items === []) {
            throw new TransactionException(self::NOTHING);
        }

        $gross = [];
        foreach ($lines as $row) {
            $gross[$row['rate_bp']] = ($gross[$row['rate_bp']] ?? 0) + $row['amount'];
        }

        try {
            $taxes = $this->books->taxes($transaction, $gross);
        } catch (InvalidArgumentException) {
            throw new TransactionException(self::OVER);
        }

        $available = $this->books->available($transaction);

        if ($total > $available) {
            throw new TransactionException(self::OVER.'（返金できる額 '.number_format($available).' 円、今回 '.number_format($total).' 円）');
        }

        return new ReturnPlan(array_values(array_filter($lines, static fn (array $l): bool => $l['amount'] > 0 || $l['restock'] || $l['kind'] === 'item')), $taxes, $total, $available, $goodsFull, $goodsLimit);
    }

    /**
     * 返品の画面に出す、明細ごとの返せる数と額の目安（読むだけ）
     *
     * @return array{items: list<array{position: int, description: string, quantity: int, shipped: int, returned: int, returnable: int, net: int, restockable: bool, addons: list<array{position: int, description: string, net: int, covered: int}>}>, shipping: int, remaining_by_rate: array<int, int>}
     */
    public function options(Transaction $transaction): array
    {
        $facts = $this->facts($transaction);
        $items = [];

        foreach ($facts['lines'] as $position => $line) {
            if ($line->line_type !== LineType::Item) {
                continue;
            }

            $shipped = $facts['shipped'][$line->id] ?? 0;
            $returned = $facts['returned'][$position] ?? 0;
            $items[] = [
                'position' => $position,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'shipped' => $shipped,
                'returned' => $returned,
                'returnable' => max(0, $shipped - $returned),
                'net' => $facts['net'][$position] ?? 0,
                'restockable' => self::restockable($line),
                'addons' => $facts['lines']->filter(static fn (TransactionLine $a): bool => $a->kind === 'addon' && $a->parent_position === $position)
                    ->map(static fn (TransactionLine $a): array => ['position' => $a->position, 'description' => $a->description, 'net' => $facts['net'][$a->position] ?? 0, 'covered' => $facts['addonUnits'][$a->position] ?? 0])
                    ->values()->all(),
            ];
        }

        return ['items' => $items, 'shipping' => array_sum($facts['shipping']), 'remaining_by_rate' => $this->books->remainingByRate($transaction)];
    }

    /**
     * 返す数 $k の額: 実の額を数で割った累計の差（前に返した数 $before から）
     */
    private static function share(int $net, int $quantity, int $before, int $k): int
    {
        return $quantity < 1 ? 0 : intdiv($net * ($before + $k), $quantity) - intdiv($net * $before, $quantity);
    }

    private static function restockable(TransactionLine $line): bool
    {
        return $line->variant_id !== null && $line->location_id !== null && $line->inventory_unit_id === null;
    }

    /**
     * 品の行（商品・オプション・送料）の返す額を、返せる残りまでに収める。
     * 上限 = 税率ごとの「品の額とその率のまだ返していない額の小さい方」の合計と、返せる額（決済代行で返す時は、決済代行で受け取ってまだ返していない額も。
     * ギフトカードで払われた分は決済代行では返せない）の小さい方。既定は上限、店の人は 0 から上限までに下げられる。
     * 下げる時は、率ごとの上限の割合で率に配り（金額だけの返金と同じ split）、率の中は行の額の割合で配る（どの行も明細から決めた額を越えない）
     *
     * @param  list<array{kind: string, position: int|null, description: string, quantity: int, amount: int, rate_bp: int, restock: bool}>  $lines
     * @return array{0: list<array{kind: string, position: int|null, description: string, quantity: int, amount: int, rate_bp: int, restock: bool}>, 1: int, 2: int} [行, 品の額, 上限]
     */
    private function fit(Transaction $transaction, array $lines, ReturnRequest $request): array
    {
        $full = array_sum(array_column($lines, 'amount'));
        $remaining = $this->books->remainingByRate($transaction);
        $caps = [];

        foreach ($lines as $row) {
            $caps[$row['rate_bp']] = ($caps[$row['rate_bp']] ?? 0) + $row['amount'];
        }

        foreach ($caps as $rate => $amount) {
            $caps[$rate] = max(0, min($amount, $remaining[$rate] ?? 0));
        }

        $limit = min(array_sum($caps), $this->books->available($transaction));

        if ($request->channel === 'provider') {
            $limit = min($limit, $this->books->room($transaction) - app(GiftCardService::class)->heldInLedger($transaction->id));
        }

        $limit = max(0, $limit);
        $target = $request->goodsAmount ?? $limit;

        if ($target < 0) {
            throw new TransactionException(self::GOODS_NEGATIVE);
        }

        if ($target > $limit) {
            throw new TransactionException(sprintf(self::GOODS_OVER, Money::format($limit)));
        }

        if ($target === $full) {
            return [$lines, $full, $limit];
        }

        $shares = [];
        foreach (Allocation::byWeight($target, $caps) as $rate => $part) {
            $weights = array_map(static fn (array $row): int => $row['amount'], array_filter($lines, static fn (array $row): bool => $row['rate_bp'] === $rate));
            $shares += Allocation::byWeight($part, $weights);
        }

        foreach (array_keys($lines) as $index) {
            $lines[$index]['amount'] = $shares[$index] ?? 0;
        }

        return [$lines, $full, $limit];
    }

    /**
     * 金額だけの返金を、税率ごとの残りの額（この返金で返す行の分も引いた）の割合で配る
     *
     * @param  list<array{kind: string, position: int|null, description: string, quantity: int, amount: int, rate_bp: int, restock: bool}>  $planned
     * @return list<array{kind: string, position: int|null, description: string, quantity: int, amount: int, rate_bp: int, restock: bool}>
     */
    private function amountLines(Transaction $transaction, int $amount, array $planned): array
    {
        $remaining = $this->books->remainingByRate($transaction);

        foreach ($planned as $row) {
            $remaining[$row['rate_bp']] = max(0, ($remaining[$row['rate_bp']] ?? 0) - $row['amount']);
        }

        if ($amount > array_sum($remaining)) {
            throw new TransactionException(self::OVER);
        }

        $rows = [];
        foreach (Allocation::byWeight($amount, $remaining) as $rate => $part) {
            if ($part > 0) {
                $rows[] = ['kind' => 'amount', 'position' => null, 'description' => '金額だけの返金', 'quantity' => 0, 'amount' => $part, 'rate_bp' => $rate, 'restock' => false];
            }
        }

        return $rows;
    }

    /**
     * 明細と、実の額・発送した数・返した数・まだ返していない送料
     *
     * @return array{lines: Collection<int, TransactionLine>, net: array<int, int>, shipped: array<string, int>, returned: array<int, int>, addonUnits: array<int, int>, shipping: array<int, int>}
     */
    private function facts(Transaction $transaction): array
    {
        /** @var Collection<int, TransactionLine> $lines */
        $lines = TransactionLine::query()->where('transaction_id', $transaction->id)->orderBy('position')->get()->keyBy('position');
        $discounts = self::positions(CouponRedemption::query()->where('transaction_id', $transaction->id)->where('status', '<>', 'released')->value('allocations'));
        $points = self::positions(OrderPoints::query()->find($transaction->id)?->allocations);

        // 率ごとに、商品とオプションの行の「値段 − 値引き − ポイント」を重みにし、その率の明細の合計から送料の分を引いた額を配る。
        // 割り振りの記録がそろっていれば重みのまま（端数も一致）、記録の無い調整の行（定期便の値引きなど）があれば、その分を割合で引く
        $weights = [];
        $shipping = [];
        $byRate = [];
        foreach ($lines as $position => $line) {
            $byRate[$line->tax_rate_bp] = ($byRate[$line->tax_rate_bp] ?? 0) + $line->amount;

            if ($line->line_type === LineType::Item || $line->kind === 'addon') {
                $weights[$line->tax_rate_bp][$position] = max(0, $line->amount - ($discounts[$position] ?? 0) - ($points[$position] ?? 0));
            } elseif ($line->kind === 'shipping') {
                $shipping[$position] = max(0, $line->amount - ($points[$position] ?? 0));
            }
        }

        $net = [];
        foreach ($weights as $rate => $byPosition) {
            $target = $byRate[$rate] - array_sum(array_intersect_key($shipping, array_filter($lines->all(), static fn (TransactionLine $l): bool => $l->tax_rate_bp === $rate)));
            $net += $target >= array_sum($byPosition) ? $byPosition : Allocation::byWeight(max(0, $target), $byPosition);
        }

        $shipped = FulfillmentItem::query()
            ->join('fulfillments', 'fulfillments.id', '=', 'fulfillment_items.fulfillment_id')
            ->where('fulfillments.transaction_id', $transaction->id)
            ->whereIn('fulfillments.status', [FulfillmentStatus::Shipped->value, FulfillmentStatus::Completed->value])
            ->groupBy('fulfillment_items.transaction_line_id')
            ->selectRaw('fulfillment_items.transaction_line_id AS line_id, SUM(fulfillment_items.quantity)::bigint AS quantity')
            ->pluck('quantity', 'line_id')->map(static fn (mixed $q): int => (int) $q)->all();

        $done = RefundLine::query()->where('transaction_id', $transaction->id)->whereNotNull('line_position')->toBase()
            ->selectRaw('kind, line_position, SUM(quantity)::bigint AS quantity, SUM(amount)::bigint AS amount')->groupBy('kind', 'line_position')->get();
        $returned = [];
        $addonUnits = [];
        $shippingDone = [];
        foreach ($done as $row) {
            match ($row->kind) {
                'item' => $returned[(int) $row->line_position] = (int) $row->quantity,
                'addon' => $addonUnits[(int) $row->line_position] = (int) $row->quantity,
                default => $shippingDone[(int) $row->line_position] = (int) $row->amount,
            };
        }

        // 送料のまだ返していない分。贈り物の送料の差額の返金も、送料の行の前から順に引く
        // 断られてまだ片付けていない差額の返金も引く（店の人が頼み直せるので、返品の送料で返すと二重になる）
        $differences = ReturnSettlement::shippingDifferences($transaction->id);
        foreach ($shipping as $position => $amount) {
            $left = max(0, $amount - ($shippingDone[$position] ?? 0));
            $take = min($left, $differences);
            $differences -= $take;
            $shipping[$position] = $left - $take;
        }

        return ['lines' => $lines, 'net' => $net, 'shipped' => $shipped, 'returned' => $returned, 'addonUnits' => $addonUnits, 'shipping' => $shipping];
    }

    /**
     * 割り振りの記録（明細の位置 => 額）を整数の配列に
     *
     * @return array<int, int>
     */
    private static function positions(mixed $allocations): array
    {
        if (is_string($allocations)) {
            $allocations = json_decode($allocations, true);
        }

        $out = [];
        foreach (is_array($allocations) ? $allocations : [] as $position => $amount) {
            $out[(int) $position] = (int) $amount;
        }

        return $out;
    }
}
