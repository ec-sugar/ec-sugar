<?php

declare(strict_types=1);

namespace App\Core\Payment\Services;

use App\Core\Event\EventStore;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Payment\Contracts\PaymentCredentials;
use App\Core\Payment\Contracts\PaymentCredentialStore;
use App\Core\Payment\Contracts\PaymentDriver;
use App\Core\Payment\Models\OrderReturn;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\RefundDeclined;
use App\Core\Payment\RefundResult;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * 返金（ADR 0027）。取消のための全額の返金と、返金が要る支払い（二重に払われた・閉じた注文に届いた）の返金と、
 * 贈り物の送料の差額の返金（ADR 0064 の 6。目的 shipping。取消の返金はこの分を引いた額）と、返品・一部返金（ADR 0048。目的 return。帳簿は ReturnSettlement）。
 * どちらも、Stripe へ頼む前に返金の記録を残し（open）、DB のロックの外で頼み（send）、答えを記録する（record）。
 * 冪等キーは対象ごとなので、答えが分からずにやり直しても返金は 1 回。台帳に返金を書くのは succeeded になってから（呼ぶ側）
 */
final class PaymentRefunds
{
    /** 支払いを受けた Stripe のアカウントの設定が今は無い（鍵を貼る形から接続する形へ移した、など） */
    public const string OTHER_ACCOUNT = 'このお支払いは、今とは別の Stripe のアカウント（以前のお支払いの設定）で受け取ったため、ここからは返金できません。そのアカウントの Stripe のダッシュボードで返金してください。';

    /** 返金の額が頼んだ額と違ったので止めている */
    public const string HELD = 'Stripe の返金額が依頼した額と違ったため、この返金を止めています。Stripe のダッシュボードで返金を確認してから、注文の画面の「止めている返金を確定する」で選んでください（それまで発送と取消はできません）。';

    /** 止めた返金の片付け */
    public const string NOT_HELD = 'この返金は止めていないため、確定できません（画面を読み込み直してください）。';

    public const string RESOLVE_AMOUNT = 'Stripe で確認した返金の額を、1 円以上で入力してください。';

    public const string RESOLVE_OVER_PAID = 'この注文で受け取った額を超える返金は記録できません。Stripe で返金の額を確認してください。';

    /** 送料の差額の返金の答えを待っている（ADR 0064 の 6） */
    public const string SHIPPING_IN_FLIGHT = '送料の差額の返金について、Stripe の応答を待っています。少し待ってから、もう一度お試しください（止めている返金があれば、先に確定してください）。';

    /** 店が受け取った支払い（銀行振込・代金引換）の返金の記録（ADR 0074） */
    public const string NOT_DIRECT = '銀行振込・代金引換で受け取った注文だけ、ここで返金を記録できます（Stripe で受け取った注文は、取消か Stripe で返金してください）。';

    public const string DIRECT_AMOUNT = '返金した額を 1 円以上で入力してください。';

    public const string DIRECT_OVER_PAID = 'この注文で受け取った額を超える返金は記録できません。';

    /** 返品・一部返金の返金の答えを待っている（ADR 0048） */
    public const string RETURN_IN_FLIGHT = '返品・一部返金の返金について、Stripe の応答を待っています。完了してから、もう一度お試しください（止めている返金があれば、先に確定してください）。';

    /** 頼み直せる差額の返金が無い（ADR 0064 の作って決めたこと） */
    public const string SHIPPING_NOT_DECLINED = '再依頼できる送料の差額の返金がありません（返金の処理中か、すでに完了しています）。画面を読み込み直してください。';

    public function __construct(
        private readonly EventStore $events,
        private readonly PaymentCredentialStore $gateways,
        private readonly PaymentDriver $driver,
        private readonly LedgerService $ledger,
        private readonly ReturnSettlement $returns,
    ) {}

    /**
     * 取消の返金の下ごしらえ（DB のロックの外。Stripe に問い合わせることがある）: 払われた支払い、そのアカウントの鍵、支払いの ID
     *
     * @return array{attempt: PaymentAttempt, credentials: PaymentCredentials, paymentRef: string}
     */
    public function prepareCancel(Transaction $transaction): array
    {
        $attempt = PaymentAttempt::query()->where('transaction_id', $transaction->id)->where('status', 'paid')->first()
            ?? throw new PaymentUnavailableException('Stripe 以外で受け取った支払いの取消は、まだできません。');
        $credentials = $this->gateways->credentialsForAttempt($attempt) ?? throw new PaymentUnavailableException(self::OTHER_ACCOUNT);

        // 支払いの ID（pi_）は、支払いの仕訳の参照に残している。無ければ支払いの画面から引く（ギフトカードでの受け取りの仕訳は除く。ADR 0076）
        $reference = LedgerJournal::query()->where('transaction_id', $transaction->id)->where('type', JournalType::Payment)
            ->where(static fn ($q) => $q->whereNull('reference')->orWhere('reference', 'not like', 'gift\_card:%'))->orderBy('occurred_at')->value('reference');
        $paymentRef = is_string($reference) && str_starts_with($reference, 'pi_') ? $reference : $this->driver->retrieveCheckout($credentials, $attempt->provider_ref)?->paymentRef;

        if (! is_string($paymentRef)) {
            throw new PaymentUnavailableException('Stripe の支払いが見つからないため返金できません。Stripe のダッシュボードで確認してください。');
        }

        return ['attempt' => $attempt, 'credentials' => $credentials, 'paymentRef' => $paymentRef];
    }

    /**
     * 取消の返金を記録に残す（呼ぶ側が台帳と取引のロックを持つ）。途中の返金があれば、それを続ける（同じ冪等キーで頼み直す）
     */
    public function openForCancel(Transaction $locked, PaymentAttempt $attempt, string $paymentRef, ?string $actor = null): Refund
    {
        $active = $this->active('cancel', $locked->id);

        if ($active?->status === 'held') {
            throw PaymentUnavailableException::conflict(self::HELD);
        }

        if ($active !== null) {
            return $active;
        }

        // 送料の差額の返金（ADR 0064 の 6）が途中なら待つ（答えが出る前に全額を頼むと、二重に返す）。済んだ分は引いて頼む
        if ($this->shippingInFlight($locked->id)) {
            throw PaymentUnavailableException::conflict(self::SHIPPING_IN_FLIGHT);
        }

        return $this->create('cancel', $locked->id, null, $attempt, $paymentRef, $this->cancelAmount($locked->id, $attempt), "refund-cancel-{$locked->id}", $actor);
    }

    /**
     * 送料の差額の返金が途中か止めている（ADR 0064 の 6）
     */
    public function shippingInFlight(string $transactionId): bool
    {
        return $this->active('shipping', $transactionId) !== null;
    }

    /**
     * 取消で返す額: 受け取った額から、もう返した（途中も含む）送料の差額・記録した外の返金などを引いた額（ADR 0064 の 6・0048）
     */
    public function cancelAmount(string $transactionId, PaymentAttempt $attempt): int
    {
        return max(0, min($attempt->amount, $this->returns->room(Transaction::query()->findOrFail($transactionId))));
    }

    /**
     * 送料の差額の返金を記録に残す（ADR 0064 の 6。呼ぶ側が台帳と取引のロックを持つ）。1 注文に 1 回（断られた返金の後だけ頼み直せる）。
     * 額は受け取った額まで。前の記録があればそれを返す
     */
    public function openForShipping(Transaction $locked, PaymentAttempt $attempt, string $paymentRef, int $amount, ?string $actor = null): ?Refund
    {
        $existing = Refund::query()->where('purpose', 'shipping')->where('transaction_id', $locked->id)->where('status', '<>', 'failed')->first();

        if ($existing !== null) {
            return $existing;
        }

        // 受け取った額のうち、まだ返していない分まで（返金の合計は受け取った額を越えない。ADR 0048）
        $amount = min($amount, $attempt->amount, $this->returns->room($locked));

        return $amount < 1 ? null : $this->create('shipping', $locked->id, null, $attempt, $paymentRef, $amount, "refund-shipping-{$locked->id}", $actor);
    }

    /**
     * 断られた送料の差額の返金を、店の人が頼み直す（ADR 0064 の作って決めたこと。呼ぶ側が台帳と取引のロックを持つ）。
     * 断られた記録は Stripe の答え（failed と断りの符号）のまま残して片付けの印（retried）を付け、新しい返金の記録を作る（冪等キーは断られた数の続きの番号）。
     * 一意の索引（refunds_one_shipping）は失敗した行を数えないので、生きた差額の返金は 1 注文に 1 つのまま。途中の返金があれば頼み直さない
     */
    public function retryShipping(Transaction $locked, PaymentAttempt $attempt, string $paymentRef, int $amount, ?string $actor = null): Refund
    {
        if (Refund::query()->where('purpose', 'shipping')->where('transaction_id', $locked->id)->where('status', '<>', 'failed')->exists()) {
            throw PaymentUnavailableException::conflict(self::SHIPPING_NOT_DECLINED);
        }

        $declined = Refund::query()->where('purpose', 'shipping')->where('transaction_id', $locked->id)->where('status', 'failed')->whereNull('resolution')->lockForUpdate()->get();
        $amount = min($amount, $attempt->amount, $this->returns->room($locked));

        if ($declined->isEmpty() || $amount < 1) {
            throw PaymentUnavailableException::conflict(self::SHIPPING_NOT_DECLINED);
        }

        foreach ($declined as $refund) {
            $refund->forceFill(['resolution' => 'retried', 'resolved_at' => CarbonImmutable::now()])->save();
            $this->events->append('payment.refund_resolved', 'transaction', $locked->id, [
                'refund' => $refund->id, 'purpose' => 'shipping', 'resolution' => 'retried', 'amount' => $refund->amount, 'requested_amount' => $refund->amount,
            ], $actor);
        }

        return $this->create('shipping', $locked->id, null, $attempt, $paymentRef, $amount, "refund-shipping-{$locked->id}", $actor);
    }

    /**
     * 返品・一部返金の返金を記録に残す（ADR 0048。呼ぶ側が台帳と取引のロックを持ち、返金の 1 回と返金の行を作った後）。
     * provider: 決済代行に頼む前の記録（requested）。冪等キーは返金の 1 回ごと（頼み直しは続きの番号）。
     * outside: 店が外で返した記録。その場で済み（succeeded）にして、台帳に返金と調整の仕訳を書く
     *
     * @param  'provider'|'outside'  $channel
     */
    public function openForReturn(Transaction $locked, OrderReturn $return, string $channel, ?PaymentAttempt $attempt, ?string $paymentRef, ?string $actor = null): Refund
    {
        if ($channel === 'provider' && ($attempt === null || $paymentRef === null)) {
            throw new LogicException('決済代行に依頼する返金には支払いが要る');
        }

        $tries = Refund::query()->where('return_id', $return->id)->count();
        $refund = Refund::create([
            'transaction_id' => $locked->id,
            'attempt_id' => $attempt?->id,
            'return_id' => $return->id,
            'purpose' => 'return',
            'channel' => $channel,
            'amount' => $return->amount,
            'currency' => strtolower($attempt->currency ?? $locked->currency),
            'payment_ref' => $channel === 'provider' ? $paymentRef : null,
            'idempotency_key' => "refund-return-{$return->id}".($tries === 0 ? '' : '-'.($tries + 1)),
            'status' => $channel === 'outside' ? 'succeeded' : 'requested',
            'settled_at' => $channel === 'outside' ? CarbonImmutable::now() : null,
        ]);
        $this->events->append('payment.refund_requested', 'transaction', $locked->id, ['refund' => $refund->id, 'purpose' => 'return', 'amount' => $refund->amount, 'channel' => $channel], $actor);

        if ($channel === 'outside') {
            $this->events->append('payment.refund_recorded', 'transaction', $locked->id, [
                'refund' => $refund->id, 'purpose' => 'return', 'status' => 'succeeded', 'amount' => $refund->amount, 'reason' => null,
            ], $actor);
            $this->returns->settle($refund, $actor);
        }

        return $refund;
    }

    /**
     * 取り消した注文の後から断られた取消の返金を、新しい返金の記録で頼み直す（RefundRetries から。呼ぶ側が台帳と取引のロックを持ち、断られた記録を片付けた後）。
     * 冪等キーは断られた数の続きの番号
     */
    public function openCancelRetry(Transaction $locked, PaymentAttempt $attempt, string $paymentRef, int $amount, ?string $actor = null): Refund
    {
        return $this->create('cancel', $locked->id, null, $attempt, $paymentRef, $amount, "refund-cancel-{$locked->id}", $actor);
    }

    /**
     * 取消の返金が途中か（この間は発送と完了を断る。ADR 0027）
     */
    public function cancelInProgress(string $transactionId): bool
    {
        return $this->activeCancel($transactionId) !== null;
    }

    /**
     * 取消の返金で、途中か止めているもの（無ければ null）
     */
    public function activeCancel(string $transactionId): ?Refund
    {
        return $this->active('cancel', $transactionId);
    }

    /**
     * 決済代行に返金を頼む（DB のロックの外）。はっきり断られたら記録を failed にして RefundDeclined を投げ直す。
     * 答えが分からなければ記録は requested のまま PaymentUnavailableException（やり直すと同じ冪等キーで続きから）
     */
    public function send(Refund $refund, PaymentCredentials $credentials, ?string $actor = null): RefundResult
    {
        try {
            return $this->driver->refund($credentials, (string) $refund->payment_ref, $refund->amount, $refund->idempotency_key, $refund->id);
        } catch (RefundDeclined $e) {
            DB::transaction(function () use ($refund, $e, $actor): void {
                $this->events->lock();
                $this->settle($refund, new RefundResult('', 'failed', $e->reason), $actor);
            });

            throw $e;
        }
    }

    /**
     * 代行の答えで返金の状態を進め、成功なら返金を済ませる（呼ぶ側の DB トランザクションの中で、台帳のロックの後）。
     * 取消の返金は台帳に返金を書く。返金が要る支払いの返金は「返金済み」にする（このお金は台帳に入れていない）。
     * 進め方: requested → pending・succeeded・failed、pending → succeeded・failed。succeeded の後の failed は reversed（台帳は自動で戻さない。要対応）。
     * 代行の言う額が頼んだ額と違えば、requested・pending から held（止めて人が見る。台帳に書かない・発送も取消も断る）。held は自動では進めない。
     * それ以外（同じ状態・終わった状態）は何もしない（同じ知らせが何度来ても 1 回だけ）
     */
    public function settle(Refund $refund, RefundResult $result, ?string $actor = null): Refund
    {
        $before = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail()->status;
        $locked = $this->record($refund, $result, $actor);

        if ($before !== 'succeeded' && $locked->status === 'succeeded') {
            if ($locked->purpose === 'cancel' || $locked->purpose === 'shipping') {
                $this->ledger->recordRefund(Transaction::query()->findOrFail($locked->transaction_id), $locked->amount, PaymentRoute::Platform, $locked->provider_ref, $actor);
                $this->returns->announce($locked, $actor);
            } elseif ($locked->purpose === 'return') {
                // 返品・一部返金: 返金と税率ごとの調整の仕訳、ポイントの割合、App への知らせ（ADR 0048）
                $this->returns->settle($locked, $actor);
            } elseif ($locked->purpose === 'stray') {
                $stray = StrayPayment::query()->whereKey($locked->stray_payment_id)->lockForUpdate()->firstOrFail();

                if ($stray->status === 'pending') {
                    $stray->forceFill(['status' => 'refunded', 'refund_ref' => $locked->provider_ref, 'refunded_at' => CarbonImmutable::now()])->save();
                    $this->events->append('payment.stray_refunded', 'transaction', $stray->transaction_id, ['stray' => $stray->id, 'reason' => $stray->reason, 'amount' => $stray->amount], $actor);
                }
            }
        }

        return $locked;
    }

    /**
     * 決済代行の返金の知らせ（Webhook。ADR 0027）を当てる（呼ぶ側の DB トランザクションの中で、台帳のロックの後）。
     * 知らない返金は、こちらが頼んだばかりで答えの届く前の返金（同じ支払い・同じ額で途中のもの）ならそれに当てる。
     * そうでなければ決済代行の画面で直接した返金なので、取引に引き当てて止めた返金（held・external_refund）として残す（ADR 0027 の追記）。
     * 台帳には書かない（人が確かめてから）。取引に引き当てられなければ受け取るだけ
     *
     * @return string 結果（refund_succeeded・refund_pending・refund_failed・refund_reversed・external_refund_held・unknown_refund・refund_unchanged）
     */
    public function applyNotice(RefundResult $result, ?string $actor = null): string
    {
        $refund = Refund::query()->where('provider_ref', $result->ref)->first() ?? $this->byReference($result) ?? $this->awaitingAnswer($result);

        if ($refund === null) {
            return $this->recordExternal($result, $actor);
        }

        $before = $refund->status;
        $after = $this->settle($refund, $result, $actor)->status;

        return $after === $before ? 'refund_unchanged' : "refund_{$after}";
    }

    /**
     * 止めた返金を片付ける（ADR 0048 の 4）。取消の返金以外（返金が要る支払い・外でした返金）の入口。
     * 取消の返金は注文の取消まで終える必要があるので、SaleOrderService::resolveCancelRefund から applyResolution を呼ぶ
     *
     * @param  'recorded'|'dismissed'  $outcome
     */
    public function resolve(Refund $refund, string $outcome, ?int $amount, ?string $actor = null): Refund
    {
        if ($refund->purpose === 'cancel') {
            throw new LogicException('取消の返金の片付けは注文の取消と一緒に（SaleOrderService::resolveCancelRefund）');
        }

        return DB::transaction(function () use ($refund, $outcome, $amount, $actor): Refund {
            $this->events->lock();

            return $this->applyResolution($refund, $outcome, $amount, $actor);
        });
    }

    /**
     * 片付けを記録する（呼ぶ側の DB トランザクションの中で、台帳のロックの後）。
     * recorded: 店の人が Stripe で確かめた額で返金を成功にする。取消の返金と外でした返金は台帳に返金を書く（返金が要る支払いへの返金は台帳に入れていないお金なので「返金済み」にするだけ）。
     * dismissed: 返金は無かった（失敗として閉じる。取消の返金なら、取消をもう一度押すと新しく頼み直せる）
     *
     * @param  'recorded'|'dismissed'  $outcome
     */
    public function applyResolution(Refund $refund, string $outcome, ?int $amount, ?string $actor): Refund
    {
        $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'held') {
            throw PaymentUnavailableException::conflict(self::NOT_HELD);
        }

        $before = $locked->amount;

        if ($outcome === 'dismissed') {
            $locked->forceFill(['status' => 'failed', 'failure_reason' => 'dismissed', 'settled_at' => CarbonImmutable::now(), 'resolution' => 'dismissed', 'resolved_at' => CarbonImmutable::now()])->save();
        } else {
            if ($amount === null || $amount < 1) {
                throw PaymentUnavailableException::conflict(self::RESOLVE_AMOUNT);
            }

            $stray = $locked->stray_payment_id !== null
                ? StrayPayment::query()->whereKey($locked->stray_payment_id)->lockForUpdate()->first()
                : StrayPayment::query()->where('payment_ref', $locked->payment_ref)->lockForUpdate()->first();

            // 受け取った額を越える返金は記録しない（DB のトリガーも断る。先に確かめて、見せてよい文で返す。返金が要る支払いは台帳の外のお金なので数えない）
            if ($stray === null && $amount > $this->returns->room(Transaction::query()->findOrFail($locked->transaction_id), $locked->id)) {
                throw PaymentUnavailableException::conflict(self::RESOLVE_OVER_PAID);
            }

            $locked->forceFill(['amount' => $amount, 'status' => 'succeeded', 'failure_reason' => null, 'settled_at' => CarbonImmutable::now(), 'resolution' => 'recorded', 'resolved_at' => CarbonImmutable::now()])->save();

            if ($stray !== null) {
                // 返金が要る支払いへの返金（このお金は台帳に入れていない）: 返金済みにするだけ
                if ($stray->status === 'pending') {
                    $stray->forceFill(['status' => 'refunded', 'refund_ref' => $locked->provider_ref, 'refunded_at' => CarbonImmutable::now()])->save();
                    $this->events->append('payment.stray_refunded', 'transaction', $stray->transaction_id, ['stray' => $stray->id, 'reason' => $stray->reason, 'amount' => $stray->amount], $actor);
                }
            } elseif ($locked->purpose === 'return') {
                // 額が違って止めた返品・一部返金の返金: 確かめた額に返金の行をそろえ（差は金額だけの行）、台帳・ポイント・App への知らせ（ADR 0048）
                try {
                    $this->returns->settleResolved($locked, $actor);
                } catch (InvalidArgumentException) {
                    throw PaymentUnavailableException::conflict(self::RESOLVE_OVER_PAID);
                }
            } elseif ($locked->purpose === 'external') {
                // 外でした返金: 金額だけの返金の行を足し、台帳に返金と税率ごとの調整の仕訳、ポイントの割合（ADR 0048）
                try {
                    $this->returns->settleExternal($locked, $actor);
                } catch (InvalidArgumentException) {
                    throw PaymentUnavailableException::conflict(self::RESOLVE_OVER_PAID);
                }
            } else {
                try {
                    $transaction = Transaction::query()->findOrFail($locked->transaction_id);
                    $this->ledger->recordRefund($transaction, $amount, PaymentRoute::Platform, $locked->provider_ref, $actor);
                } catch (InvalidArgumentException) {
                    throw PaymentUnavailableException::conflict(self::RESOLVE_OVER_PAID);
                }

                $this->returns->announce($locked, $actor);
            }
        }

        $this->events->append('payment.refund_resolved', 'transaction', $locked->transaction_id, [
            'refund' => $locked->id, 'purpose' => $locked->purpose, 'resolution' => $locked->resolution, 'amount' => $locked->amount, 'requested_amount' => $before,
        ], $actor);

        return $locked;
    }

    /**
     * 店が受け取った支払い（銀行振込・代金引換。ADR 0074）の返金を記録する（店の人が自分で振り込んだ後。呼ぶ側が台帳と取引のロックを持つ）。
     * 決済代行を通らないので、外でした返金（external）を「記録した」（recorded）として残し、すぐに帳簿を書く（経路は店の直接の受け取り）。
     * 帳簿・ポイント・App への知らせは決済代行の画面でした返金を確かめた時と同じ（ReturnSettlement::settleExternal。発送の後なら税率ごとの調整、一部ならポイントは割合。ADR 0048）。
     * 額は受け取って、まだ返していない額まで（DB のトリガーも断る）
     */
    public function recordDirect(Transaction $locked, int $amount, ?string $actor = null): Refund
    {
        $attempt = PaymentAttempt::query()->where('transaction_id', $locked->id)->where('kind', 'direct')->where('status', 'paid')->first()
            ?? throw PaymentUnavailableException::conflict(self::NOT_DIRECT);

        if ($amount < 1) {
            throw PaymentUnavailableException::conflict(self::DIRECT_AMOUNT);
        }

        if ($amount > $this->returns->room($locked)) {
            throw PaymentUnavailableException::conflict(self::DIRECT_OVER_PAID);
        }

        $now = CarbonImmutable::now();
        $ref = 'direct-refund-'.strtolower((string) Str::ulid());
        $refund = Refund::create([
            'transaction_id' => $locked->id,
            'attempt_id' => $attempt->id,
            'purpose' => 'external',
            'amount' => $amount,
            'currency' => $attempt->currency,
            'payment_ref' => $attempt->provider_ref,
            'idempotency_key' => $ref,
            'provider_ref' => $ref,
            'status' => 'succeeded',
            'settled_at' => $now,
            'resolution' => 'recorded',
            'resolved_at' => $now,
        ]);
        $this->events->append('payment.refund_recorded', 'transaction', $locked->id, ['refund' => $refund->id, 'amount' => $amount, 'method' => $attempt->method], $actor);
        $this->returns->settleExternal($refund, $actor, PaymentRoute::Direct);

        return $refund;
    }

    /**
     * 頼んだ時に代行の返金に載せた、こちらの返金の記録の ID で当てる（ADR 0048。同じ支払い・同じ額の返金の取り違えを防ぐ）。
     * 代行の返金の ID がまだ無い記録で、支払いが同じものだけ（ほかの返金の ID を載せた知らせでは動かさない）
     */
    private function byReference(RefundResult $result): ?Refund
    {
        if ($result->refundId === null) {
            return null;
        }

        return Refund::query()->whereKey($result->refundId)->whereNull('provider_ref')
            ->when($result->paymentRef !== null, static fn ($q) => $q->where('payment_ref', $result->paymentRef))->first();
    }

    /**
     * こちらが頼んで答えがまだ届いていない返金（DB のロックの外で頼むので、知らせの方が先に届くことがある）
     */
    private function awaitingAnswer(RefundResult $result): ?Refund
    {
        if ($result->paymentRef === null || $result->amount === null) {
            return null;
        }

        return Refund::query()->where('payment_ref', $result->paymentRef)->where('amount', $result->amount)
            ->where('status', 'requested')->whereNull('provider_ref')->orderBy('created_at')->first();
    }

    /**
     * 決済代行の画面で直接した返金を、止めた返金として残す。支払いは台帳の受領の記録（取引の支払い）か、返金が要る支払いの記録から引く
     */
    private function recordExternal(RefundResult $result, ?string $actor): string
    {
        if ($result->ref === '' || $result->paymentRef === null || $result->amount === null || $result->amount <= 0) {
            Log::warning('決済代行の知らない返金を取引に引き当てられない（支払いの ID か額が無い）', ['provider_ref' => $result->ref]);

            return 'unknown_refund';
        }

        $stray = StrayPayment::query()->where('payment_ref', $result->paymentRef)->first();
        $transactionId = $stray->transaction_id ?? LedgerJournal::query()
            ->where('type', JournalType::Payment)->where('reference', $result->paymentRef)->value('transaction_id');
        $attempt = match (true) {
            $stray !== null => PaymentAttempt::query()->find($stray->attempt_id),
            is_string($transactionId) => PaymentAttempt::query()->where('transaction_id', $transactionId)->where('status', 'paid')->orderByDesc('paid_at')->first(),
            default => null,
        };

        if (! is_string($transactionId) || $attempt === null) {
            Log::warning('決済代行の知らない返金を取引に引き当てられない（この店の支払いに無い）', ['provider_ref' => $result->ref]);

            return 'unknown_refund';
        }

        $refund = Refund::create([
            'transaction_id' => $transactionId,
            'attempt_id' => $attempt->id,
            'purpose' => 'external',
            'amount' => $result->amount,
            'currency' => $attempt->currency,
            'payment_ref' => $result->paymentRef,
            'idempotency_key' => 'external-'.$result->ref,
            'provider_ref' => $result->ref,
            'status' => 'held',
            'failure_reason' => 'external_refund',
        ]);
        $this->events->append('payment.external_refund_held', 'transaction', $transactionId, ['refund' => $refund->id, 'amount' => $refund->amount, 'reported_status' => $result->status], $actor);

        return 'external_refund_held';
    }

    /**
     * 答えを記録する（settle の中で）。状態の進め方は settle を参照
     */
    private function record(Refund $refund, RefundResult $result, ?string $actor): Refund
    {
        $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
        // 額が違う（起きないはず）: Stripe に別の額の返金があるかもしれない。お金を返したうえに品物も送らないよう、止めて人が見る
        $mismatch = $result->amount !== null && $result->amount !== $locked->amount;

        if ($mismatch) {
            Log::error('決済代行の返金の額が依頼した額と違う', ['refund' => $locked->id, 'provider_ref' => $result->ref, 'requested' => $locked->amount, 'reported' => $result->amount]);
        }

        $next = match (true) {
            // 終わった後の知らせは額が違っても通常の進め方（成功の後の失敗は reversed）。額の違いは上の記録に残る
            $mismatch && in_array($locked->status, ['requested', 'pending'], true) => 'held',
            $locked->status === 'requested' => $result->status,
            $locked->status === 'pending' && in_array($result->status, ['succeeded', 'failed'], true) => $result->status,
            $locked->status === 'succeeded' && $result->status === 'failed' => 'reversed',
            default => null,
        };

        if ($next === null) {
            return $locked;
        }

        if ($next === 'reversed') {
            // 成功した返金が後から戻された: お金はこちらに戻った。台帳の返金は人が確かめて直す
            Log::error('Stripe の返金が成功の後に戻された', ['refund' => $locked->id, 'provider_ref' => $locked->provider_ref, 'reason' => $result->failureReason]);
        }

        $locked->forceFill([
            'provider_ref' => $locked->provider_ref ?? ($result->ref === '' ? null : $result->ref),
            'status' => $next,
            'failure_reason' => match ($next) {
                'held' => 'amount_mismatch',
                'failed', 'reversed' => $result->failureReason,
                default => null,
            },
            'settled_at' => in_array($next, ['succeeded', 'failed', 'reversed'], true) ? ($locked->settled_at ?? CarbonImmutable::now()) : null,
        ])->save();
        $this->events->append('payment.refund_recorded', 'transaction', $locked->transaction_id, [
            'refund' => $locked->id, 'purpose' => $locked->purpose, 'status' => $locked->status, 'amount' => $locked->amount, 'reason' => $locked->failure_reason,
        ], $actor);

        return $locked;
    }

    /**
     * 返金が要る支払いを、Stripe で返金する（全額。DB のロックの外で Stripe へ）。
     * このお金は台帳に入れていないので、返金も台帳に入れず、ここと記録で追う。返金済みにするのは succeeded になってから
     */
    public function refundStray(StrayPayment $stray, ?string $actor = null): StrayPayment
    {
        if ($stray->status !== 'pending') {
            throw PaymentUnavailableException::conflict('この支払いはすでに返金済みです。');
        }

        $attempt = PaymentAttempt::query()->findOrFail($stray->attempt_id);
        $credentials = $this->gateways->credentialsForAttempt($attempt) ?? throw new PaymentUnavailableException(self::OTHER_ACCOUNT);

        if ($stray->payment_ref === null || ! str_starts_with($stray->payment_ref, 'pi_')) {
            throw new PaymentUnavailableException('Stripe の支払いの ID が無いため、ここからは返金できません。Stripe のダッシュボードで返金してください。');
        }

        $refund = DB::transaction(function () use ($stray, $attempt, $actor): Refund {
            $this->events->lock();
            $locked = StrayPayment::query()->whereKey($stray->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw PaymentUnavailableException::conflict('この支払いはすでに返金済みです。');
            }

            $active = $this->active('stray', $locked->transaction_id, $locked->id);

            if ($active?->status === 'pending') {
                throw PaymentUnavailableException::conflict('この支払いの返金は、Stripe で手続き中です。');
            }

            if ($active?->status === 'held') {
                throw PaymentUnavailableException::conflict(self::HELD);
            }

            return $active ?? $this->create('stray', $locked->transaction_id, $locked->id, $attempt, (string) $locked->payment_ref, $locked->amount, "refund-stray-{$locked->id}", $actor);
        });

        $result = $this->send($refund, $credentials, $actor);

        [$stray, $recorded] = DB::transaction(function () use ($stray, $refund, $result, $actor): array {
            $this->events->lock();
            $recorded = $this->settle($refund, $result, $actor);

            return [StrayPayment::query()->findOrFail($stray->id), $recorded];
        });

        if ($recorded->status === 'failed') {
            throw new RefundDeclined((string) $recorded->failure_reason);
        }

        if ($recorded->status === 'held') {
            throw PaymentUnavailableException::conflict(self::HELD);
        }

        return $stray;
    }

    /**
     * 対象の途中の返金（requested・pending）
     */
    private function active(string $purpose, string $transactionId, ?string $strayId = null): ?Refund
    {
        return Refund::query()->where('purpose', $purpose)->where('transaction_id', $transactionId)
            ->when($strayId !== null, static fn ($query) => $query->where('stray_payment_id', $strayId))
            ->whereIn('status', Refund::ACTIVE)->first();
    }

    /**
     * 新しい返金の記録。前にはっきり断られた返金があれば、冪等キーに回数を付ける（Stripe が前の断りを返し続けないように）
     */
    private function create(string $purpose, string $transactionId, ?string $strayId, PaymentAttempt $attempt, string $paymentRef, int $amount, string $baseKey, ?string $actor): Refund
    {
        $failed = Refund::query()->where('purpose', $purpose)->where('transaction_id', $transactionId)
            ->when($strayId !== null, static fn ($query) => $query->where('stray_payment_id', $strayId))
            ->where('status', 'failed')->count();
        $refund = Refund::create([
            'transaction_id' => $transactionId,
            'attempt_id' => $attempt->id,
            'stray_payment_id' => $strayId,
            'purpose' => $purpose,
            'amount' => $amount,
            'currency' => $attempt->currency,
            'payment_ref' => $paymentRef,
            'idempotency_key' => $failed === 0 ? $baseKey : $baseKey.'-'.($failed + 1),
            'status' => 'requested',
        ]);
        $this->events->append('payment.refund_requested', 'transaction', $transactionId, ['refund' => $refund->id, 'purpose' => $purpose, 'amount' => $amount], $actor);

        return $refund;
    }
}
