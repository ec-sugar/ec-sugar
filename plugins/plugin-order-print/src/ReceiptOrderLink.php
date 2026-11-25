<?php

declare(strict_types=1);

namespace EcSugar\PluginOrderPrint;

use App\Core\Plugin\Contracts\OrderLinkProvider;
use App\Core\Plugin\OrderLink;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Access\SecretLinks;

/**
 * お客さんのご注文の画面（ご注文の確認・マイページの注文の履歴）の「領収書」（核のご注文の画面のリンクの口）。払い済みの注文だけ。
 * 行き先は領収書の印刷用のページで、署名と期限（LINK_HOURS）つき。新しいタブで開く（ご注文の画面はそのまま残す）
 */
final class ReceiptOrderLink implements OrderLinkProvider
{
    /** お客さんの領収書のリンクの期限（時間）。リンクはご注文の画面を開くたびに作り直す */
    public const int LINK_HOURS = 24;

    public function forOrder(Transaction $order): array
    {
        if (! app(ReceiptIssues::class)->issuable($order)) {
            return [];
        }

        // 店をまたいで使えないよう、店の名前も署名に含める（受け取る側で今の店と比べる）
        $path = SecretLinks::signed('storefront.plugin.order-print.receipt', ['orderId' => $order->id], now()->addHours(self::LINK_HOURS));

        return [new OrderLink(OrderPrintServiceProvider::NAME, '領収書', $path, newTab: true)];
    }
}
