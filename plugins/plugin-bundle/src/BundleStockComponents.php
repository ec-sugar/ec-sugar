<?php

declare(strict_types=1);

namespace EcSugar\PluginBundle;

use App\Core\Plugin\Contracts\StockComponentsProvider;

/**
 * セットの商品の在庫は、構成の品から引く（核の口 StockComponentsProvider）。
 *
 * **この口は落ちたら取引が止まる**（核の StockComponents は例外をそのまま上げる。在庫を引かずに売るより止める方が安全）。
 * なので、ここは表を素直に読むだけにして、ほかのことは何もしない（入れ子の確かめは保存のとき、
 * 作れるセットの数の数え直しは見せ方の口）。セットでない商品には空の配列を返す
 */
final class BundleStockComponents implements StockComponentsProvider
{
    public function stockComponents(array $listingIds): array
    {
        return BundleStock::componentsOf($listingIds);
    }
}
