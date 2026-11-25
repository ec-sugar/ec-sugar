<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\PurchaseOption;

/**
 * ショップの商品の画面の「カートに入れる」の近くに、App が置く買い方（ADR 0050。定期便の「定期便で申し込む」など）。
 * 使っている店だけで呼ばれる。返すのは文字と道筋だけ（HTML は返さない。描くのは核の部品）
 */
interface PurchaseOptionProvider
{
    public function forListing(Listing $listing): ?PurchaseOption;
}
