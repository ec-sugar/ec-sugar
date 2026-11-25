<?php

declare(strict_types=1);

namespace App\Core\Order\Services;

use App\Core\Event\EventStore;
use App\Core\Payment\Contracts\PaymentCredentialStore;
use App\Core\Payment\Contracts\PaymentDriver;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\RefundDeclined;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 途中の返金を確かめ直す（ADR 0027。店の文脈の中で、定期の処理から）。Webhook の取りこぼしと、押し直されないまま残った返金を拾う。
 * - 答えが分からないまま（requested）古くなった返金: 同じ冪等キーで頼み直す（取消は続きから最後まで。返金は 1 回）
 * - 保留（pending）のまま古くなった返金: 決済代行に今の状態を問い合わせて確定させる
 * - 答えが分からないまま残った返品・一部返金の返金も、同じ冪等キーで続きから（ADR 0048）
 * - 返金の記録を作る前に止まった送料の差額（ADR 0064 の 6）: 差額の返金を頼み直す（recoverShipping）
 */
final class RefundReconciler
{
    public const string ACTOR = 'system:refunds';

    /** 答えが分からない返金を頼み直すまでの間（押した人が押し直す間を残す） */
    public const int REQUESTED_AFTER_MINUTES = 10;

    /** 保留の返金を問い合わせるまでの間（ふつうは Webhook で確定する） */
    public const int PENDING_AFTER_MINUTES = 30;

    public function __construct(
        private readonly EventStore $events,
        private readonly PaymentCredentialStore $gateways,
        private readonly PaymentDriver $driver,
        private readonly PaymentRefunds $refunds,
        private readonly SaleOrderService $orders,
        private readonly ShippingDifferenceRefunds $shippingRefunds,
        private readonly OrderReturns $returns,
    ) {}

    /**
     * @return string 結果（resumed・settled・declined・waiting・not_yet・no_account・closed）
     */
    public function reconcile(Refund $refund, CarbonImmutable $now): string
    {
        if ($refund->status === 'requested' && $refund->created_at->isBefore($now->subMinutes(self::REQUESTED_AFTER_MINUTES))) {
            return $this->resume($refund);
        }

        if ($refund->status === 'pending' && $refund->updated_at->isBefore($now->subMinutes(self::PENDING_AFTER_MINUTES))) {
            return $this->ask($refund);
        }

        return 'not_yet';
    }

    /**
     * 返金の記録を作る前に止まった送料の差額（ADR 0064 の 6）を拾い直す。記録の有無は ShippingDifferenceRefunds がロックの中で確かめ直すので、
     * 同じ差額をキューの仕事と同時に拾っても返金の記録は 1 つ（返金は 1 回）
     *
     * @return string 結果（resumed: 返金を頼んだ／declined: 断られた〔店のやることへ〕／waiting: 次の回へ／closed: 返すものが無くなった）
     */
    public function recoverShipping(string $transactionId): string
    {
        try {
            $after = $this->shippingRefunds->refund($transactionId, self::ACTOR);
        } catch (RefundDeclined) {
            return 'declined';
        } catch (PaymentUnavailableException) {
            return 'waiting';
        }

        return match ($after) {
            'failed' => 'declined',
            'waiting' => 'waiting',
            'none', 'closed' => 'closed',
            default => 'resumed',
        };
    }

    private function resume(Refund $refund): string
    {
        try {
            if ($refund->purpose === 'cancel') {
                $transaction = Transaction::query()->findOrFail($refund->transaction_id);

                // 取消の返金が途中のまま、取引が閉じていることは無いはず（発送も完了も断っている）。人が見る
                if ($transaction->status !== TransactionStatus::Open) {
                    return 'closed';
                }

                $this->orders->cancel($transaction, self::ACTOR);
            } elseif ($refund->purpose === 'return') {
                // 返品・一部返金（ADR 0048）: 同じ記録・同じ冪等キーで続きから（取引の状態は変えない）
                if ($this->returns->resume($refund, self::ACTOR) === 'declined') {
                    return 'declined';
                }
            } elseif ($refund->purpose === 'shipping') {
                // 送料の差額（ADR 0064 の 6）: 同じ記録・同じ冪等キーで続きから
                $after = $this->shippingRefunds->refund($refund->transaction_id, self::ACTOR);

                if ($after === 'failed') {
                    return 'declined';
                }

                if ($after === 'waiting') {
                    return 'waiting';
                }
            } else {
                $this->refunds->refundStray(StrayPayment::query()->findOrFail($refund->stray_payment_id), self::ACTOR);
            }
        } catch (RefundDeclined) {
            return 'declined';
        } catch (PaymentUnavailableException|TransactionException) {
            return 'waiting';
        }

        return 'resumed';
    }

    private function ask(Refund $refund): string
    {
        $attempt = PaymentAttempt::query()->findOrFail($refund->attempt_id);
        $credentials = $this->gateways->credentialsForAttempt($attempt);

        if ($credentials === null || $refund->provider_ref === null) {
            return 'no_account';
        }

        $result = $this->driver->retrieveRefund($credentials, $refund->provider_ref);

        if ($result === null) {
            return 'waiting';
        }

        $after = DB::transaction(function () use ($refund, $result): string {
            $this->events->lock();

            return $this->refunds->settle($refund, $result, self::ACTOR)->status;
        });

        return $after === 'pending' ? 'waiting' : 'settled';
    }
}
