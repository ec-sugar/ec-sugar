<?php

declare(strict_types=1);

namespace EcSugar\PluginSalePeriod;

use App\Core\Plugin\Contracts\SaleHoldProvider;

/**
 * 期間の外の商品の売り方を押さえる（核の口 SaleHoldProvider。ADR 0080）。
 * 出す日時の前と、終わる日時を過ぎた商品は、カート（入れる時・入った後の行・注文の確定の錠の中の読み直し）と
 * ほかの App の 1 品の注文に入れられない。断りの文はお客さんにそのまま見せる
 */
final class SalePeriodSaleHold implements SaleHoldProvider
{
    public function holds(array $listingIds): array
    {
        return SalePeriodSchedule::outside($listingIds);
    }
}
