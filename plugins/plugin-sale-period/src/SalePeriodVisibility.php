<?php

declare(strict_types=1);

namespace EcSugar\PluginSalePeriod;

use App\Core\Plugin\Contracts\ListingVisibilityProvider;

/**
 * 期間の前の商品はショップに出さず、期間の後の商品は売り切れにする（核の口 ListingVisibilityProvider）。
 * 断る（カートに入らない・注文を確定しない）のはこの口ではなく、売り方を押さえる口（SalePeriodSaleHold）。同じ決まりを見る
 */
final class SalePeriodVisibility implements ListingVisibilityProvider
{
    public function hiddenListings(): array
    {
        return SalePeriodSchedule::notYetOpen();
    }

    public function unsellableListings(array $listingIds): array
    {
        return SalePeriodSchedule::closed($listingIds);
    }
}
