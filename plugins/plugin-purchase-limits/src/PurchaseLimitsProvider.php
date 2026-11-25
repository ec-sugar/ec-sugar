<?php

declare(strict_types=1);

namespace EcSugar\PluginPurchaseLimits;

use App\Core\Plugin\Contracts\PurchaseLimitProvider;
use App\Core\Plugin\Services\PluginSettings;
use EcSugar\PluginPurchaseLimits\Models\ListingLimit;
use UnexpectedValueException;

/**
 * 核の口「1 回のご注文の決まり」（PurchaseLimitProvider）への答え: 商品ごとの上限は App の表から、商品の合計の下限は App の設定から。
 * 核は使う店だけで呼び、カートの組み立て・注文の確定・1 品の注文が同じ答えを見る。
 * **読めない時は例外のまま返す**（核がご注文を受けない側に倒す。決まりが分からないまま「決まりは無い」と答えない）
 */
final class PurchaseLimitsProvider implements PurchaseLimitProvider
{
    /** 下限の上限（円。設定の形の決まりと同じ） */
    public const int MAX_MINIMUM = 100_000_000;

    /**
     * 頼まれた商品だけを 1 回で読む（商品の数だけ問い合わせない）
     *
     * @param  list<string>  $listingIds
     * @return array<string, int>
     */
    public function perOrder(array $listingIds): array
    {
        if ($listingIds === []) {
            return [];
        }

        $limits = [];

        foreach (ListingLimit::query()->whereIn('listing_id', $listingIds)->get(['listing_id', 'per_order']) as $row) {
            $limits[$row->listing_id] = $row->per_order;
        }

        return $limits;
    }

    /**
     * 店の「最低購入金額」（円の整数。0 か空なら無し）。形はマニフェストの決まりで保存の時に確かめてある。
     * それでも形や範囲の外の値が入っていたら、0（無し）に読み替えずに例外にする（壊れた設定のまま下限なしで売らない）
     */
    public function minimumSubtotal(): int
    {
        $value = app(PluginSettings::class)->publicValues(PurchaseLimitsServiceProvider::NAME)['minimum_subtotal'] ?? null;

        if ($value === null || $value === '') {
            return 0;
        }

        if (! is_string($value) || preg_match('/\A[0-9]{1,9}\z/', $value) !== 1 || (int) $value > self::MAX_MINIMUM) {
            throw new UnexpectedValueException('最低購入金額の設定を読めません');
        }

        return (int) $value;
    }
}
