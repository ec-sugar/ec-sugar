<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use DateTimeInterface;

/**
 * App が持つ、検索に載せるショップのページ（ADR 0072。最初は App「お知らせ・ブログ」の /news）。
 * App のショップの経路（/p/<名前>）は robots.txt で回らせない道なので、検索に載せたい読み物は、App が決めた 1 つの頭（prefix）の下に置く。
 *
 * - 頭は英小文字・数字・ハイフン。核の経路の頭と自由なページの予約語（ShopPage::RESERVED_SLUGS）・ほかの App の頭とは重ならない（登録で断る）
 * - 経路は核が代わりに登録し、storefront・public・plugin:<名前> の印を付ける（使わない店では 404。PluginRoutes）
 * - 画面は StorefrontView::renderAppPage で描く（テーマの外枠の中。検索と共有の見え方は SeoBuilder::forAppPage で核が作る）
 * - サイトマップには、使っている店でだけ sitemap() の URL を足す（頭の下の URL だけ。ほかは核が捨てる）
 */
interface ShopPagesProvider
{
    /** 経路の頭（/news なら news） */
    public function prefix(): string;

    /**
     * 経路を足す（Route::get などを呼ぶ。道筋は頭の下。名前は storefront.plugin.<名前>.pages. の後ろ）
     */
    public function routes(): void;

    /**
     * サイトマップに載せるページ（絶対の URL と、最後に変えた日時）。呼ぶのは使っている店の文脈の中だけ。$limit 件まで
     *
     * @return list<array{0: string, 1: ?DateTimeInterface}>
     */
    public function sitemap(int $limit): array;
}
