<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\DemoRestockHandler;
use EcSugar\PluginPreorder\Models\PreorderListing;

/**
 * デモの店の予約の数を、作った時の残りへ戻す（ADR 0086。1 時間ごとの demo:restock から）。
 * 予約の数の上限を「作った時の上限 + 今の予約の数」にする（予約の注文はそのまま）。書き込みは管理画面の欄と同じ PreorderListings::save
 * （連鎖のロック・上限を予約の数より下げない確かめ・イベント）。受けていない・上限の無い商品は触らない
 */
final class PreorderDemoRestock implements DemoRestockHandler
{
    public function restockDemo(Listing $listing, array $built, string $actor): int
    {
        $capacity = $built['capacity'] ?? null;
        $row = PreorderListing::query()->where('listing_id', $listing->id)->first();

        if (! is_string($capacity) || preg_match('/\A[1-9][0-9]{0,5}\z/', $capacity) !== 1 || $row === null || ! $row->active) {
            return 0;
        }

        $taken = PreorderCounts::taken([$listing->id])[$listing->id] ?? 0;
        $target = min(PreorderListings::MAX_CAPACITY, (int) $capacity + $taken);

        if ($row->capacity === $target) {
            return 0;
        }

        app(PreorderListings::class)->save($listing, ['enabled' => true, 'release_date' => $row->releaseDate(), 'ends_on' => $row->endsOn(), 'capacity' => (string) $target], $actor);

        return 1;
    }
}
