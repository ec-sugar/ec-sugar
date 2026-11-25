<?php

declare(strict_types=1);

namespace App\Core\Order\Services;

use App\Core\Discount\Services\CouponService;
use App\Core\Event\EventStore;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\ListingKinds;
use App\Core\Listing\Models\Listing;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\RefundDeclined;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Payment\Services\PaymentService;
use App\Core\Payment\Services\ReturnSettlement;
use App\Core\Payment\Services\StoredPayments;
use App\Core\Points\Services\PointService;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\OrderStatus;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Events\FulfillmentShipped;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 物販の注文を運営・店が動かす（管理画面の片 F）。取引と受渡のサービスを決まった順で呼ぶだけで、状態はそれぞれのサービスが変える。
 * 二度目の操作は状態で断る（発送済みの受渡をもう一度発送しない・終わった取引は動かさない）。入金の前は発送しない（物販は先払い）
 */
final class SaleOrderService
{
    public const string CHECKOUT_IN_FLIGHT = 'お客さんが支払いの画面を開いている途中です。数分おいてから、もう一度お試しください。';

    private const string STORED_IN_FLIGHT = '登録済みのカードへの請求を、決済代行で取り消せませんでした（支払い済み・処理中・確認が必要のいずれか）。少し待ってから、もう一度お試しください。';

    /** 運送会社（受渡の carrier に入れる識別子と、画面の名前） */
    public const array CARRIERS = ['yamato' => 'ヤマト運輸', 'sagawa' => '佐川急便', 'japanpost' => '日本郵便', 'other' => 'その他'];

    public function __construct(
        private readonly EventStore $events,
        private readonly TransactionService $transactions,
        private readonly FulfillmentService $fulfillments,
        private readonly PaymentService $payments,
        private readonly PaymentRefunds $refunds,
        private readonly StoredPayments $stored,
        private readonly CouponService $coupons,
        private readonly PointService $points,
        private readonly LedgerService $ledger,
        private readonly ReturnSettlement $returns,
        private readonly GiftCardService $giftCards,
    ) {}

    /**
     * 対応の状況（見せ方）
     */
    public function status(Transaction $transaction): OrderStatus
    {
        if ($transaction->status === TransactionStatus::Refunded) {
            return OrderStatus::Refunded;
        }

        if ($transaction->status === TransactionStatus::Cancelled) {
            return OrderStatus::Cancelled;
        }

        return match ($transaction->phase) {
            Phase::Agreement => OrderStatus::AwaitingPayment,
            Phase::Payment => match (true) {
                (int) $transaction->total_amount === 0 => OrderStatus::Confirmed,
                // 代引きで、まだ払われていない（届けた時に払う。ADR 0074）
                self::codUnpaid($transaction->id) => OrderStatus::CashOnDelivery,
                // 予約の当日払いで、まだ払われていない（来店の時にお店で払う）
                self::onSiteUnpaid($transaction->id) => OrderStatus::PayOnSite,
                default => OrderStatus::Paid,
            },
            Phase::Fulfillment => OrderStatus::Shipped,
            default => OrderStatus::Completed,
        };
    }

    /**
     * 受渡を発送済みにする（運送会社と追跡番号）。入金済みの注文の最初の発送で、取引を「受渡」へ進める。
     * 受渡が全部発送されたら、会員の付く予定のポイントを同じトランザクションで付ける（ADR 0068 の 4）
     */
    public function ship(Transaction $transaction, string $fulfillmentId, ?string $carrier, ?string $trackingNumber, ?string $actor = null): Transaction
    {
        if ($carrier !== null && ! array_key_exists($carrier, self::CARRIERS)) {
            throw new TransactionException('運送会社が違います');
        }

        return DB::transaction(function () use ($transaction, $fulfillmentId, $carrier, $trackingNumber, $actor): Transaction {
            $locked = $this->lock($transaction);

            if (! in_array($locked->phase, [Phase::Payment, Phase::Fulfillment], true)) {
                throw new TransactionException($locked->phase === Phase::Agreement ? '入金の前は発送できません' : 'この注文は発送できません');
            }

            $this->assertNoCancelInProgress($locked);

            $fulfillment = Fulfillment::query()->where('transaction_id', $locked->id)->whereKey($fulfillmentId)->first()
                ?? throw new TransactionException('この注文の配送ではありません');

            if ($fulfillment->status !== FulfillmentStatus::Pending) {
                throw new TransactionException('この配送はもう発送済みです');
            }

            // 来店で受け渡す受渡（予約。役務）は発送しない。来店は deliverInPerson で済みにする
            if ($fulfillment->method === FulfillmentMethod::InPerson) {
                throw new TransactionException('来店で受け渡す注文は発送しません（App「予約」の画面で「来店済み」にします）');
            }
            // 送らない品の受渡（ダウンロード・チケットなど）も発送しない（払いが確かになった時に App が渡す）
            if ($fulfillment->method === FulfillmentMethod::Digital) {
                throw new TransactionException('発送しない商品（ダウンロードなど）は、発送の登録をしません（お支払いを確認した時点でお客さんに渡します）');
            }

            $this->fulfillments->ship($fulfillment, $carrier, $trackingNumber, $actor);
            $this->events->notice(new FulfillmentShipped($locked->id, $fulfillment->id), $actor);

            // ポイントは発送と支払いの両方がそろった時に付ける（代引きは入金を確かめた時に。ADR 0074）
            if (! Fulfillment::query()->where('transaction_id', $locked->id)->where('status', FulfillmentStatus::Pending)->exists() && ! self::codUnpaid($locked->id)
                && $this->points->earn($locked->id, $actor) > 0) {
                // 先に発送した品の返品・一部返金があれば、付けた分をその割合まで取り消す（ADR 0048・0068）
                [$refunded, $received] = $this->returns->progress($locked);
                $this->points->refundedPart($locked->id, $refunded, $received, $actor);
            }

            return $locked->phase === Phase::Payment ? $this->transactions->transition($locked, Phase::Fulfillment, $actor) : $locked;
        });
    }

    /**
     * 来店で受け渡した（予約の品。受渡が役務〔in_person〕だけの注文。ADR 0056）: 支払いが済んだ注文の役務の受渡を全部済みにし、
     * 取引を受渡 → 受取確認 → 精算へ進める。役務は発送しないので、発送の知らせのメール（FulfillmentShipped）は出さない。
     * 受渡が全部済んだので、会員の付く予定のポイントを同じトランザクションで付ける（ADR 0068 の 4 の「発送で付ける」に合わせる）。
     * 当日払いでまだ払われていない注文は断る（先に入金を確かめる。DirectPayments::confirm）。呼ぶ側が先に連鎖のロックを取ったトランザクションの中から呼んでよい
     */
    public function deliverInPerson(Transaction $transaction, ?string $actor = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $actor): Transaction {
            $locked = $this->lock($transaction);

            if ($locked->phase !== Phase::Payment) {
                throw new TransactionException($locked->phase === Phase::Agreement ? '支払いの前は来店済みにできません' : 'この注文は来店済みにできません');
            }

            $this->assertNoCancelInProgress($locked);

            if (self::onSiteUnpaid($locked->id) || self::codUnpaid($locked->id)) {
                throw new TransactionException('当日払いの代金を受け取ってから、来店済みにしてください。');
            }

            $open = Fulfillment::query()->where('transaction_id', $locked->id)->where('status', '!=', FulfillmentStatus::Cancelled)->get();

            if ($open->isEmpty() || $open->contains(static fn (Fulfillment $f): bool => $f->method !== FulfillmentMethod::InPerson)) {
                throw new TransactionException('来店で受け渡す品だけの注文ではありません');
            }

            foreach ($open as $fulfillment) {
                if ($fulfillment->status === FulfillmentStatus::Pending) {
                    $this->fulfillments->complete($fulfillment, $actor);
                }
            }

            if (($left = $this->fulfillments->undelivered($locked)) > 0) {
                throw new TransactionException("まだ受け渡していない商品が {$left} 点あります");
            }

            $this->points->earn($locked->id, $actor);
            $locked = $this->transactions->transition($locked, Phase::Fulfillment, $actor);
            $locked = $this->transactions->transition($locked, Phase::Acceptance, $actor);

            return $this->transactions->transition($locked, Phase::Settlement, $actor);
        });
    }

    /**
     * 役務（設置の代行など。種類が inPerson の商品だけの注文）を提供した: 店の人が管理画面の注文の「提供済みにする」で呼ぶ。
     * 済ませ方は来店で受け渡した注文と同じ（deliverInPerson）。予約の品（App「予約」）の役務の受渡は、App が枠の状態と一緒に済ませるので、ここでは断る
     */
    public function deliverService(Transaction $transaction, ?string $actor = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $actor): Transaction {
            $locked = $this->lock($transaction);

            if (! $this->isServiceOrder($locked)) {
                throw new TransactionException('サービスだけの注文ではありません');
            }

            if ($locked->phase !== Phase::Payment) {
                throw new TransactionException($locked->phase === Phase::Agreement ? '支払いの前は提供済みにできません' : 'この注文は提供済みにできません');
            }

            return $this->deliverInPerson($locked, $actor);
        });
    }

    /**
     * 役務の種類（inPerson）の商品だけの物販の注文か。商品の行が無い注文・知らない種類の商品がある注文は false
     */
    public function isServiceOrder(Transaction $transaction): bool
    {
        if ($transaction->kind !== 'sale') {
            return false;
        }

        $listingIds = $transaction->lines()->where('line_type', LineType::Item)->pluck('listing_id')->unique()->all();

        if ($listingIds === [] || in_array(null, $listingIds, true)) {
            return false;
        }

        $kinds = app(ListingKinds::class);
        $listings = Listing::query()->whereKey($listingIds)->pluck('kind');

        return $listings->count() === count($listingIds) && $listings->every(static fn (mixed $kind): bool => is_string($kind) && $kinds->inPerson($kind));
    }

    /**
     * 送らない品（受渡が digital。App「デジタルチケット」のチケットなど。ADR 0069）を渡した: 入金済みの注文の digital の受渡を全部済みにし、
     * 在庫を確定して、取引を受渡 → 受取確認 → 精算へ進める。digital では「渡した＝済み」なので、発送の知らせのメール（FulfillmentShipped）は出さない。
     * 受渡が全部済んだので、会員の付く予定のポイントを同じトランザクションで付ける（ADR 0068 の 4 の「発送で付ける」に合わせる）。
     * 呼ぶ側が先に連鎖のロックを取ったトランザクションの中から呼んでよい。digital でない受渡が残る注文は断る
     */
    public function deliverDigital(Transaction $transaction, ?string $actor = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $actor): Transaction {
            $locked = $this->lock($transaction);

            if ($locked->phase !== Phase::Payment) {
                throw new TransactionException($locked->phase === Phase::Agreement ? '入金の前は渡せません' : 'この注文は渡せません');
            }

            $this->assertNoCancelInProgress($locked);
            $open = Fulfillment::query()->where('transaction_id', $locked->id)->where('status', '!=', FulfillmentStatus::Cancelled)->get();

            if ($open->isEmpty() || $open->contains(static fn (Fulfillment $f): bool => $f->method !== FulfillmentMethod::Digital)) {
                throw new TransactionException('送らない品だけの注文ではありません');
            }

            foreach ($open as $fulfillment) {
                if ($fulfillment->status === FulfillmentStatus::Pending) {
                    $this->fulfillments->complete($fulfillment, $actor);
                }
            }

            if (($left = $this->fulfillments->undelivered($locked)) > 0) {
                throw new TransactionException("まだ渡していない商品が {$left} 点あります");
            }

            $this->points->earn($locked->id, $actor);
            $locked = $this->transactions->transition($locked, Phase::Fulfillment, $actor);
            $locked = $this->transactions->transition($locked, Phase::Acceptance, $actor);

            return $this->transactions->transition($locked, Phase::Settlement, $actor);
        });
    }

    /**
     * 送らない品を渡した（物と一緒の注文も。ADR 0084）: まだ渡していない digital の受渡を済みにする。送る品の受渡が無ければ deliverDigital と同じ。
     * 送る品があれば digital だけを済みにして段は変えない（送る品がもう全部発送済みならポイントを付ける。1 回だけ）。渡すものが無ければ何もしない
     */
    public function deliverDigitalItems(Transaction $transaction, ?string $actor = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $actor): Transaction {
            $locked = $this->lock($transaction);
            $open = Fulfillment::query()->where('transaction_id', $locked->id)->where('status', '!=', FulfillmentStatus::Cancelled)->get();

            if (! $open->contains(static fn (Fulfillment $f): bool => $f->method !== FulfillmentMethod::Digital)) {
                return $this->deliverDigital($locked, $actor);
            }

            if (! in_array($locked->phase, [Phase::Payment, Phase::Fulfillment], true)) {
                throw new TransactionException($locked->phase === Phase::Agreement ? '入金の前は渡せません' : 'この注文は渡せません');
            }

            $this->assertNoCancelInProgress($locked);

            if (self::codUnpaid($locked->id) || self::onSiteUnpaid($locked->id)) {
                throw new TransactionException('入金の前は渡せません');
            }

            $pending = $open->filter(static fn (Fulfillment $f): bool => $f->method === FulfillmentMethod::Digital && $f->status === FulfillmentStatus::Pending);

            if ($pending->isEmpty()) {
                return $locked;
            }

            foreach ($pending as $fulfillment) {
                $this->fulfillments->complete($fulfillment, $actor);
            }

            if ($locked->phase === Phase::Fulfillment && ! Fulfillment::query()->where('transaction_id', $locked->id)->where('status', FulfillmentStatus::Pending)->exists()) {
                $this->points->earn($locked->id, $actor);
            }

            return $locked;
        });
    }

    /**
     * 完了にする（お客さんに届いた）。発送済みの受渡を済みにして在庫を確定し、取引を受取確認 → 精算へ進める
     */
    public function complete(Transaction $transaction, ?string $actor = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $actor): Transaction {
            $locked = $this->lock($transaction);

            if ($locked->phase !== Phase::Fulfillment) {
                throw new TransactionException('発送済みの注文だけ完了にできます');
            }

            // 代引きは、入金を確かめてから完了にする（完了＝精算。お金が届く前に閉じない。ADR 0074）
            if (self::codUnpaid($locked->id)) {
                throw new TransactionException('代引きの入金を確認してから、完了にしてください（「代引きの入金を確認した」）。');
            }

            $this->assertNoCancelInProgress($locked);

            $open = Fulfillment::query()->where('transaction_id', $locked->id)->where('status', '!=', FulfillmentStatus::Cancelled)->get();

            if ($open->contains(static fn (Fulfillment $f): bool => $f->status === FulfillmentStatus::Pending)) {
                throw new TransactionException('まだ発送していない配送があります');
            }

            // 受渡に載っていない品が残っていれば完了にしない（一部だけ渡して完了にすると、残りの在庫を押さえたままになる）
            if (($left = $this->fulfillments->undelivered($locked)) > 0) {
                throw new TransactionException("まだ渡していない商品が {$left} 点あります");
            }

            foreach ($open as $fulfillment) {
                if ($fulfillment->status === FulfillmentStatus::Shipped) {
                    $this->fulfillments->complete($fulfillment, $actor);
                }
            }

            $locked = $this->transactions->transition($locked, Phase::Acceptance, $actor);

            return $this->transactions->transition($locked, Phase::Settlement, $actor);
        });
    }

    /**
     * 取り消す（発送の前だけ）。払われていなければ、まだ払える支払いの画面を先に閉じてから取り消す（押さえた在庫が戻る）。
     * 払われていれば 3 段で（ADR 0027）: ① ロックの中で取り消せるかを確かめ、返金の記録を残す（この間は発送と完了を断る）
     * ② ロックの外で Stripe に全額の返金を頼む ③ ロックの中で答えを記録し、succeeded なら台帳に返金を書いて取り消す。pending なら取り消し、
     * 返金は確定を待つ（台帳には書かない）。はっきり断られたら取り消さない。答えが分からなければ記録を残したまま、もう一度押すと
     * 同じ冪等キーで続きから進む（返金は 1 回）
     *
     * @return array{transaction: Transaction, refunded: int, refund_pending: int}
     */
    public function cancel(Transaction $transaction, ?string $actor = null): array
    {
        $this->assertCancellable(Transaction::query()->findOrFail($transaction->id));

        if (! $this->payments->isPaid($transaction)) {
            return $this->cancelUnpaid($transaction, $actor);
        }

        // 店が受け取った支払い（銀行振込・代引き）は、ここからは返金できない。店の人が返金を記録して、受け取った額を全部返した後に取り消す（ADR 0074）
        if (PaymentAttempt::direct($transaction->id)?->status === 'paid') {
            return $this->cancelDirectPaid($transaction, $actor);
        }

        $plan = $this->refunds->prepareCancel($transaction);

        $refund = DB::transaction(function () use ($transaction, $plan, $actor): Refund|Transaction {
            $locked = $this->lock($transaction);
            $this->assertCancellable($locked);

            // 受け取った額をもう全部返した（送料の差額の返金が払いの全部だった。ADR 0064 の 6）: 返金を頼まずに取り消す
            if ($this->refunds->activeCancel($locked->id) === null && ! $this->refunds->shippingInFlight($locked->id) && $this->refunds->cancelAmount($locked->id, $plan['attempt']) === 0) {
                return $this->close($locked, ['refunded' => 0, 'refund_pending' => 0], $actor);
            }

            return $this->refunds->openForCancel($locked, $plan['attempt'], $plan['paymentRef'], $actor);
        });

        if ($refund instanceof Transaction) {
            return ['transaction' => $refund, 'refunded' => 0, 'refund_pending' => 0];
        }

        $result = $this->refunds->send($refund, $plan['credentials'], $actor);

        // 返金の記録がある間は発送も完了もできないので、ここで取り消せなくなることは無い
        $done = DB::transaction(function () use ($transaction, $refund, $result, $actor): ?array {
            $locked = $this->lock($transaction);
            // 成功なら台帳に返金を書く（Webhook・定期の問い合わせと同じ処理。ADR 0027）
            $recorded = $this->refunds->settle($refund, $result, $actor);

            if ($recorded->status === 'failed') {
                return null;
            }

            // 額が頼んだ額と違った: 取り消さず止める（記録が残る間は発送も完了もできない。人が Stripe で確かめる）
            if ($recorded->status === 'held') {
                return ['held' => true];
            }

            $refunded = $recorded->status === 'succeeded' ? $recorded->amount : 0;
            $pending = $recorded->status === 'pending' ? $recorded->amount : 0;

            return ['transaction' => $this->close($locked, ['refunded' => $refunded, 'refund_pending' => $pending], $actor), 'refunded' => $refunded, 'refund_pending' => $pending];
        });

        if (isset($done['held'])) {
            throw PaymentUnavailableException::conflict(PaymentRefunds::HELD);
        }

        return $done ?? throw new RefundDeclined((string) Refund::query()->findOrFail($refund->id)->failure_reason);
    }

    /**
     * 店が受け取った支払い（銀行振込・代引き）の注文の取消。受け取った額を返金の記録で全部返していれば取り消す（在庫・ポイントが戻る）
     *
     * @return array{transaction: Transaction, refunded: int, refund_pending: int}
     */
    private function cancelDirectPaid(Transaction $transaction, ?string $actor): array
    {
        return DB::transaction(function () use ($transaction, $actor): array {
            $locked = $this->lock($transaction);
            $this->assertCancellable($locked);

            // お金で受け取った額（ギフトカードでの受け取りは、取消でカードへ戻す。ADR 0076）
            if ($this->ledger->netPaid($locked) - $this->giftCards->heldInLedger($locked->id) > 0) {
                throw new TransactionException('銀行振込・代金引換で受け取った代金を先にお客さんへ返金し、「返金を記録する」で返金した額を記録してから取り消してください。');
            }

            return ['transaction' => $this->close($locked, ['refunded' => 0, 'refund_pending' => 0], $actor), 'refunded' => 0, 'refund_pending' => 0];
        });
    }

    /**
     * 代引きで、まだ入金を確かめていない注文か（ADR 0074）
     */
    public static function codUnpaid(string $transactionId): bool
    {
        return PaymentAttempt::query()->where('transaction_id', $transactionId)->where('kind', 'direct')->where('method', 'cod')->where('status', 'awaiting')->exists();
    }

    /**
     * 予約の当日払いで、まだ入金を確かめていない注文か（ADR 0056）
     */
    public static function onSiteUnpaid(string $transactionId): bool
    {
        return PaymentAttempt::query()->where('transaction_id', $transactionId)->where('kind', 'direct')->where('method', 'on_site')->where('status', 'awaiting')->exists();
    }

    /**
     * 払われていない時だけ取り消す（定期便の回の注文など、仕組みが自分で取り消す時。返金の道へは進まない）。
     * 払われていた・取り消せない・取り消しの途中で支払いが届いた時は、何もせず false
     */
    public function cancelIfUnpaid(Transaction $transaction, ?string $actor = null): bool
    {
        $current = Transaction::query()->findOrFail($transaction->id);

        if (! $this->cancellable($current) || $this->payments->isPaid($current)) {
            return false;
        }

        try {
            $this->cancelUnpaid($current, $actor);
        } catch (TransactionException) {
            return false;
        }

        return true;
    }

    /**
     * 額が違って止めた取消の返金を片付ける（ADR 0048 の 4）。店の人が Stripe で確かめた後に選ぶ。
     * recorded: 確かめた額で返金を記録し（台帳にも）、止まっていた取消を終える（在庫を戻す）。
     * dismissed: 返金は無かった。注文は開いたまま（取消をもう一度押すと、新しく頼み直す）
     *
     * @param  'recorded'|'dismissed'  $outcome
     */
    public function resolveCancelRefund(Transaction $transaction, Refund $refund, string $outcome, ?int $amount, ?string $actor = null): Transaction
    {
        if ($refund->purpose !== 'cancel' || $refund->transaction_id !== $transaction->id) {
            throw new LogicException('この注文の取消の返金だけを片付ける');
        }

        return DB::transaction(function () use ($transaction, $refund, $outcome, $amount, $actor): Transaction {
            $locked = $this->lock($transaction);
            $resolved = $this->refunds->applyResolution($refund, $outcome, $amount, $actor);

            if ($resolved->resolution !== 'recorded') {
                return $locked;
            }

            return $this->close($locked, ['refunded' => $resolved->amount, 'refund_pending' => 0], $actor);
        });
    }

    /**
     * 払われていない注文の取消。まだ払える支払いの画面を先に閉じる（DB のロックの外）
     *
     * @return array{transaction: Transaction, refunded: int, refund_pending: int}
     */
    private function cancelUnpaid(Transaction $transaction, ?string $actor): array
    {
        // 預けたカードの開いたままの請求（定期便の回の本人確認待ちなど）は、先に決済代行でも取り消す。取り消せない（払われていた・処理中・
        // 答えが分からない・問い合わせられない）なら断る（取り消した注文に後で払われないように。管理画面の取消も同じ。ADR 0045）
        if (! $this->stored->releaseOpenCharges($transaction, $actor ?? 'system')) {
            throw new TransactionException(self::STORED_IN_FLIGHT);
        }

        // Stripe の画面を閉じる前に、今まさに画面を作っている途中なら断る（閉じた後で断ると、閉じた画面が記録では開いたまま残る）
        if ($this->payments->checkoutBeingMade($transaction)) {
            throw new TransactionException(self::CHECKOUT_IN_FLIGHT);
        }

        $seen = $this->payments->closeOpenCheckouts($transaction);

        $done = DB::transaction(function () use ($transaction, $actor, $seen): ?array {
            $locked = $this->lock($transaction);
            $this->assertCancellable($locked);

            if ($this->payments->isPaid($locked)) {
                throw new TransactionException('この注文には支払いが届きました。画面を読み込み直してから、もう一度お試しください。');
            }

            // 取り消した後に、預けたカードの新しい請求が始まった（今まさに請求中）: 取り消さない
            if (PaymentAttempt::query()->where('transaction_id', $locked->id)->where('kind', 'stored')->where('status', 'open')->exists()) {
                throw new TransactionException(self::STORED_IN_FLIGHT);
            }

            // 閉じた後に、お客さんが支払いの画面を開いた・開いている途中。取り消すと払える画面が残る
            if ($this->payments->checkoutInFlight($locked, $seen)) {
                return null;
            }

            // 払われずに消えた注文のクーポンは数を戻す（ADR 0054 の 6。払った後の取消では戻さない）。
            // 全額をギフトカードで払って確かになった注文（支払いの段より先）は、払った後の取消と同じに扱い、戻さない（ADR 0076）
            if ($locked->phase === Phase::Agreement || OrderGiftCard::appliedTo($locked->id) === 0) {
                $this->coupons->release($locked->id, $actor);
            }

            return ['transaction' => $this->close($locked, ['refunded' => 0, 'refund_pending' => 0], $actor), 'refunded' => 0, 'refund_pending' => 0];
        });

        if ($done === null) {
            // 断るが、Stripe で閉じた画面は記録でも閉じる（開いたまま残すと、お客さんが閉じた画面へ送られる）
            DB::transaction(function () use ($transaction, $seen, $actor): void {
                $this->events->lock();
                $closed = PaymentAttempt::query()->where('transaction_id', $transaction->id)->whereIn('id', $seen)->whereIn('status', ['open', 'awaiting'])
                    ->update(['status' => 'expired', 'updated_at' => now()]);

                if ($closed > 0) {
                    $this->events->append('payment.checkout_closed', 'transaction', $transaction->id, ['attempts_closed' => $closed], $actor);
                }
            });

            throw new TransactionException(self::CHECKOUT_IN_FLIGHT);
        }

        return $done;
    }

    /**
     * 受渡を取り消し（押さえた在庫が戻る）、まだ払える画面を期限切れにして、取引を取消にする（呼ぶ側がロックを持つ）
     *
     * @param  array{refunded: int, refund_pending: int}  $money
     */
    private function close(Transaction $locked, array $money, ?string $actor): Transaction
    {
        foreach (Fulfillment::query()->where('transaction_id', $locked->id)->where('status', FulfillmentStatus::Pending)->get() as $fulfillment) {
            $this->fulfillments->cancel($fulfillment, $actor);
        }

        // 渡し終えた digital の受渡は済みのまま残し（在庫は動かしていない）、数を取消の記録に。渡したものは取消・返金の知らせで App が止める
        $delivered = Fulfillment::query()->where('transaction_id', $locked->id)->where('method', FulfillmentMethod::Digital)->where('status', FulfillmentStatus::Completed)->count();

        // 入金待ちの払込票（コンビニ払い。ADR 0058）は、取り消す前に Stripe でも取り消してある（closeOpenCheckouts）
        $expired = PaymentAttempt::query()->where('transaction_id', $locked->id)->whereIn('status', ['open', 'awaiting'])->update(['status' => 'expired', 'updated_at' => now()]);
        $this->events->append('order.cancelled', 'transaction', $locked->id, $money + ['attempts_expired' => $expired] + ($delivered > 0 ? ['digital_delivered' => $delivered] : []), $actor);
        // 使ったポイントは戻す（払う前の取消も、払った後の取消も。ADR 0068 の 3）。発送の前なので付けた分は無い
        $this->points->release($locked->id, $actor);
        // 使ったギフトカードの額もカードへ戻す（ADR 0076）。払いが確かになっていれば台帳の「ギフトカードでの受け取り」も返金にする（期限は延ばさない）
        $this->giftCards->release($locked->id, $actor);

        return $this->transactions->transition($locked, Phase::Cancel, $actor);
    }

    /**
     * 取り消せるのは、開いている物販で、まだ発送していない（受渡が発送済み・済みになっていない）注文だけ。渡し終えた digital の受渡は数えない
     * （物と一緒の注文を発送の前に取り消せるように。digital だけの注文は渡すと精算まで進むので段で断られ、返金は返品の道）
     */
    public function cancellable(Transaction $transaction): bool
    {
        return $transaction->kind === 'sale' && $transaction->status === TransactionStatus::Open
            && in_array($transaction->phase, [Phase::Agreement, Phase::Payment], true)
            && ! Fulfillment::query()->where('transaction_id', $transaction->id)->whereIn('status', [FulfillmentStatus::Shipped, FulfillmentStatus::Completed])
                ->where('method', '!=', FulfillmentMethod::Digital)->exists();
    }

    private function assertCancellable(Transaction $transaction): void
    {
        if (! $this->cancellable($transaction)) {
            throw new TransactionException('発送の前の注文だけ取り消せます（発送した注文は返品・返金で扱います）');
        }
    }

    /**
     * 取消の返金が途中の注文は、発送も完了もしない（返金だけが残るのを防ぐ。ADR 0027）。呼ぶ側がロックを持つ
     */
    private function assertNoCancelInProgress(Transaction $locked): void
    {
        $active = $this->refunds->activeCancel($locked->id);

        if ($active?->status === 'held') {
            throw new TransactionException(PaymentRefunds::HELD);
        }

        if ($active !== null) {
            throw new TransactionException('この注文は取消と返金の処理中です。「注文を取り消す」をもう一度押すと続きから進みます（発送はできません）');
        }
    }

    /**
     * 台帳のロック → 取引の行のロック（決まりの順）。物販で、開いている取引だけ
     */
    private function lock(Transaction $transaction): Transaction
    {
        $this->events->lock();
        $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

        if ($locked->kind !== 'sale' || $locked->status !== TransactionStatus::Open) {
            throw new TransactionException('この注文は操作できません（完了しているか、物販の注文ではありません）');
        }

        return $locked;
    }
}
