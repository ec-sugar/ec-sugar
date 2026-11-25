<?php

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Plugin\Contracts\GiftHandler;
use App\Core\Plugin\Gift\GiftNote;
use App\Core\Plugin\Gift\GiftShare;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 贈り物の注文（ADR 0055）の核の側。贈り物の口（GiftHandler）を持つ App をこの店で使っている時だけ、注文の入力に「贈り物として送る」を出す。
 *
 * - 贈り物の注文の印は、注文の確定と同じトランザクションのイベント order.gift_placed（中身は空。差出人とメッセージは App が暗号化して持つ）。
 *   App を使うのをやめても、印は残る（発送の知らせ・納品書で値段を出さない）
 * - 見せ方を App に聞いて落ちても、注文の画面とメールは描く（贈り物の欄を出さないだけ）
 */
final class Gifts
{
    public const string PLACED = 'order.gift_placed';

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
        private readonly EventStore $events,
    ) {}

    /**
     * この店で使っている贈り物の App の口（無ければ null）
     */
    public function handler(): ?GiftHandler
    {
        $plugin = $this->plugins->giftPlugin();

        return $plugin !== null && $this->states->enabled($plugin->name) ? $plugin->gift : null;
    }

    public function available(): bool
    {
        return $this->handler() !== null;
    }

    /**
     * 注文の確定の中で呼ぶ（連鎖のロックの後）。印を残し、App に差出人とメッセージを渡す。App が無ければ GiftUnavailable
     */
    public function place(string $transactionId, GiftNote $note, string $actor): void
    {
        $handler = $this->handler() ?? throw new GiftUnavailable;
        $this->events->append(self::PLACED, 'transaction', $transactionId, [], $actor);
        $handler->placed($transactionId, $note, $actor);
    }

    /**
     * 贈り物の注文か（値段を出さない印。App を使うのをやめた後も）
     */
    public function isGift(string $transactionId): bool
    {
        return DomainEvent::query()->where('aggregate_type', 'transaction')->where('aggregate_id', $transactionId)->where('type', self::PLACED)->exists();
    }

    /**
     * 払いが確かになった（注文の知らせの前に呼ぶ）。贈り物の注文なら App が受け取りのリンクを作る
     */
    public function paid(string $transactionId): void
    {
        if ($this->isGift($transactionId)) {
            $this->handler()?->paid($transactionId);
        }
    }

    /**
     * 買う人に見せる様子（贈り物でない・App を使っていない・App が落ちたら null）
     */
    public function share(string $transactionId, bool $private): ?GiftShare
    {
        if (! $this->isGift($transactionId)) {
            return null;
        }

        try {
            return $this->handler()?->share($transactionId, $private);
        } catch (Throwable $e) {
            Log::error('贈り物の状況を出せなかった', ['transaction' => $transactionId, 'error' => $e::class]);

            return null;
        }
    }
}
