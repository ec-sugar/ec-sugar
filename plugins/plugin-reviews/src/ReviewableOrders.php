<?php

declare(strict_types=1);

namespace EcSugar\PluginReviews;

use App\Core\Listing\Models\Listing;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerMerges;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Storefront\Checkout\CheckoutOrder;
use Illuminate\Support\Collection;

/**
 * レビューを書ける注文（買った人だけ）。ショップで確定した物販の注文で、発送済みか完了のもの（取消・返金・払う前・発送の前は書けない）。
 * 書けるのはその注文の商品の行の商品だけ。お客さんの名前・メール・届け先は読まない
 */
final class ReviewableOrders
{
    /** 書ける段（発送済み・受け取りの確認・精算〔完了〕） */
    private const array PHASES = [Phase::Fulfillment, Phase::Acceptance, Phase::Settlement];

    /** マイページに並べる注文の数（新しい順） */
    public const int MEMBER_ORDERS = 30;

    /**
     * ショップの注文（物販でショップの注文の印があるもの）。ID の形が違う・無い・管理画面などで作った取引は null
     */
    public static function find(string $orderId): ?Transaction
    {
        $id = strtolower($orderId);

        if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) !== 1 || ! CheckoutOrder::query()->where('transaction_id', $id)->exists()) {
            return null;
        }

        return Transaction::query()->where('kind', 'sale')->find($id);
    }

    /**
     * 今、書ける注文か（発送済みか完了で、取り消し・返金をしていない）
     */
    public static function writable(Transaction $order): bool
    {
        return $order->kind === 'sale' && ! $order->status->isClosed() && in_array($order->phase, self::PHASES, true);
    }

    /**
     * 注文の商品（商品の ID => 名前。並びは注文の行の順。同じ商品の種類違いは 1 つ）
     *
     * @return array<string, string>
     */
    public static function listings(Transaction $order): array
    {
        $ids = TransactionLine::query()->where('transaction_id', $order->id)->where('line_type', LineType::Item)->whereNotNull('listing_id')
            ->orderBy('position')->pluck('listing_id')->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $titles = Listing::query()->whereKey($ids)->pluck('title', 'id')->all();
        $out = [];

        foreach ($ids as $id) {
            if (isset($titles[$id])) {
                $out[$id] = (string) $titles[$id];
            }
        }

        return $out;
    }

    /**
     * この会員（と、店の人が同じお客さんとしてまとめた記録）の注文か（ADR 0044 の 2）
     */
    public static function ownedBy(Transaction $order, CustomerAccount $account): bool
    {
        return $order->customer_account_id !== null && in_array($order->customer_account_id, CustomerMerges::family($account), true);
    }

    /**
     * 会員が書ける注文（新しい順に MEMBER_ORDERS まで）
     *
     * @return Collection<int, Transaction>
     */
    public static function ofMember(CustomerAccount $account): Collection
    {
        return Transaction::query()->whereIn('customer_account_id', CustomerMerges::family($account))->where('kind', 'sale')
            ->whereIn('id', CheckoutOrder::query()->select('transaction_id'))
            ->whereIn('phase', array_map(static fn (Phase $p): string => $p->value, self::PHASES))
            ->whereNotIn('status', [TransactionStatus::Cancelled->value, TransactionStatus::Refunded->value])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::MEMBER_ORDERS)->get();
    }
}
