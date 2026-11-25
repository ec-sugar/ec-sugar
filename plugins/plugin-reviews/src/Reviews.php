<?php

declare(strict_types=1);

namespace EcSugar\PluginReviews;

use App\Core\Event\EventStore;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginReviews\Models\Review;
use Illuminate\Support\Facades\DB;

/**
 * レビューを書く（お客さん）・公開する・非公開にする・消す・返信する（店の人）。書くのはここだけ。
 * どれも連鎖のロック（EventStore::lock()）の後にその行のロックを取り、同じトランザクションでイベントを残す
 * （review.written・review.published・review.hidden・review.deleted・review.replied）。
 * イベントの中身は ID・星・状態だけ（題・本文・表示名・返信の文は入れない。文は表にあり、記録には「何が起きたか」だけを残す）
 */
final class Reviews
{
    /** お客さんの操作の記録の actor（個人情報を入れない） */
    public const string CUSTOMER = 'storefront';

    public function __construct(
        private readonly EventStore $events,
        private readonly PluginSettings $settings,
    ) {}

    /**
     * お客さんが書く。書ける注文か・注文の商品か・もう書いていないかは、ロックの中で確かめ直す
     *
     * @throws ReviewRefused
     */
    public function write(string $orderId, string $listingId, ReviewInput $input): Review
    {
        return DB::transaction(function () use ($orderId, $listingId, $input): Review {
            $this->events->lock();
            $order = Transaction::query()->whereKey($orderId)->first();

            if ($order === null || ! ReviewableOrders::writable($order)) {
                throw new ReviewRefused('このご注文には、まだレビューを書けません（発送後に書けます）。');
            }

            if (! isset(ReviewableOrders::listings($order)[$listingId])) {
                throw new ReviewRefused('このご注文の商品ではありません。');
            }

            if (Review::query()->where('transaction_id', $order->id)->where('listing_id', $listingId)->exists()) {
                throw new ReviewRefused('この商品のレビューは、すでに投稿いただいています。');
            }

            $now = $this->publishesAtOnce();
            $review = Review::query()->create([
                'transaction_id' => $order->id,
                'listing_id' => $listingId,
                'rating' => $input->rating,
                'title' => $input->title,
                'body' => $input->body,
                'display_name' => $input->displayName,
                'status' => $now ? 'published' : 'pending',
                'published_at' => $now ? CarbonImmutable::now() : null,
            ]);
            $this->events->append('review.written', 'review', $review->id, ['listing_id' => $listingId, 'rating' => $input->rating, 'status' => $review->status], self::CUSTOMER);

            return $review;
        });
    }

    public function publish(string $id, string $actor): void
    {
        $this->change($id, ['pending', 'hidden'], 'published', 'review.published', $actor);
    }

    public function hide(string $id, string $actor): void
    {
        $this->change($id, ['pending', 'published'], 'hidden', 'review.hidden', $actor);
    }

    /**
     * 消す。行は残して文・表示名・返信を空にする（同じ注文の同じ商品にもう一度書かせない。中身は残さない）
     */
    public function delete(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $review = $this->locked($id);
            $review->fill(['status' => 'deleted', 'title' => null, 'body' => null, 'display_name' => null, 'reply' => null, 'replied_at' => null])->save();
            $this->events->append('review.deleted', 'review', $review->id, ['listing_id' => $review->listing_id], $actor);
        });
    }

    /**
     * 店からの返信を書く・直す・消す（null で消す）。同じなら何もしない
     */
    public function reply(string $id, ?string $reply, string $actor): void
    {
        DB::transaction(function () use ($id, $reply, $actor): void {
            $this->events->lock();
            $review = $this->locked($id);

            if ($review->reply === $reply) {
                return;
            }

            $review->fill(['reply' => $reply, 'replied_at' => $reply === null ? null : CarbonImmutable::now()])->save();
            $this->events->append('review.replied', 'review', $review->id, ['listing_id' => $review->listing_id, 'cleared' => $reply === null], $actor);
        });
    }

    /**
     * 店の設定が「すぐ出す」か（既定は「店が公開するまで出さない」）
     */
    public function publishesAtOnce(): bool
    {
        return ($this->settings->publicValues('reviews')['publish'] ?? 'manual') === 'immediate';
    }

    /**
     * @param  list<string>  $from
     */
    private function change(string $id, array $from, string $to, string $event, string $actor): void
    {
        DB::transaction(function () use ($id, $from, $to, $event, $actor): void {
            $this->events->lock();
            $review = $this->locked($id);

            if (! in_array($review->status, $from, true)) {
                throw new AdminActionRefused('このレビューは、ほかの人が先に更新しました。画面を再読み込みしてください。');
            }

            $before = $review->status;
            // 公開した日時は最初に公開した時のまま（非公開にしてから出し直しても変えない）
            $review->fill(['status' => $to, 'published_at' => $to === 'published' ? ($review->published_at ?? CarbonImmutable::now()) : $review->published_at])->save();
            $this->events->append($event, 'review', $review->id, ['listing_id' => $review->listing_id, 'from' => $before], $actor);
        });
    }

    /**
     * 消していないレビューの行のロック（無い・消したものは AdminRecordNotFound）
     */
    private function locked(string $id): Review
    {
        $review = Review::query()->whereKey(strtolower($id))->lockForUpdate()->first();

        if ($review === null || $review->status === 'deleted') {
            throw new AdminRecordNotFound;
        }

        return $review;
    }
}
