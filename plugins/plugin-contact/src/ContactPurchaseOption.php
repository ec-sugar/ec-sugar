<?php

declare(strict_types=1);

namespace EcSugar\PluginContact;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\PurchaseOptionProvider;
use App\Core\Plugin\PurchaseOption;

/**
 * 商品の画面の「この商品について問い合わせる」（ADR 0050 の買い方の口。行き先を持てる商品の画面の口はこれだけなので、ここに置く）。
 * 売り切れの時にも出す（入荷の予定などを聞きたい人のため）。押すとこの商品を付けたフォーム（/p/contact/product/{商品}）
 */
final class ContactPurchaseOption implements PurchaseOptionProvider
{
    public function forListing(Listing $listing): PurchaseOption
    {
        return new PurchaseOption('contact', 'この商品について問い合わせる', ContactLinks::product($listing->id), whenSoldOut: true);
    }
}
