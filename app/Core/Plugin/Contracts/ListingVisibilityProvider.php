<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

/**
 * 商品を「今はショップに出さない」「今は売らない」と App が答える口（App「公開の予約と販売期間」が実装する）。
 * 使う店だけで呼ばれる。何個の App でも持てる（答えは足し合わせる）。
 *
 * - hiddenListings: 一覧・検索・商品の画面・サイトマップ・フィードから除く（公開の日時の前の商品など）。ショップの窓口（Catalog::published）が使う
 * - unsellableListings: 見せ方だけを変える（商品の画面と札を売り切れにする。販売の終わりの日時を過ぎた商品など）。
 *   **カートと注文の確定を断るのはこの口ではない**（売り方を押さえる口 SaleHoldProvider で、断りの文つきで断る）。見せ方と断りで同じ決まりを見る
 *
 * App が落ちたら、どちらも「無い」として扱う（ショップを止めない）。壊れたことは知らせる（ADR 0043）
 */
interface ListingVisibilityProvider
{
    /** 今はショップに出さない商品の数の上限（ショップの読み出しのたびに除くので、数を抑える） */
    public const int MAX_HIDDEN = 1000;

    /**
     * 今はショップに出さない商品の ID（MAX_HIDDEN 件まで。超えた分は核が切る）
     *
     * @return list<string>
     */
    public function hiddenListings(): array;

    /**
     * 頼んだ商品のうち、今は売らない商品の ID
     *
     * @param  list<string>  $listingIds
     * @return list<string>
     */
    public function unsellableListings(array $listingIds): array;
}
