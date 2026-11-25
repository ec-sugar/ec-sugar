<?php

declare(strict_types=1);

namespace EcSugar\PluginGift;

use App\Core\Event\EventStore;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginGift\Models\GiftOrder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 贈り物の毎時の処理（ADR 0055 の 5・6。`gifts:run`、使う店だけ）。何度流しても同じ結果（どの操作も行のロックの中で状態を確かめ直す）。
 *
 * 1. 払われたのにリンクが無い贈り物にリンクを作る（払いの確定の時に作れなかった分）
 * 2. 店の人が注文の画面で取り消した贈り物を「取り消した」に合わせる
 * 3. 期限の 3 日前に、買う人へ知らせる（1 回だけ。期限が 3 日より短い店では知らせない）
 * 4. 期限を過ぎてもお届け先の無い贈り物を取り消して全額を返金する（GiftCancellation。返金できなければ止めて、店の人に知らせる）
 * 5. 取り消しの途中のまま 1 時間を過ぎたものを止める
 *
 * App を使うのをやめた店でも、片付ける贈り物が残っていれば同じように回す（`hasLeftover`。受け取りの画面は止まるので、期限で返金して終える）
 */
final class GiftRounds
{
    public const string ACTOR = 'system:gifts';

    public const int REMIND_DAYS = 3;

    /** 1 回で扱う数の上限（残りは次の回） */
    private const int BATCH = 200;

    public function __construct(
        private readonly EventStore $events,
        private readonly GiftOrders $gifts,
        private readonly GiftCancellation $cancellation,
    ) {}

    /**
     * 使うのをやめた店でも片付ける贈り物があるか（払いの確認待ち・受け取り待ち・取り消しの途中。期限での返金を続けるため）
     */
    public static function hasLeftover(): bool
    {
        return GiftOrder::query()->whereIn('status', ['preparing', 'waiting', 'cancelling'])->exists();
    }

    /**
     * @return array<string, int> 種類ごとの数
     */
    public function run(CarbonImmutable $now): array
    {
        $done = ['linked' => 0, 'synced' => 0, 'reminded' => 0, 'expired' => 0, 'stopped' => 0];

        foreach (GiftOrder::query()->where('status', 'preparing')->limit(self::BATCH)->pluck('transaction_id') as $transactionId) {
            // 払われた: 決済代行で払われた・合計 0 円・全額をギフトカードで払って確かになった（ADR 0076）
            $transaction = Transaction::query()->find((string) $transactionId);
            $settled = $transaction !== null && $transaction->phase !== Phase::Agreement && OrderGiftCard::dueOf($transaction) === 0;

            if (PaymentAttempt::paid((string) $transactionId) || (int) $transaction?->total_amount === 0 || $settled) {
                $done['linked'] += $this->gifts->paid((string) $transactionId, self::ACTOR) === null ? 0 : 1;
            }
        }

        foreach (GiftOrder::query()->whereIn('status', ['preparing', 'waiting', 'claimed', 'stopped'])->whereIn('transaction_id', Transaction::query()->whereIn('status', [TransactionStatus::Cancelled, TransactionStatus::Refunded])->select('id'))->limit(self::BATCH)->pluck('id') as $id) {
            $done['synced'] += $this->closeWithOrder((string) $id) ? 1 : 0;
        }

        foreach (GiftOrder::query()->where('status', 'waiting')->whereNull('reminded_at')->where('expires_at', '>', $now)->where('expires_at', '<=', $now->addDays(self::REMIND_DAYS))->limit(self::BATCH)->pluck('id') as $id) {
            $done['reminded'] += $this->remind((string) $id, $now) ? 1 : 0;
        }

        foreach (GiftOrder::query()->where('status', 'waiting')->where('expires_at', '<=', $now)->orderBy('expires_at')->limit(self::BATCH)->pluck('id') as $id) {
            try {
                $outcome = $this->cancellation->cancel((string) $id, self::ACTOR, expiry: true);
                $done[$outcome === 'stopped' ? 'stopped' : 'expired']++;
            } catch (GiftRefused) {
                // ほかの処理が先に取り消した・受け取られた
            } catch (Throwable $e) {
                report($e);
            }
        }

        foreach (GiftOrder::query()->where('status', 'cancelling')->where('updated_at', '<', $now->subHour())->limit(self::BATCH)->pluck('id') as $id) {
            $done['stopped'] += $this->cancellation->stopStale((string) $id, self::ACTOR) ? 1 : 0;
        }

        return $done;
    }

    /**
     * 期限の 3 日前の知らせ（受け取り待ちで、まだ知らせていない時だけ。リンクができた時にもう 3 日を切っていた贈り物には送らない）
     */
    private function remind(string $id, CarbonImmutable $now): bool
    {
        $sent = DB::transaction(function () use ($id, $now): bool {
            $this->events->lock();
            $gift = GiftOrder::query()->whereKey($id)->lockForUpdate()->first();

            if ($gift === null || $gift->status !== 'waiting' || $gift->reminded_at !== null || $gift->expires_at === null || $gift->expires_at->lessThanOrEqualTo($now)) {
                return false;
            }

            $short = $gift->expires_at->subDays(self::REMIND_DAYS)->lessThanOrEqualTo(CarbonImmutable::parse($gift->getAttribute('created_at')));
            $gift->forceFill(['reminded_at' => $now])->save();
            $this->events->append('gift.reminded', 'gift_order', $gift->id, ['transaction_id' => $gift->transaction_id, 'sent' => ! $short], self::ACTOR);

            return ! $short;
        });

        if ($sent) {
            SendGiftMail::dispatch($id, 'expiring');
        }

        return $sent;
    }

    /**
     * 店の人が注文の画面で取り消した（返金は核が済ませた）贈り物を「取り消した」にする
     */
    private function closeWithOrder(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $this->events->lock();
            $gift = GiftOrder::query()->whereKey($id)->lockForUpdate()->first();
            $closed = $gift === null ? false : Transaction::query()->findOrFail($gift->transaction_id)->status->isClosed();

            if ($gift === null || ! $closed || ! in_array($gift->status, ['preparing', 'waiting', 'claimed', 'stopped'], true)) {
                return false;
            }

            $gift->forceFill(['status' => 'cancelled', 'closed_at' => CarbonImmutable::now()])->save();
            $this->events->append('gift.closed_with_order', 'gift_order', $gift->id, ['transaction_id' => $gift->transaction_id], self::ACTOR);

            return true;
        });
    }
}
