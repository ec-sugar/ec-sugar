<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\PurchaseOptionProvider;
use App\Core\Plugin\PurchaseOption;

/**
 * 商品の画面の「入荷したら知らせる」（ADR 0050 の買い方の口）。売り切れの種類があるときだけ。商品の全部が売り切れの時にも出す（whenSoldOut）。
 * 押すと App の画面（/p/restock/{商品}）で種類とメールを選ぶ（JavaScript を使わない）
 */
final class RestockPurchaseOption implements PurchaseOptionProvider
{
    public function forListing(Listing $listing): ?PurchaseOption
    {
        $soldOut = app(RestockTargets::class)->soldOut($listing->id);

        if ($soldOut === []) {
            return null;
        }

        // 種類のある商品で一部だけ売り切れなら、どの種類の知らせかを案内に書く
        $note = count($soldOut) === 1 && reset($soldOut) === ''
            ? '入荷したら、メールで 1 回だけお知らせします'
            : '売り切れの種類が入荷したら、メールで 1 回だけお知らせします';

        return new PurchaseOption('restock', '入荷のお知らせを受け取る', '/p/restock/'.$listing->id, $note, whenSoldOut: true);
    }
}
