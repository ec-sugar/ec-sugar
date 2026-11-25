<?php

declare(strict_types=1);

namespace EcSugar\PluginBundle;

use App\Core\Plugin\Contracts\ListingVisibilityProvider;

/**
 * 1 セットも作れないセットは、ショップに出さない（核の口 ListingVisibilityProvider。口を で使う）。
 *
 * セットそのものは在庫を持たないので、核の在庫の数だけ見ると「ある」ことになってしまう。
 * 作れるセットの数は「構成の品それぞれの（在庫 − 確保）÷ 1 セットあたりの数」の最小値で、
 * 0 なら一覧・検索・商品の画面・サイトマップ・フィードから外す（構成の品が消えた・下書き・種類を止めた時も 0）。
 *
 * 同じ決まりで「今は売らない」にも答える（ほかの App が並べた札など、別の道で出た時も売り切れに見せる）。
 * カートと注文の確定で本当に断るのは核の在庫（LineStock が構成の品を押さえられなければ丸ごと断る）
 */
final class BundleVisibility implements ListingVisibilityProvider
{
    public function hiddenListings(): array
    {
        return BundleStock::unbuildable();
    }

    public function unsellableListings(array $listingIds): array
    {
        return $listingIds === [] ? [] : BundleStock::unbuildable($listingIds);
    }
}
