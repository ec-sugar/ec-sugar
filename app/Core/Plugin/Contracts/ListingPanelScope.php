<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Listing\Models\Listing;

/**
 * 商品の画面の App の欄（ListingPanelHandler）を、決まった商品だけに出す（チケットの欄はチケットの扱いの商品だけ）。
 * 係がこれも実装したときだけ核が聞く。偽の商品には欄を出さず、保存も同じ 404（ListingPanelNotFound）
 */
interface ListingPanelScope
{
    public function appliesTo(Listing $listing): bool;
}
