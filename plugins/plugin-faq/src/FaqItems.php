<?php

declare(strict_types=1);

namespace EcSugar\PluginFaq;

use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use EcSugar\PluginFaq\Models\FaqItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * よくある質問を足す・直す・並べ替える・消す。書くのはここだけ。
 * どれも連鎖のロック（EventStore::lock()）の後にその行のロックを取り、同じトランザクションでイベントを残す（faq.created・faq.updated・faq.moved・faq.deleted）。
 * イベントの中身は ID と真偽・変えた欄の名前だけ（質問と答えの文は入れない。文は表にあり、記録には「何を変えたか」だけを残す）。
 * 呼ぶのは管理画面の App の画面（FaqAdminPage）だけで、値の形は核が先に確かめてから渡す（AdminForms）
 */
final class FaqItems
{
    /** 1 つの店に置ける質問の数（ホストする店でも表が膨らまないように） */
    public const int MAX_ITEMS = 200;

    public const int QUESTION_MAX = 200;

    public const int ANSWER_MAX = 2000;

    /** 「全部の商品」の選択肢の値（商品の ID と重ならない） */
    public const string ALL = 'all';

    public function __construct(private readonly EventStore $events) {}

    /**
     * @param  array<string, bool|string|null>  $values  question・answer・listing・published（核が形を確かめた後）
     */
    public function create(array $values, string $actor): string
    {
        return DB::transaction(function () use ($values, $actor): string {
            $this->events->lock();

            if (FaqItem::query()->count() >= self::MAX_ITEMS) {
                throw ValidationException::withMessages(['values' => '質問は '.self::MAX_ITEMS.' 個までです。使わない質問を削除してから追加してください。']);
            }

            $row = self::row($values);
            $item = FaqItem::query()->create($row + ['position' => (int) FaqItem::query()->max('position') + 1]);
            $this->events->append('faq.created', 'faq_item', $item->id, ['listing_id' => $item->listing_id, 'published' => $item->published], $actor);

            return $item->id;
        });
    }

    /**
     * @param  array<string, bool|string|null>  $values
     */
    public function update(string $id, array $values, string $actor): void
    {
        DB::transaction(function () use ($id, $values, $actor): void {
            $this->events->lock();
            $item = FaqItem::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;
            $row = self::row($values);
            $changed = array_keys(array_filter($row, static fn (mixed $value, string $key): bool => $item->getAttribute($key) !== $value, ARRAY_FILTER_USE_BOTH));

            if ($changed === []) {
                return;
            }

            $item->fill($row)->save();
            $this->events->append('faq.updated', 'faq_item', $item->id, ['changed' => $changed, 'listing_id' => $item->listing_id, 'published' => $item->published], $actor);
        });
    }

    /**
     * 1 つ上（up）か下（down）の質問と並びを入れ替える。端なら何もしない
     */
    public function move(string $id, string $direction, string $actor): void
    {
        DB::transaction(function () use ($id, $direction, $actor): void {
            $this->events->lock();
            $item = FaqItem::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;
            $up = $direction === 'up';
            $neighbor = FaqItem::query()
                ->where('position', $up ? '<' : '>', $item->position)
                ->orderBy('position', $up ? 'desc' : 'asc')
                ->lockForUpdate()
                ->first();

            if ($neighbor === null) {
                return;
            }

            // 並びの一意はトランザクションの終わりに確かめる（DEFERRABLE）ので、2 行をそのまま入れ替えてよい
            [$item->position, $neighbor->position] = [$neighbor->position, $item->position];
            $item->save();
            $neighbor->save();
            $this->events->append('faq.moved', 'faq_item', $item->id, ['direction' => $up ? 'up' : 'down', 'swapped_with' => $neighbor->id], $actor);
        });
    }

    public function delete(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $item = FaqItem::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;
            $item->delete();
            $this->events->append('faq.deleted', 'faq_item', $item->id, ['listing_id' => $item->listing_id], $actor);
        });
    }

    /**
     * 欄の値を表の行にする。商品は書く時にも店にあるかを確かめ直す（選んだ後に消された商品を指さない）
     *
     * @param  array<string, bool|string|null>  $values
     * @return array{question: string, answer: string, listing_id: ?string, published: bool}
     */
    private static function row(array $values): array
    {
        $listing = is_string($values['listing'] ?? null) ? $values['listing'] : self::ALL;
        $listingId = $listing === self::ALL ? null : strtolower($listing);

        if ($listingId !== null && ! Listing::query()->whereKey($listingId)->exists()) {
            throw ValidationException::withMessages(['listing' => 'この商品は見つかりませんでした。選び直してください。']);
        }

        return [
            'question' => (string) ($values['question'] ?? ''),
            'answer' => (string) ($values['answer'] ?? ''),
            'listing_id' => $listingId,
            'published' => ($values['published'] ?? false) === true,
        ];
    }
}
