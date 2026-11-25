<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

/**
 * 商品の売り方を App が押さえる口（ADR 0080。抽選販売の受付から結果の片付けまで）。押さえた商品は、カートにも、ほかの App の
 * 1 品の注文（定期便・チケットを買うなど）にも入れられず、押さえた App だけが核の 1 品の注文（PlaceSingleOrder の heldBy）で売れる。
 * 使う店だけで呼ばれる。答えは「頼んだ商品のうち押さえているもの」と、断る時にお客さんに見せる文（120 文字まで）
 */
interface SaleHoldProvider
{
    /**
     * @param  list<string>  $listingIds
     * @return array<string, string> 商品の ID => 断りの文
     */
    public function holds(array $listingIds): array;
}
