<?php

declare(strict_types=1);

namespace EcSugar\PluginGift;

use App\Core\Event\EventStore;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginGift\Models\GiftOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 贈り物を取り消して全額を返金する（ADR 0055 の 6。買う人の取り消しと、期限を過ぎた贈り物）。返金は核の注文の取消（`SaleOrderService::cancel`）を通し、在庫も戻る。
 *
 * 3 段で: ① 連鎖のロック → 贈り物の行のロックの中で「取り消しの途中（cancelling）」にする（2 つ同時に走っても、ここを抜けるのは 1 つだけ。
 * この間は受け取れない）② ロックの外で核の取消（決済代行へ返金を頼む）③ ロックの中で答えを書く。返金できなかった（断られた・答えが分からない・額が違って止めた）時は
 * 「止めた（stopped）」にして、もう自動では返金しない（何度も返金しない）。店の人が注文の画面で取消を押し直すと、核が同じ冪等キーで続きから進める
 */
final class GiftCancellation
{
    public function __construct(
        private readonly EventStore $events,
        private readonly SaleOrderService $orders,
    ) {}

    /**
     * @param  bool  $expiry  期限を過ぎた贈り物の取り消し（受け取り待ちで、期限を過ぎたものだけ）。偽なら買う人の取り消し（受け取り待ちだけ）
     * @return 'cancelled'|'expired'|'stopped'
     *
     * @throws GiftRefused 取り消せる状態でない（受け取られた・もう取り消した・期限の前など）
     */
    public function cancel(string $giftId, string $actor, bool $expiry = false): string
    {
        $gift = DB::transaction(function () use ($giftId, $actor, $expiry): GiftOrder {
            $this->events->lock();
            $gift = GiftOrder::query()->whereKey($giftId)->lockForUpdate()->firstOrFail();
            $due = $gift->expires_at !== null && $gift->expires_at->lessThanOrEqualTo(CarbonImmutable::now());

            if ($gift->status !== 'waiting' || ($expiry && ! $due)) {
                throw new GiftRefused('受け取られる前の贈り物だけ取り消せます。');
            }

            $gift->forceFill(['status' => 'cancelling'])->save();
            $this->events->append('gift.cancelling', 'gift_order', $gift->id, ['transaction_id' => $gift->transaction_id, 'expiry' => $expiry], $actor);

            return $gift;
        });

        $failure = null;

        try {
            $transaction = Transaction::query()->findOrFail($gift->transaction_id);

            // 店の人がもう注文の画面で取り消していれば、返金は済んでいる（核が返金した）
            if (! $transaction->status->isClosed()) {
                $this->orders->cancel($transaction, $actor);
            }
        } catch (Throwable $e) {
            $failure = $e;
        }

        $outcome = $this->finish($gift->id, $actor, $expiry);

        if ($outcome === 'stopped') {
            // 店の人へは管理画面のホームの「やること」で知らせる（返金できなかった贈り物）。理由の文は残さず種類だけ
            Log::warning('贈り物の取り消しの返金ができず止めた', ['gift' => $gift->id, 'error' => $failure === null ? null : $failure::class]);
        } else {
            SendGiftMail::dispatch($gift->id, $expiry ? 'expired' : 'cancelled');
        }

        return $outcome;
    }

    /**
     * 取り消しの途中のまま 1 時間を過ぎたもの（処理が途中で落ちた）を「止めた」にする（自動では返金し直さない。店の人が注文の画面で確かめる）
     */
    public function stopStale(string $giftId, string $actor): bool
    {
        return DB::transaction(function () use ($giftId, $actor): bool {
            $this->events->lock();
            $gift = GiftOrder::query()->whereKey($giftId)->lockForUpdate()->first();

            if ($gift === null || $gift->status !== 'cancelling' || $gift->updated_at === null || $gift->updated_at->greaterThan(CarbonImmutable::now()->subHour())) {
                return false;
            }

            $gift->forceFill(['status' => 'stopped'])->save();
            $this->events->append('gift.cancel_stopped', 'gift_order', $gift->id, ['transaction_id' => $gift->transaction_id], $actor);

            return true;
        });
    }

    /**
     * @return 'cancelled'|'expired'|'stopped'
     */
    private function finish(string $giftId, string $actor, bool $expiry): string
    {
        return DB::transaction(function () use ($giftId, $actor, $expiry): string {
            $this->events->lock();
            $gift = GiftOrder::query()->whereKey($giftId)->lockForUpdate()->firstOrFail();
            $closed = Transaction::query()->findOrFail($gift->transaction_id)->status->isClosed();

            // 取り消せなかった（返金が断られた・答えが分からない・額が違って止めた）: 止める。注文は開いたまま（発送はできない）
            if (! $closed) {
                $gift->forceFill(['status' => 'stopped'])->save();
                $this->events->append('gift.cancel_stopped', 'gift_order', $gift->id, ['transaction_id' => $gift->transaction_id], $actor);

                return 'stopped';
            }

            $status = $expiry ? 'expired' : 'cancelled';
            $gift->forceFill(['status' => $status, 'closed_at' => CarbonImmutable::now()])->save();
            $this->events->append('gift.cancelled', 'gift_order', $gift->id, ['transaction_id' => $gift->transaction_id, 'expiry' => $expiry], $actor);

            return $status;
        });
    }
}
