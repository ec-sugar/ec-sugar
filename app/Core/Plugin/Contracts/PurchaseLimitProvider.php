<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

/**
 * 1 回のご注文の決まりを App が答える口（ADR 0109）: 商品ごとの「1 回のご注文で何点まで」と、店の「◯ 円以上から」。
 * 核はカートの組み立てと注文の確定でこの答えを見るだけで、決まりの中身（どの商品がいくつか・下限はいくらか）は App が持つ。
 * 使う店だけで呼ばれる。読めなかった時、核はご注文を受けない側に倒す（決まりが分からないまま売らない）
 */
interface PurchaseLimitProvider
{
    /**
     * 頼んだ商品のうち、1 回のご注文で買える数に上限のあるもの（種類が違っても同じ商品は合わせて数える）。上限の無い商品は入れない
     *
     * @param  list<string>  $listingIds
     * @return array<string, int> 商品の ID => 上限（1 以上）
     */
    public function perOrder(array $listingIds): array;

    /**
     * 1 回のご注文の商品の合計の下限（最小通貨単位の整数。値引き・送料の前の、買える行の合計と比べる）。下限が無ければ 0
     */
    public function minimumSubtotal(): int;
}
