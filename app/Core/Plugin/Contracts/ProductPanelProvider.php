<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\ProductPanel;

/**
 * ショップの商品の画面の説明の下に、App が置く開いて読む並び（よくある質問など）。
 * 使っている店だけで呼ばれる。返すのは見出しと項目の文字だけ（HTML は返さない。描くのは核の部品）。出すものが無ければ null
 */
interface ProductPanelProvider
{
    public function forListing(Listing $listing): ?ProductPanel;
}
