<?php

declare(strict_types=1);

namespace App\Core\Order\Services;

use App\Core\Event\EventStore;
use App\Core\Order\Models\OrderShipping;
use App\Core\Shop\Shipping\Regions;
use App\Core\Shop\Shipping\ShippingQuote;
use InvalidArgumentException;

/**
 * 注文の便ごとの送料の記録（ADR 0064）。注文の確定の中で便ごとの決め方を残し、贈り物の受け取りで差額を決める。
 * どちらも呼ぶ側が連鎖のロックを持つ（注文の確定・贈り物の受け取りのトランザクションの中）。イベントに都道府県（お届け先）は入れない
 */
final class OrderShippingCharges
{
    public const string SETTLED = 'order.shipping_settled';

    public function __construct(private readonly EventStore $events) {}

    public function record(string $transactionId, ?ShippingQuote $quote, string $actor): void
    {
        if ($quote === null || $quote->groups === []) {
            return;
        }

        $groups = [];

        foreach ($quote->groups as $index => $group) {
            OrderShipping::create([
                'transaction_id' => $transactionId,
                'position' => $index + 1,
                'method_id' => $group->methodId,
                'method_name' => $group->name,
                'items_amount' => $group->itemsAmount,
                'fee' => $group->fee,
                'basis' => $group->basis,
                // いちばん高い額で取った便は、その時の表を写す（受け取りまでに表が変わっても、注文の時の表で差額を決める）
                'rates' => $group->basis === 'highest' ? $group->rates : null,
            ]);
            $groups[] = ['method_id' => $group->methodId, 'method' => $group->name, 'fee' => $group->fee, 'basis' => $group->basis];
        }

        $this->events->append('order.shipping_quoted', 'transaction', $transactionId, ['groups' => $groups], $actor);
    }

    /**
     * 贈り物の受け取り（ADR 0055 の 7）: いちばん高い額で取った便を、受け取りの都道府県の額（注文の時の表）で決め直し、差額を返す（0 なら返金しない）。
     * 1 回だけ（決め直した便はもう変えない）
     */
    public function settle(string $transactionId, string $prefecture, string $actor): int
    {
        if (! Regions::known($prefecture)) {
            throw new InvalidArgumentException('知らない都道府県');
        }

        $rows = OrderShipping::query()->where('transaction_id', $transactionId)->where('basis', 'highest')->whereNull('settled_fee')->orderBy('position')->lockForUpdate()->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $difference = 0;

        foreach ($rows as $row) {
            // 表に無い都道府県（起きないはず）は取った額のまま（多く返さない）
            $actual = min($row->fee, (int) ($row->rates[$prefecture] ?? $row->fee));
            $row->settled_fee = $actual;
            $row->save();
            $difference += $row->fee - $actual;
        }

        $this->events->append(self::SETTLED, 'transaction', $transactionId, ['difference' => $difference], $actor);

        return $difference;
    }

    /**
     * 受け取りで決まった送料の差額（まだ決まっていなければ 0）
     */
    public function difference(string $transactionId): int
    {
        return (int) OrderShipping::query()->where('transaction_id', $transactionId)->whereNotNull('settled_fee')
            ->selectRaw('COALESCE(SUM(fee - settled_fee), 0) AS diff')->value('diff');
    }
}
