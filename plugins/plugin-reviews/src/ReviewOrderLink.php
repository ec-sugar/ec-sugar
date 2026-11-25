<?php

declare(strict_types=1);

namespace EcSugar\PluginReviews;

use App\Core\Plugin\Contracts\OrderLinkProvider;
use App\Core\Plugin\OrderLink;
use App\Core\Transaction\Models\Transaction;

/**
 * ご注文の画面（ご注文の確認・マイページの注文の履歴）の「レビューを書く」（核のご注文の画面のリンクの口）。書ける注文（発送済みか完了）で商品があるときだけ。
 * 行き先はこの注文のレビューの画面で、署名と期限（ReviewLinks::HOURS）つき（ゲストはそれで書ける。会員はマイページからも）
 */
final class ReviewOrderLink implements OrderLinkProvider
{
    public function forOrder(Transaction $order): array
    {
        if (! ReviewableOrders::writable($order) || ReviewableOrders::listings($order) === []) {
            return [];
        }

        return [new OrderLink('reviews', 'レビューを書く', ReviewLinks::order($order->id, true))];
    }
}
