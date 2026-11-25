<?php

declare(strict_types=1);

namespace App\Core\Payment\Services;

use App\Core\Event\EventStore;
use App\Core\Payment\Models\OrderReturn;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * 断られた返金のやり直し（ADR 0048 の 3。PaymentRefunds が 600 行を越えるので役で分けた）。
 * 返品・一部返金の返金と、取り消した注文の後から断られた取消の返金を、新しい冪等キーで頼み直す。断られた記録は決済代行の答えのまま残し、頼み直した印（retried）を付ける
 */
final class RefundRetries
{
    /** 返金のやり直し（ADR 0048 の 3） */
    public const string NOT_RETRYABLE = '再依頼できる返金がありません（返金の処理中か、すでに完了しているか、再依頼済みです）。画面を読み込み直してください。';

    /** 頼み直す額が、まだ返せる余りを越える（ほかの返金が先に入った） */
    public const string NO_ROOM = 'この注文で受け取った額のうち、まだ返金していない額が足りないため再依頼できません（ほかの返金が先に行われました）。返金の記録を確認してください。';

    public function __construct(
        private readonly EventStore $events,
        private readonly PaymentRefunds $refunds,
        private readonly ReturnSettlement $books,
    ) {}

    /**
     * 断られた返品・一部返金の返金と、取り消した注文の後から断られた取消の返金を、店の人が頼み直す（ADR 0048 の 3。呼ぶ側が台帳と取引のロックを持つ）。
     * 断られた記録は決済代行の答えのまま残して頼み直した印（retried）を付け、新しい返金の記録を作る（新しい冪等キー）。
     * 返品は外で返したと記録することもできる（outside。その場で済み）。取消の頼み直しは決済代行へだけ
     *
     * @param  'provider'|'outside'  $channel
     */
    public function retryDeclined(Transaction $locked, Refund $declined, string $channel, ?PaymentAttempt $attempt, ?string $paymentRef, ?string $actor = null): Refund
    {
        $failed = Refund::query()->whereKey($declined->id)->where('transaction_id', $locked->id)->lockForUpdate()->first();

        if ($failed === null || ! $this->retryable($failed)) {
            throw PaymentUnavailableException::conflict(self::NOT_RETRYABLE);
        }

        if ($failed->purpose === 'cancel' && $channel !== 'provider') {
            throw PaymentUnavailableException::conflict(self::NOT_RETRYABLE);
        }

        // 受け取った額のうち、まだ返せる余りを越えては頼まない（DB のトリガーも断る。先に確かめて見せてよい文で返す）
        if ($failed->amount > $this->books->room($locked)) {
            throw PaymentUnavailableException::conflict(self::NO_ROOM);
        }

        // 途中の返品の返金は 1 注文に 1 つ（DB の一意の索引も守る）
        if ($failed->purpose === 'return' && Refund::query()->where('transaction_id', $locked->id)->where('purpose', 'return')->whereIn('status', Refund::ACTIVE)->exists()) {
            throw PaymentUnavailableException::conflict(self::NOT_RETRYABLE);
        }

        $failed->forceFill(['resolution' => 'retried', 'resolved_at' => CarbonImmutable::now()])->save();
        $this->events->append('payment.refund_resolved', 'transaction', $locked->id, [
            'refund' => $failed->id, 'purpose' => $failed->purpose, 'resolution' => 'retried', 'amount' => $failed->amount, 'requested_amount' => $failed->amount,
        ], $actor);

        if ($failed->purpose === 'return') {
            return $this->refunds->openForReturn($locked, OrderReturn::query()->findOrFail($failed->return_id), $channel, $attempt, $paymentRef, $actor);
        }

        if ($attempt === null || $paymentRef === null) {
            throw new LogicException('取消の再依頼には支払いが要る');
        }

        return $this->refunds->openCancelRetry($locked, $attempt, $paymentRef, $failed->amount, $actor);
    }

    /**
     * 頼み直せる断られた返金か（画面のボタンの目安。頼み直す時はロックの中で確かめ直す）:
     * 返品の返金で、同じ返金の 1 回に生きた返金が無い（額が違って止めた返金を「無かった」で閉じたもの〔dismissed〕も頼み直せる）。
     * 取消の返金で、注文はもう取り消し済み・ほかの取消の返金が途中でも済んでもいない
     */
    public function retryable(Refund $refund): bool
    {
        if ($refund->status !== 'failed' || ($refund->resolution !== null && ! ($refund->purpose === 'return' && $refund->resolution === 'dismissed'))) {
            return false;
        }

        if ($refund->purpose === 'return') {
            return ! Refund::query()->where('return_id', $refund->return_id)->where('status', '<>', 'failed')->exists();
        }

        if ($refund->purpose === 'cancel') {
            return Transaction::query()->whereKey($refund->transaction_id)->where('status', TransactionStatus::Cancelled)->exists()
                && ! Refund::query()->where('transaction_id', $refund->transaction_id)->where('purpose', 'cancel')->where('status', '<>', 'failed')->exists();
        }

        return false;
    }
}
