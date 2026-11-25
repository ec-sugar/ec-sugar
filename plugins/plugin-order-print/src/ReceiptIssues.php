<?php

declare(strict_types=1);

namespace EcSugar\PluginOrderPrint;

use App\Core\Event\EventStore;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Transaction\Enums\OrderStatus;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginOrderPrint\Models\OrderPrintReceipt;
use Illuminate\Support\Facades\DB;

/**
 * 領収書を出す（回数を数える）。書くのはここだけ。連鎖のロック（EventStore::lock()）の後に注文と回数の行のロックを取り、
 * 払い済みかを確かめ直してから回数を 1 つ進め、同じトランザクションで order_print.receipt_issued を残す。
 * 記録に残すのは回数と、お客さんか店の人か（actor）だけ（宛名・但し書きは残さない）
 */
final class ReceiptIssues
{
    /** 領収書を出せる注文の状況（払い済み。0 円の注文・取消・返金・入金待ちは出さない。発送した代引きは入金を確かめるまで出さない〔下の issuable〕） */
    public const array PAID = [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Completed];

    public function __construct(
        private readonly EventStore $events,
        private readonly SaleOrderService $orders,
    ) {}

    public function issuable(Transaction $transaction): bool
    {
        // 代金引換・予約の当日払いでまだ入金を確かめていない注文は、発送済み・来店済みでも出さない（お金を受け取る前の領収書になる。ADR 0074）
        return $transaction->kind === 'sale' && in_array($this->orders->status($transaction), self::PAID, true)
            && ! SaleOrderService::codUnpaid($transaction->id) && ! SaleOrderService::onSiteUnpaid($transaction->id);
    }

    /**
     * 今までに出した回数（まだなら 0）
     */
    public function count(Transaction $transaction): int
    {
        return (int) OrderPrintReceipt::query()->where('transaction_id', $transaction->id)->value('issued_count');
    }

    /**
     * 1 枚出す。何回目かを返す（2 から「再発行」）。払い済みでなければ ReceiptRefused
     */
    public function issue(Transaction $transaction, string $actor): int
    {
        return DB::transaction(function () use ($transaction, $actor): int {
            $this->events->lock();
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if (! $this->issuable($locked)) {
                throw new ReceiptRefused;
            }

            $now = CarbonImmutable::now();
            $row = OrderPrintReceipt::query()->lockForUpdate()->where('transaction_id', $locked->id)->first();

            if ($row === null) {
                $row = OrderPrintReceipt::query()->create(['transaction_id' => $locked->id, 'issued_count' => 1, 'first_issued_at' => $now, 'last_issued_at' => $now]);
            } else {
                $row->forceFill(['issued_count' => $row->issued_count + 1, 'last_issued_at' => $now])->save();
            }

            $this->events->append('order_print.receipt_issued', 'transaction', $locked->id, ['number' => $row->issued_count], $actor);

            return $row->issued_count;
        });
    }
}
