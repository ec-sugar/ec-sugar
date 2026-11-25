<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\Catalog\CatalogBand;
use App\Core\Plugin\Catalog\CatalogTag;

/**
 * ショップの一覧の並びと絞り込みの口（App「並び順と絞り込み」が実装する）。店で 1 つの App だけ。
 *
 * 核（ショップの一覧）が持つのは「決まった列で並べる・決まった列で絞る」だけで、何を出すかは App が決める。
 * どれも、この店で App を使っている時の店の文脈の中だけで呼ばれる。返す値は核が確かめる（数・長さ・形・ID の形）。
 *
 * - order(): 店が手で決めた並び（商品の ID。先頭から）。空なら「おすすめ順」は出さない
 * - firstSortIsShopOrder(): 一覧をはじめから「おすすめ順」で出すか（お客さんが並べ替えを選ぶ前の既定）
 * - bands()・tags()・showsInStock(): お客さんに出す絞り込み（店の設定で出さないものは空・false）
 * - taggedListings(): 選ばれたタグの商品の ID。核は bands()・tags() が返した key と同じものしか渡さない
 */
interface CatalogBrowseHandler
{
    /**
     * 店が手で決めた並び（商品の ID を先頭から。核は CatalogBrowse::MAX_ORDER 件までを使い、残りは今までの並び）
     *
     * @return list<string>
     */
    public function order(): array;

    /**
     * 一覧の最初の並びを「おすすめ順」にするか（order() が空なら無視される）
     */
    public function firstSortIsShopOrder(): bool;

    /**
     * 値段の帯（出さないなら空。核は CatalogBrowse::MAX_OPTIONS 個までを使う）
     *
     * @return list<CatalogBand>
     */
    public function bands(): array;

    /**
     * タグ（出さないなら空。核は CatalogBrowse::MAX_OPTIONS 個までを使う）
     *
     * @return list<CatalogTag>
     */
    public function tags(): array;

    /**
     * 「在庫あり」の絞り込みを出すか
     */
    public function showsInStock(): bool;

    /**
     * そのタグを付けた商品の ID（核は CatalogBrowse::MAX_TAGGED 件までを使う）
     *
     * @return list<string>
     */
    public function taggedListings(string $tag): array;
}
