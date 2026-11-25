<?php

declare(strict_types=1);

namespace App\Storefront\Postal;

/**
 * 郵便番号から住所を引く部品。差し替えられる（今は zipcloud。後で日本郵便の公式 API などに）。
 * 呼ぶのはサーバーだけ（店頭の CSP は自分のオリジンだけ）。外に送るのは 7 桁の郵便番号だけ。
 * 引けないとき（止まっている・遅い・見つからない）は空を返す。お客さんは手で入力して注文できる。
 */
interface PostalLookup
{
    /**
     * @param  string  $code  7 桁の数字（呼ぶ側で確かめてある）
     * @return list<PostalAddress> 見つからなければ空
     */
    public function find(string $code): array;
}
