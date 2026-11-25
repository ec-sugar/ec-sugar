<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock;

use App\Core\Listing\Enums\VariantStatus;
use App\Core\Listing\Models\ListingVariant;
use App\Storefront\Catalog\Catalog;

/**
 * 登録できる種類と、入荷したかの見方。どちらもショップでカートに入れられるかと同じ決まり（Catalog::purchasableVariants。公開中の商品の販売中の種類、
 * 決まった値段、0 円は店が売ると決めた時だけ）。売り切れ = 拠点をまたいだ「利用できる数」（在庫 − 確保）が 0
 */
final class RestockTargets
{
    /** 1 つの商品で見る種類の数の上限（種類の画面の上限より大きく取る） */
    private const int VARIANTS_MAX = 500;

    public function __construct(private readonly Catalog $catalog) {}

    /**
     * 商品の、いま売り切れの種類（ID => 種類の名前。軸の無い商品は空の名前）。ショップに出ていない商品は空
     *
     * @return array<string, string>
     */
    public function soldOut(string $listingId): array
    {
        $variants = ListingVariant::query()
            ->where('listing_id', $listingId)
            ->where('status', VariantStatus::Active)
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::VARIANTS_MAX)
            ->get();

        if ($variants->isEmpty()) {
            return [];
        }

        $purchasable = $this->catalog->purchasableVariants($variants->pluck('id')->all());
        $out = [];

        foreach ($variants as $variant) {
            // 予約の商品（ADR 0073）は在庫で売っていないので、入荷の知らせの対象にしない
            if (isset($purchasable[$variant->id]) && $purchasable[$variant->id]['available'] === 0 && $purchasable[$variant->id]['preorder'] === null) {
                $out[$variant->id] = $variant->label();
            }
        }

        return $out;
    }

    /**
     * 種類がいまショップでカートに入れられるか（入荷した）
     */
    public function available(string $variantId): bool
    {
        // 入荷したかは店の在庫の見方（見る人を問わない。会員だけ・ランクだけの商品も。ADR 0079）。知らせる相手ごとに見せてよいかは、送る時に確かめる（SendRestockMail）
        $row = $this->catalog->forShop()->purchasableVariants([$variantId])[$variantId] ?? null;

        // 予約の受付を始めた（予約の残りがある）のは入荷ではない（ADR 0073）。発売日からの在庫で知らせる
        return $row !== null && $row['preorder'] === null && $row['available'] > 0;
    }
}
