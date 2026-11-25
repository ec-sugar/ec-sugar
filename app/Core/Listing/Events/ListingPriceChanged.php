<?php

declare(strict_types=1);

namespace App\Core\Listing\Events;

/**
 * 商品か種類の値段が変わった（ADR 0049 の 4）。値段を変えるのと同じトランザクション・連鎖のロックの中で出す。
 * App は約束した値段で続く申し込み（定期便など）の、値段の変更の知らせをここで決める。聞く側が例外を投げると値段の変更も取り消される
 */
final readonly class ListingPriceChanged
{
    public function __construct(
        public string $listingId,
        public string $actor,
    ) {}
}
