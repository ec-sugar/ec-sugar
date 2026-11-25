<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Transaction\Models\Transaction;

/**
 * お客さんのご注文の画面（ご注文の確認・マイページの注文の履歴）とご注文の知らせのメールに、App が足す注文の事実（予約の「ご予約の日時」「場所」「人数」など）。
 * 使っている店だけで呼ばれる。返すのは名前と値の文字だけ（HTML は返さない。描くのは核の部品と核のメールで、文字として出す）。
 * 名前・メール・住所・電話などの個人情報は返さない（メールは宛先を打ち間違えた人にも届きうる）。足すものが無ければ空
 */
interface OrderFactProvider
{
    /**
     * @return list<array{name: string, value: string}>
     */
    public function forOrder(Transaction $order): array;
}
