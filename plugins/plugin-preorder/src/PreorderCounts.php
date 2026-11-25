<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder;

use App\Core\Transaction\Enums\TransactionStatus;
use EcSugar\PluginPreorder\Models\PreorderItem;
use Illuminate\Database\Query\JoinClause;

/**
 * 予約の数（ADR 0073）: 商品の今の回の予約のうち、取り消し・返金で終わっていない注文の数の合計。
 * 払われずに消えた注文・取り消した注文は取消になるので、数え直すだけで戻る（戻す処理を別に持たない）
 */
final class PreorderCounts
{
    /**
     * @param  list<string>  $listingIds
     * @return array<string, int> 商品の ID => 予約の数（予約の無い商品は入れない）
     */
    public static function taken(array $listingIds): array
    {
        if ($listingIds === []) {
            return [];
        }

        return PreorderItem::query()
            ->join('preorder_listings', static function (JoinClause $join): void {
                $join->on('preorder_listings.listing_id', '=', 'preorder_items.listing_id')->on('preorder_listings.round', '=', 'preorder_items.round');
            })
            ->join('transactions', 'transactions.id', '=', 'preorder_items.transaction_id')
            ->whereIn('preorder_items.listing_id', $listingIds)
            ->whereNotIn('transactions.status', [TransactionStatus::Cancelled->value, TransactionStatus::Refunded->value])
            ->groupBy('preorder_items.listing_id')
            ->selectRaw('preorder_items.listing_id AS listing_id, SUM(preorder_items.quantity)::bigint AS taken')
            ->pluck('taken', 'listing_id')
            ->map(static fn (mixed $taken): int => (int) $taken)
            ->all();
    }
}
