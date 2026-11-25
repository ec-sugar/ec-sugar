<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\PreorderHandler;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Plugin\Preorder\PreorderUnavailable;
use EcSugar\PluginPreorder\Models\PreorderItem;
use EcSugar\PluginPreorder\Models\PreorderListing;

/**
 * 予約販売の核の口（ADR 0073）。ショップの窓口に発売日の前の商品の決まりを渡し、注文の確定の錠の中で予約の数を数え直して残す
 */
final class PreorderCheckout implements PreorderHandler
{
    /** 断る文（%s は商品名） */
    public const string FULL = '「%s」は予約の上限の数に達したため、受付を終えました。カートの内容をご確認ください。';

    public const string CLOSED = '「%s」の予約の受付を終えました。カートの内容をご確認ください。';

    /**
     * @param  list<string>  $listingIds
     * @return array<string, PreorderTerms>
     */
    public function terms(array $listingIds): array
    {
        $today = PreorderTerms::today();
        $rows = PreorderListing::query()->whereIn('listing_id', $listingIds)->where('active', true)->where('release_date', '>', $today)->get();
        $taken = PreorderCounts::taken($rows->pluck('listing_id')->all());
        $terms = [];

        foreach ($rows as $row) {
            $remaining = $row->capacity === null ? null : max(0, $row->capacity - ($taken[$row->listing_id] ?? 0));
            $terms[$row->listing_id] = new PreorderTerms($row->listing_id, $row->releaseDate(), $remaining, self::open($row, $today));
        }

        return $terms;
    }

    /**
     * @param  array<string, int>  $quantities
     */
    public function reserve(string $transactionId, array $quantities, string $actor): void
    {
        $today = PreorderTerms::today();
        ksort($quantities);

        foreach ($quantities as $listingId => $quantity) {
            // 商品の行の錠の中で数え直す（連鎖のロックの中なので同時の注文は順になる。行の錠は決まりの変更と取り合う）
            $row = PreorderListing::query()->where('listing_id', $listingId)->lockForUpdate()->first();

            $title = mb_strimwidth((string) Listing::query()->whereKey($listingId)->value('title'), 0, 60, '…');

            if ($row === null || ! $row->active || $row->releaseDate() <= $today || ! self::open($row, $today)) {
                throw new PreorderUnavailable(sprintf(self::CLOSED, $title));
            }

            $taken = PreorderCounts::taken([$listingId])[$listingId] ?? 0;

            if ($quantity < 1 || ($row->capacity !== null && $taken + $quantity > $row->capacity)) {
                throw new PreorderUnavailable(sprintf(self::FULL, $title));
            }

            PreorderItem::create([
                'transaction_id' => $transactionId,
                'listing_id' => $listingId,
                'round' => $row->round,
                'quantity' => $quantity,
                'release_date' => $row->releaseDate(),
            ]);
        }
    }

    /**
     * 受付の終わりの日まで（その日を含む）受ける
     */
    public static function open(PreorderListing $row, string $today): bool
    {
        $ends = $row->endsOn();

        return $ends === null || $today <= $ends;
    }
}
