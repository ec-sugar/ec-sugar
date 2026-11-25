<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads;

use App\Core\Plugin\Contracts\OrderLinkProvider;
use App\Core\Plugin\OrderLink;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use EcSugar\PluginDownloads\Models\DownloadGrant;

/**
 * ご注文の画面（ご注文の確認・マイページの注文の履歴）の「ダウンロード」。リンクを作った注文だけ。
 * 行き先は署名つき（1 時間）。開けるのは照会を通した人か会員の本人だけ（確認のメールのリンクで開いた人は、照会へ回る）
 */
final class DownloadOrderLink implements OrderLinkProvider
{
    public function forOrder(Transaction $order): array
    {
        if (! DownloadGrant::query()->where('transaction_id', $order->id)->exists()) {
            return [];
        }

        return [new OrderLink('downloads', 'ダウンロード', DownloadLinks::order($order->id, CarbonImmutable::now()->addHour()))];
    }
}
