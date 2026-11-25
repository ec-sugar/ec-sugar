<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Plugin\Preorder\PreorderUnavailable;

/**
 * 予約販売の App の口（ADR 0073）。核は「発売日の前の商品は在庫によらず予約で受ける」「予約の品のある注文は発売日まで発送しない」だけを持ち、
 * 商品ごとの発売日・受付の終わり・上限と、注文ごとの予約の数は App が持つ。呼ばれるのは、この店で使っている時の店の文脈の中だけ（店で 1 つの App だけ）
 */
interface PreorderHandler
{
    /**
     * 発売日の前の予約の商品の決まり。発売日が日本の暦の今日より後の商品だけを返す（予約でない・発売日になった商品は入れない）。
     * 受付を終えた・上限に届いた商品も入れる（売り切れとして見せるため。PreorderTerms::acceptable が 0）
     *
     * @param  list<string>  $listingIds
     * @return array<string, PreorderTerms> 商品の ID => 決まり
     */
    public function terms(array $listingIds): array;

    /**
     * 注文の確定のトランザクションの中（連鎖のロックの後）で呼ぶ。予約の数を商品の行の錠の中で数え直し、上限と受付の終わりを超えなければ注文の予約として残す。
     * 超える・受付を終えた・発売日になったなら PreorderUnavailable（注文ごと巻き戻る）
     *
     * @param  array<string, int>  $quantities  商品の ID => 数（同じ商品の種類違いは足した数）
     *
     * @throws PreorderUnavailable
     */
    public function reserve(string $transactionId, array $quantities, string $actor): void;
}
