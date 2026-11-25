<?php

declare(strict_types=1);

namespace EcSugar\PluginContact;

use App\Core\Plugin\Contracts\OrderLinkProvider;
use App\Core\Plugin\OrderLink;
use App\Core\Transaction\Models\Transaction;

/**
 * ご注文の画面（ご注文の確認・マイページの注文の履歴）の「この注文について問い合わせる」（核のご注文の画面のリンクの口）。
 * 核がその注文を見てよい人の画面でだけ呼ぶ。行き先はこの注文を付けたフォームで、署名と期限（ContactLinks::HOURS）と店の ID つき
 */
final class ContactOrderLink implements OrderLinkProvider
{
    public function forOrder(Transaction $order): array
    {
        return [new OrderLink('contact', 'この注文について問い合わせる', ContactLinks::order($order->id))];
    }
}
