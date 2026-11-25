<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\PurchaseOption;

/**
 * ショップの一覧の札（商品のカード）に App が置く「押す・戻す」のボタン（App「お気に入り」のハートなど。ADR 0071）。
 * 商品の画面の買い方の口（PurchaseOptionProvider）を渡した App が、同じ係でこれも実装したときだけ使う（PluginDefinition は変えない。MarketingConsent と同じ形）。
 * 札に出すのはトグル（pressed のある PurchaseOption）だけで、ほかの形は核が捨てる。使っている店だけで呼ばれる。
 * 札は 1 つの画面に何十枚も並ぶので、1 枚ごとに DB を引かない（要るものは 1 回の要求で 1 度だけ読んで覚えておく）
 */
interface CardOptionProvider
{
    /**
     * @param  string  $listingId  札の商品（ショップに出ている商品）
     */
    public function forCard(string $listingId): ?PurchaseOption;
}
