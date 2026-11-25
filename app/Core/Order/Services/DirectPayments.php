<?php

declare(strict_types=1);

namespace App\Core\Order\Services;

use App\Core\Discount\Services\CouponService;
use App\Core\Event\EventStore;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Models\FulfillmentItem;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Inventory\Enums\AdjustmentReason;
use App\Core\Inventory\Services\InventoryOperations;
use App\Core\Inventory\Services\UnitService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Money\Money;
use App\Core\Payment\DirectMethod;
use App\Core\Payment\Events\PaymentAwaiting;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Points\Services\PointService;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\Services\TransactionException;
use App\Core\Transaction\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 店が受け取る支払い（銀行振込・代金引換。ADR 0074。予約の当日払い。ADR 0056）の注文の流れ。Stripe を通さず、入金は店の人が確かめる。
 *
 * - 始める（begin）: 注文の確定のトランザクションの中で。支払いの記録（provider・kind が direct）を 1 つ作る。
 *   銀行振込は「入金待ち」（合意の段のまま。在庫・クーポン・ポイントは押さえたまま）、代引きは払う前に発送できる段（支払い）へ進める。
 *   当日払いは代引きと同じく払う前に支払いの段へ進め（予約が確定する）、来店の時に店の人が入金を確かめる
 * - 入金を確かめる（confirm）: 店の人（お金の段階・直前の再確認）。台帳に受け取りを店の直接の受け取りとして書き、記録を払われたにする。
 *   入れた額が注文の額と違えば、確かめの一文に同意した時だけ（多い・少ないは店が外で調整する。台帳は注文の額で書く）
 * - 受取拒否（refuseCod）: 発送した代引きの注文を取り消す。在庫を戻すかを選ぶ・クーポンとポイントを戻す・記録に残す
 * - 期限切れ（expireIfLapsed）: 銀行振込の払う期限と猶予を過ぎたら、今の入金待ちの期限切れと同じ道で取り消す（在庫・クーポン・ポイントを戻す）
 * - 返金の記録（recordRefund）: 店の人が自分で振り込んだ額を記録する（台帳に返金を書く）
 *
 * どの書き込みも EventStore::lock() → 取引の行のロック → 支払いの記録のロックの順。確認と期限切れ・受取拒否が同時に来ても、ロックの中の状態で片方だけが効く
 */
final class DirectPayments
{
    public const string NOT_DIRECT = 'この注文は、銀行振込・代金引換の注文ではありません。';

    public const string ALREADY_PAID = 'この注文の入金は、すでに確認済みです。';

    public const string CLOSED = 'この注文は取り消されているため、入金を確認できません（受け取った代金は、お客さんへ返金してください）。';

    public function __construct(
        private readonly EventStore $events,
        private readonly TransactionService $transactions,
        private readonly LedgerService $ledger,
        private readonly FulfillmentService $fulfillments,
        private readonly DirectMethodSettings $settings,
        private readonly PaymentNotices $notices,
        private readonly PaymentRefunds $refunds,
        private readonly CouponService $coupons,
        private readonly PointService $points,
        private readonly InventoryOperations $inventory,
        private readonly UnitService $units,
        private readonly GiftCardService $giftCards,
    ) {}

    /**
     * 店が受け取る支払いの注文を始める（注文の確定のトランザクションの中、台帳のロックの後で呼ぶ）。払ってもらう額が 1 円以上の注文だけ。
     * 払ってもらう額は合計 − ギフトカードで払った額（ADR 0076。全額をギフトカードで払った注文は支払いを飛ばして確定するので、ここへ来ない）
     */
    public function begin(Transaction $locked, DirectMethod $method, string $actor): void
    {
        if (DB::transactionLevel() === 0 || OrderGiftCard::dueOf($locked) < 1) {
            throw new LogicException('店が受け取る支払いは、1 円以上の注文の確定のトランザクションの中で始める');
        }

        $now = CarbonImmutable::now();
        $bank = $method === DirectMethod::BankTransfer ? ($this->settings->bankTransfer() ?? throw new LogicException('銀行振込を使っていない店')) : null;
        $payBy = $bank === null ? null : DirectMethodSettings::bankDue($now, $bank['days']);

        $attempt = PaymentAttempt::create([
            'transaction_id' => $locked->id,
            'provider' => 'direct',
            'provider_ref' => 'direct-'.$locked->id,
            'checkout_url' => '',
            'amount' => OrderGiftCard::dueOf($locked),
            'currency' => $locked->currency,
            'status' => 'awaiting',
            'expires_at' => $payBy ?? $now,
            'kind' => 'direct',
            'methods' => $method->value,
            'method' => $method->value,
            'pay_by' => $payBy,
            // 振込先は注文の時の写し（後で店が口座を替えても、この注文で案内した振込先が残る）。店の鍵で暗号化。記録には入れない
            'bank_account' => $bank['account'] ?? null,
        ]);
        $this->events->append('payment.awaiting', 'transaction', $locked->id, ['attempt' => $attempt->id, 'provider' => 'direct', 'amount' => $attempt->amount, 'method' => $method->value]
            + ($payBy === null ? [] : ['pay_by' => $payBy->toIso8601String()]), $actor);

        if ($bank !== null) {
            // 「入金をお待ちしています」の知らせ（振込先と期限）。書き込みが終わってから届く
            $this->events->notice(new PaymentAwaiting($locked->id), $actor);

            return;
        }

        // 代引き・当日払い: 払う前に受け渡せる段へ。ご注文の確認のメールと店への知らせは、受け付けた時に 1 回だけ
        $this->transactions->transition($locked, Phase::Payment, $actor);
        $this->events->notice(new PaymentConfirmed($locked->id, onDelivery: true), $actor);
    }

    /**
     * 入金を確かめた（店の人。お金の段階・直前の再確認は経路が守る）。銀行振込は入金待ちの注文、代引き・当日払いは払う前の注文だけ。
     * $received は店の人が入れた受け取った額。注文の額と違えば $acceptDifference（確かめの一文への同意）が要る
     */
    public function confirm(Transaction $transaction, int $received, bool $acceptDifference, ?string $actor = null): Transaction
    {
        if ($received < 1 || $received > 100_000_000) {
            throw ValidationException::withMessages(['amount' => '受け取った額を 1 円以上で入力してください。']);
        }

        return DB::transaction(function () use ($transaction, $received, $acceptDifference, $actor): Transaction {
            [$locked, $attempt] = $this->lock($transaction);

            if ($attempt->status === 'paid') {
                throw new TransactionException(self::ALREADY_PAID);
            }

            if ($attempt->status !== 'awaiting' || $locked->status !== TransactionStatus::Open) {
                throw new TransactionException(self::CLOSED);
            }

            // 受け渡しの時に払う手段（代引き・当日払い）は、払う前に支払いの段へ進んでいる
            $cod = DirectMethod::tryFrom((string) $attempt->method)?->paysOnHandover() === true;
            $expected = $cod ? [Phase::Payment, Phase::Fulfillment] : [Phase::Agreement];

            if (! in_array($locked->phase, $expected, true)) {
                throw new TransactionException(self::CLOSED);
            }

            // 注文の額は払ってもらう額（合計 − ギフトカードで払った額。ADR 0076）
            $total = OrderGiftCard::dueOf($locked);

            if ($received !== $total && ! $acceptDifference) {
                throw ValidationException::withMessages(['amount' => '入力した額（'.Money::format($received).'）が、注文の額（'.Money::format($total).'）より'
                    .($received > $total ? '多いです' : '少ないです').'。差額はお客さんと直接やり取りして調整し、この注文は注文の額で入金済みにします。よければ「差額を確認した」にチェックを入れてから、もう一度押してください。']);
            }

            // 台帳には注文の額で、店の直接の受け取りとして書く（決済代行を通らない）。確定の前は売掛のまま
            $this->ledger->recordPayment($locked, $total, PaymentRoute::Direct, $attempt->provider_ref, $actor);
            // ギフトカードで払った分も、同じトランザクションで「ギフトカードでの受け取り」として台帳に（カード払いの確定と同じ。ADR 0076）
            $this->giftCards->recordPayment($locked, $actor);
            $attempt->forceFill(['status' => 'paid', 'paid_at' => CarbonImmutable::now()])->save();
            $this->events->append('payment.received', 'transaction', $locked->id, ['attempt' => $attempt->id, 'provider' => 'direct', 'amount' => $total, 'method' => $attempt->method]
                + ($received === $total ? [] : ['received' => $received]), $actor);

            if (! $cod) {
                $locked = $this->transactions->transition($locked, Phase::Payment, $actor);
                // 今のカードの支払いと同じ後ろの流れ（ご注文の確認のメール・クーポンを使ったに・店への知らせ）
                $this->events->notice(new PaymentConfirmed($locked->id), $actor);

                return $locked;
            }

            // 代引き・当日払い: 払いが届いたのでクーポンを「使った」に。発送（来店）が済んでいれば、ここでポイントを付ける（発送と支払いの両方がそろった時。ADR 0068・0074）
            $this->coupons->markUsed($locked->id);

            if (! Fulfillment::query()->where('transaction_id', $locked->id)->where('status', FulfillmentStatus::Pending)->exists()) {
                $this->points->earn($locked->id, $actor);
            }

            return $locked;
        });
    }

    /**
     * 代引きの受取拒否で取り消す（発送した、まだ払われていない代引きの注文だけ。発送の前なら、ふつうの取消を使う）。
     * 受渡を取り消し、支払いの記録を閉じ、クーポンの数と使ったポイントを戻して、取引を取消にする（押さえた在庫が戻る）。
     * 在庫に戻さない（傷んだ・戻ってこない）を選んだら、発送した受渡の品だけを、戻した後に「破損・不良」として減らす（個体で持つ品は廃棄に。記録に残る）
     */
    public function refuseCod(Transaction $transaction, bool $restock, ?string $actor = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $restock, $actor): Transaction {
            [$locked, $attempt] = $this->lock($transaction);

            if ($attempt->method !== DirectMethod::Cod->value) {
                throw new TransactionException(self::NOT_DIRECT);
            }

            if ($attempt->status === 'paid') {
                throw new TransactionException('入金を確認した代引きの注文は、受取拒否にできません（返品・返金で扱います）。');
            }

            if ($attempt->status !== 'awaiting' || $locked->status !== TransactionStatus::Open || ! in_array($locked->phase, [Phase::Payment, Phase::Fulfillment], true)) {
                throw new TransactionException('この注文は受取拒否にできません（終わっているか、取り消されています）。');
            }

            $rows = Fulfillment::query()->where('transaction_id', $locked->id)->get();

            if (! $rows->contains(static fn (Fulfillment $f): bool => $f->status === FulfillmentStatus::Shipped)) {
                throw new TransactionException('まだ発送していない代引きの注文は、「注文を取り消す」で取り消してください。');
            }

            // 戻ってきたのは発送した受渡の品だけ（まだ発送していない受渡の品は店にある）。明細ごとの数
            /** @var array<string, int> $returned */
            $returned = FulfillmentItem::query()
                ->whereIn('fulfillment_id', $rows->filter(static fn (Fulfillment $f): bool => $f->status === FulfillmentStatus::Shipped)->pluck('id')->all())
                ->groupBy('transaction_line_id')->selectRaw('transaction_line_id, SUM(quantity)::integer AS quantity')
                ->pluck('quantity', 'transaction_line_id')->map(static fn (mixed $q): int => (int) $q)->all();

            foreach ($rows as $fulfillment) {
                if (in_array($fulfillment->status, [FulfillmentStatus::Pending, FulfillmentStatus::Shipped], true)) {
                    $this->fulfillments->cancel($fulfillment, $actor);
                }
            }

            $expired = PaymentAttempt::query()->where('transaction_id', $locked->id)->whereIn('status', ['open', 'awaiting'])->update(['status' => 'expired', 'updated_at' => CarbonImmutable::now()]);
            $this->coupons->release($locked->id, $actor);
            $this->points->release($locked->id, $actor);
            // 使ったギフトカードの額もカードへ戻す（払われていないので台帳には受け取りが無い。ADR 0076）
            $this->giftCards->release($locked->id, $actor);
            $this->events->append('order.cod_refused', 'transaction', $locked->id, ['restock' => $restock, 'attempts_expired' => $expired], $actor);
            // 取消で押さえた在庫が戻る（受渡を済みにしていないので、明細の数の全部）
            $locked = $this->transactions->transition($locked, Phase::Cancel, $actor);

            // 在庫に戻さない: 戻ってきた（発送した受渡の）品だけを、戻した後に減らす。数で持つ品は破損・不良の調整、個体で持つ品は廃棄に（戻す時と対称に）
            if (! $restock) {
                foreach (TransactionLine::query()->whereKey(array_keys($returned))->where('line_type', LineType::Item)->orderBy('position')->get() as $line) {
                    $quantity = $returned[$line->id] ?? 0;

                    if ($line->inventory_unit_id !== null) {
                        $this->units->dispose($line->unit()->firstOrFail());
                        $this->events->append('inventory.unit_disposed', 'inventory_unit', $line->inventory_unit_id, ['reason' => AdjustmentReason::Damage->value, 'transaction' => $locked->id], $actor);
                    } elseif ($line->variant_id !== null && $line->location_id !== null && $quantity > 0) {
                        $this->inventory->adjust($line->variant()->firstOrFail(), $line->location()->firstOrFail(), -$quantity, AdjustmentReason::Damage, $actor);
                    }
                }
            }

            return $locked;
        });
    }

    /**
     * 銀行振込の払う期限と猶予を過ぎた注文を取り消す（見回りから。今の入金待ちの期限切れと同じ道。在庫・クーポン・ポイントを戻し、お客さんへ知らせる）。
     * ロックの中で確かめ直すので、店の人の入金の確認と同時に走っても片方だけが効く
     *
     * @return string 結果（cancelled・waiting・closed）
     */
    public function expireIfLapsed(Transaction $transaction, CarbonImmutable $now, string $actor = 'system:expiry'): string
    {
        return DB::transaction(function () use ($transaction, $now, $actor): string {
            $this->events->lock();
            $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $attempt = PaymentAttempt::query()->where('transaction_id', $locked->id)->where('kind', 'direct')->lockForUpdate()->first();

            if ($attempt === null || $attempt->method !== DirectMethod::BankTransfer->value || $attempt->status !== 'awaiting'
                || $locked->status !== TransactionStatus::Open || $locked->phase !== Phase::Agreement) {
                return 'closed';
            }

            if (self::lapseAt($attempt)?->isAfter($now) !== false) {
                return 'waiting';
            }

            $this->notices->cancelUnpaidOrder($locked, $actor, lapsed: true);

            return 'cancelled';
        });
    }

    /**
     * 期限切れで取り消す時刻（払う期限 + 猶予。コンビニ払いと同じ猶予。期限の日の遅い振込を、店が確かめる前に取り消さない）
     */
    public static function lapseAt(PaymentAttempt $attempt): ?CarbonImmutable
    {
        return $attempt->pay_by?->addHours((int) config('payment.awaiting_grace_hours'));
    }

    /**
     * 店の人が自分で振り込んだ返金を記録する（銀行振込・代引きで受け取った注文だけ）
     */
    public function recordRefund(Transaction $transaction, int $amount, ?string $actor = null): Refund
    {
        return DB::transaction(function () use ($transaction, $amount, $actor): Refund {
            [$locked] = $this->lock($transaction);

            return $this->refunds->recordDirect($locked, $amount, $actor);
        });
    }

    /**
     * 台帳のロック → 取引の行のロック → 店が受け取る支払いの記録のロック（決まりの順）。物販で、店が受け取る支払いの注文だけ
     *
     * @return array{0: Transaction, 1: PaymentAttempt}
     */
    private function lock(Transaction $transaction): array
    {
        $this->events->lock();
        $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
        $attempt = PaymentAttempt::query()->where('transaction_id', $locked->id)->where('kind', 'direct')->lockForUpdate()->first();

        if ($locked->kind !== 'sale' || $attempt === null) {
            throw new TransactionException(self::NOT_DIRECT);
        }

        return [$locked, $attempt];
    }
}
