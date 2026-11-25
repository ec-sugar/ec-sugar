<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\OrderLink;
use App\Core\Transaction\Models\Transaction;

/**
 * お客さんのご注文の画面（ご注文の確認・マイページの注文の履歴）に、App が置くリンク（「レビューを書く」「領収書」など。2026-11-04）。
 * 使っている店だけで、その注文を見てよいと核が確かめた後（確認のメールの署名つきのリンクか、注文番号とメールでの照会で開いた人と、
 * ログインした会員の自分の注文）にだけ呼ばれる。確認のメールには使わない。
 * 返すのは文字と自分の経路だけ（HTML は返さない。描くのは核の部品 x-storefront::order-links）。置くものが無ければ空。
 * 行き先の先で見てよい人かを確かめるのは App（署名つきのリンクなど）
 */
interface OrderLinkProvider
{
    /**
     * @return list<OrderLink>
     */
    public function forOrder(Transaction $order): array;
}
