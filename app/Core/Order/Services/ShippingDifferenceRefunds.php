<?php

declare(strict_types=1);

namespace App\Core\Order\Services;

use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Order\Models\OrderShipping;
use App\Core\Payment\Contracts\PaymentCredentials;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\RefundDeclined;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Payment\Services\PaymentService;
use App\Core\Points\Services\PointService;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 贈り物の送料の差額を返金する（ADR 0064 の 6、ADR 0055 の 7）。受け取りで差額が決まった（OrderShippingCharges::settle）後に、キューの仕事と返金の見回りから呼ぶ。
 * 取消の返金と同じ 3 段（ADR 0027）: ① ロックの中で返金の記録を残す ② ロックの外で決済代行へ頼む ③ ロックの中で答えを記録する（成功なら台帳に返金）。
 * 同じ注文に 1 回（記録が残っていれば続きから。冪等キーは注文ごと）。はっきり断られたら失敗として残し、自動では頼み直さない（店のホームのやることに出て、
 * 店の人が注文の画面の「差額をもう一度返金する」で頼み直す。retry）。記録を作る前に止まった差額は、返金の見回りが拾い直す（stranded）。
 * 現金で返せない差額（ポイントで送料を払った贈り物。合計 0 円の注文・払った現金が差額より少ない時の残り）は、使ったポイントの数までポイントで戻す（ADR 0068）。
 * それでも残る額は、使ったギフトカードへ戻す（理由 shipping で 1 回だけ。期限は延ばさない。ADR 0076）。戻した内訳と、それでも残る額を記録（order.shipping_rest_returned。
 * 注文に 1 回）に残し、残る額があればホームのやることに出す（店の人が直接返す）
 */
final class ShippingDifferenceRefunds
{
    /** 受け取りから、見回りが拾い直すまでの間（その前はキューの仕事に任せる。仕事のやり直しの間〔60 秒・600 秒〕より長く） */
    public const int STRANDED_AFTER_MINUTES = 15;

    /** 現金で返せない差額をポイントとギフトカードで戻した記録（unreturned はそれでも残った額。ホームのやること） */
    public const string REST_RETURNED = 'order.shipping_rest_returned';

    public function __construct(
        private readonly EventStore $events,
        private readonly PaymentRefunds $refunds,
        private readonly PaymentService $payments,
        private readonly OrderShippingCharges $charges,
        private readonly PointService $points,
        private readonly GiftCardService $giftCards,
    ) {}

    /**
     * @return string 結果（none: 差額・払いが無い／closed: 取り消した注文〔取消の返金が残りを返す〕／succeeded・pending・held: 返金の状態／failed: 断られた／waiting: 答えが分からない・今は頼めない）
     */
    public function refund(string $transactionId, string $actor): string
    {
        $transaction = Transaction::query()->findOrFail($transactionId);
        $difference = $this->charges->difference($transactionId);

        if ($difference < 1) {
            return 'none';
        }

        if ($transaction->status->isClosed()) {
            return 'closed';
        }

        // 払いの無い注文（ポイントや値引きで 0 円）: 現金は返せないので、差額をポイントで戻す（使ったポイントの数まで。1 回だけ）
        if (! $this->payments->isPaid($transaction)) {
            DB::transaction(function () use ($transactionId, $difference, $actor): void {
                $this->events->lock();
                $locked = Transaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();

                if (! $locked->status->isClosed()) {
                    $this->returnRest($locked, $difference, $actor);
                }
            });

            return 'none';
        }

        try {
            $plan = $this->refunds->prepareCancel($transaction);
        } catch (PaymentUnavailableException $e) {
            // 別のアカウントで受けた・支払いが見つからない・Stripe に届かない: 記録はまだ無い。キューの仕事のやり直しと返金の見回り（stranded）が拾い直す
            Log::warning('送料の差額を返金できない（支払いの準備）', ['transaction' => $transactionId, 'error' => $e->getMessage()]);

            return 'waiting';
        }

        $refund = DB::transaction(function () use ($transactionId, $plan, $difference, $actor): Refund|string|null {
            $this->events->lock();
            $locked = Transaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();

            // 取り消した・取消の返金が途中: 取消が残りを全部返す
            if ($locked->status->isClosed() || $this->refunds->activeCancel($locked->id) !== null) {
                return null;
            }

            // 返品・一部返金の返金が途中なら待つ（同じ支払いに途中の返金を 2 つ作らない。知らせの取り違えと額の重なりを防ぐ。ADR 0048）
            if (self::returnInFlight($locked->id)) {
                return 'waiting';
            }

            $refund = $this->refunds->openForShipping($locked, $plan['attempt'], $plan['paymentRef'], $difference, $actor);
            // 払った現金より差額が大きい（ポイント・ギフトカードで送料を払った）: 現金で返せない残りをポイント → ギフトカードで戻す（1 回だけ）
            $this->returnRest($locked, $difference - (int) ($refund->amount ?? 0), $actor);

            return $refund;
        });

        return match (true) {
            $refund === null => 'closed',
            is_string($refund) => $refund,
            default => $this->send($refund, $plan['credentials'], $actor),
        };
    }

    /**
     * 断られた差額の返金を、店の人が頼み直す（注文の画面の「差額をもう一度返金する」。お金の段階・パスキーの直前の再確認は経路が掛ける）。
     * 断られた記録は片付け済み（retried）にし、新しい返金を頼む。頼めない時は見せてよい文の PaymentUnavailableException
     *
     * @return string 新しい返金の状態（succeeded・pending・held・failed・waiting）
     */
    public function retry(string $transactionId, string $actor): string
    {
        $transaction = Transaction::query()->findOrFail($transactionId);
        $difference = $this->charges->difference($transactionId);

        if ($difference < 1 || ! $this->payments->isPaid($transaction) || ! $this->retryable($transaction)) {
            throw PaymentUnavailableException::conflict(PaymentRefunds::SHIPPING_NOT_DECLINED);
        }

        // 別のアカウントで受けた・Stripe に届かない: 見せてよい文のまま返す（まだ何も変えていない）
        $plan = $this->refunds->prepareCancel($transaction);

        $refund = DB::transaction(function () use ($transactionId, $plan, $difference, $actor): Refund {
            $this->events->lock();
            $locked = Transaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();

            // 取り消した注文は、取消の返金が残りを全部返した（返せていない差額も含む）
            if ($locked->status->isClosed() || $this->refunds->activeCancel($locked->id) !== null) {
                throw PaymentUnavailableException::conflict(PaymentRefunds::SHIPPING_NOT_DECLINED);
            }

            // 返品・一部返金の返金が途中なら、済んでから（ADR 0048）
            if (self::returnInFlight($locked->id)) {
                throw PaymentUnavailableException::conflict(PaymentRefunds::RETURN_IN_FLIGHT);
            }

            return $this->refunds->retryShipping($locked, $plan['attempt'], $plan['paymentRef'], $difference, $actor);
        });

        return $this->send($refund, $plan['credentials'], $actor);
    }

    /**
     * 返品・一部返金の返金が途中か止めている（ADR 0048）
     */
    public static function returnInFlight(string $transactionId): bool
    {
        return Refund::query()->where('transaction_id', $transactionId)->where('purpose', 'return')->whereIn('status', Refund::ACTIVE)->exists();
    }

    /**
     * 店の人が頼み直せるか（断られて片付けていない差額の返金があり、途中・済んだ差額の返金が無く、注文が閉じていない）。画面のボタンの目安で、頼み直す時はロックの中で確かめ直す
     */
    public function retryable(Transaction $transaction): bool
    {
        if ($transaction->status->isClosed()) {
            return false;
        }

        $refunds = Refund::query()->where('purpose', 'shipping')->where('transaction_id', $transaction->id)->get(['status', 'resolution']);

        return $refunds->contains(static fn (Refund $r): bool => $r->status === 'failed' && $r->resolution === null)
            && ! $refunds->contains(static fn (Refund $r): bool => $r->status !== 'failed');
    }

    /**
     * 返金の記録を作る前に止まった差額（受け取りで差額が決まったのに差額の返金の記録が 1 つも無い、払われて閉じていない注文）。
     * キューの仕事が届かなかった・支払いの準備で止まったものを、返金の見回りが拾い直す（古い順に $limit 件まで）。
     * 断られた記録のある注文は拾わない（自動では頼み直さない。店の人が頼み直す）。拾い直しても二重にならないのは、記録の一意の索引と openForShipping（ロックの中で前の記録を返す）が守る
     *
     * @return list<string> 取引の ID
     */
    public static function stranded(CarbonImmutable $now, int $limit): array
    {
        return OrderShipping::query()
            ->whereNotNull('settled_fee')->whereColumn('fee', '>', 'settled_fee')
            ->where('updated_at', '<', $now->utc()->subMinutes(self::STRANDED_AFTER_MINUTES))
            ->whereNotExists(static fn ($q) => $q->from('refunds')->whereColumn('refunds.transaction_id', 'order_shipping.transaction_id')->where('refunds.purpose', 'shipping'))
            ->whereExists(static fn ($q) => $q->from('payment_attempts')->whereColumn('payment_attempts.transaction_id', 'order_shipping.transaction_id')->where('payment_attempts.status', 'paid'))
            ->whereExists(static fn ($q) => $q->from('transactions')->whereColumn('transactions.id', 'order_shipping.transaction_id')
                ->whereNotIn('transactions.status', [TransactionStatus::Cancelled->value, TransactionStatus::Refunded->value]))
            ->groupBy('transaction_id')->orderByRaw('MIN(updated_at)')->limit($limit)
            ->pluck('transaction_id')->map(static fn (mixed $id): string => (string) $id)->values()->all();
    }

    /**
     * 現金で返せない差額を、使ったポイント → 使ったギフトカードの順に戻す（呼ぶ側がロックを持つ）。注文に 1 回だけ（記録 order.shipping_rest_returned で見分ける。
     * 差額の返金を見回りが頼み直しても、2 度目は何もしない）。戻した内訳と、それでも残る額を記録に残す
     */
    private function returnRest(Transaction $locked, int $rest, string $actor): void
    {
        if ($rest < 1 || DomainEvent::query()->where('aggregate_id', $locked->id)->where('type', self::REST_RETURNED)->exists()) {
            return;
        }

        $points = $this->points->creditShipping($locked->id, $rest, $actor);
        $card = $rest - $points > 0 ? $this->giftCards->release($locked->id, $actor, 'shipping', $rest - $points) : 0;
        $this->events->append(self::REST_RETURNED, 'transaction', $locked->id, ['points' => $points, 'gift_card' => $card, 'unreturned' => $rest - $points - $card], $actor);
    }

    /**
     * ② ロックの外で決済代行へ頼み、③ 答えを記録する（記録が requested のときだけ。ほかは今の状態を返す）
     */
    private function send(Refund $refund, PaymentCredentials $credentials, string $actor): string
    {
        if ($refund->status !== 'requested') {
            return $refund->status;
        }

        try {
            $result = $this->refunds->send($refund, $credentials, $actor);
        } catch (RefundDeclined) {
            return 'failed';
        } catch (PaymentUnavailableException) {
            // 答えが分からない: 記録は requested のまま。返金の見回りが同じ冪等キーで頼み直す
            return 'waiting';
        }

        return DB::transaction(function () use ($refund, $result, $actor): string {
            $this->events->lock();

            return $this->refunds->settle($refund, $result, $actor)->status;
        });
    }
}
