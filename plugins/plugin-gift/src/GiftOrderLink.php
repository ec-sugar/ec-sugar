<?php

declare(strict_types=1);

namespace EcSugar\PluginGift;

use App\Core\Plugin\Contracts\OrderLinkProvider;
use App\Core\Plugin\OrderLink;
use App\Core\Transaction\Models\Transaction;
use EcSugar\PluginGift\Models\GiftOrder;

/**
 * ご注文の画面（ご注文の確認・マイページの注文の履歴）の「贈り物」のリンク（核の App のご注文のリンクの口。ADR 0055）。
 * 贈り物の注文だけ。行き先は買う人の画面（受け取りのリンク・写す・LINE で送る・作り直す・取り消す）で、署名と期限をつけて渡す
 * （ゲストがご注文の確認から開いても入れる。会員は署名が無くても自分の注文なら入れる）
 */
final class GiftOrderLink implements OrderLinkProvider
{
    public function forOrder(Transaction $order): array
    {
        $gift = GiftOrder::query()->where('transaction_id', $order->id)->first();

        if ($gift === null) {
            return [];
        }

        $label = in_array(GiftStatus::of($gift), ['preparing', 'waiting'], true) ? '贈り物のリンク・取り消し' : '贈り物の状況';

        return [new OrderLink('gift', $label, GiftCheckout::signedManagePath($order->id))];
    }
}
