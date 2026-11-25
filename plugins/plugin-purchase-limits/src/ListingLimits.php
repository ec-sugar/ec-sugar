<?php

declare(strict_types=1);

namespace EcSugar\PluginPurchaseLimits;

use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use EcSugar\PluginPurchaseLimits\Models\ListingLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 商品ごとの「1 回のご注文の上限」を決める。状態を変えるのはここだけ（モデルを直に書き換えない）。
 * DB のトランザクションの中で、最初に連鎖のロック（EventStore::lock()）を取り、その後に行のロック（lockForUpdate）を取り、
 * 同じトランザクションでイベント（purchase_limits.set・purchase_limits.cleared）を残す（conventions.md の 3、ADR 0012）。
 * イベントの中身は商品の ID と数だけ（店の人の書いた文・個人情報は入れない。ADR 0010）。核の商品（listings）は読むだけ
 */
final class ListingLimits
{
    /** 上限の下と上（DB の CHECK と同じ） */
    public const int MIN = 1;

    public const int MAX = 999;

    public function __construct(private readonly EventStore $events) {}

    /**
     * 商品の欄から保存する。null なら、その商品の上限をやめる（行を消す）
     *
     * @throws ValidationException 範囲の外の数のとき（欄の名前で返す）
     */
    public function save(Listing $listing, ?int $perOrder, string $actor): void
    {
        if ($perOrder !== null && ($perOrder < self::MIN || $perOrder > self::MAX)) {
            throw ValidationException::withMessages(['per_order' => self::rangeMessage()]);
        }

        DB::transaction(function () use ($listing, $perOrder, $actor): void {
            $this->events->lock();
            $row = ListingLimit::query()->whereKey($listing->id)->lockForUpdate()->first();

            if ($perOrder === null) {
                if ($row !== null) {
                    $row->delete();
                    $this->events->append('purchase_limits.cleared', 'purchase_limits_listing', $listing->id, ['listing_id' => $listing->id], $actor);
                }

                return;
            }

            // 同じ数の送り直しでは、記録を増やさない
            if ($row !== null && $row->per_order === $perOrder) {
                return;
            }

            $row ??= new ListingLimit(['listing_id' => $listing->id]);
            $row->fill(['per_order' => $perOrder])->save();
            $this->events->append('purchase_limits.set', 'purchase_limits_listing', $listing->id, ['listing_id' => $listing->id, 'per_order' => $perOrder], $actor);
        });
    }

    public static function rangeMessage(): string
    {
        return '上限は '.self::MIN.'〜'.self::MAX.' の数字で入れてください。';
    }
}
