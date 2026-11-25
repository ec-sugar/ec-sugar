<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Transaction\Models\Transaction;

/**
 * 管理画面の注文の画面に、App の画面の 1 件を出す口（App「ダウンロード販売」の回数と最後の日時など）。
 * App の画面（AdminPageHandler）を持つ App だけが使える。核はこの注文に結びつく 1 件の ID を聞き、App の画面の 1 件（record）と同じ形・同じ確かめで、
 * 個人情報の印の付いた事実と表を出さずに注文の画面へ並べる（操作は出さず、App の画面の 1 件へのリンクを置く）。使う店だけで呼ばれる
 */
interface AdminOrderPanelProvider
{
    /**
     * この注文に結びつく App の画面の 1 件の ID（ULID）。無ければ null
     */
    public function recordForOrder(Transaction $order): ?string;
}
