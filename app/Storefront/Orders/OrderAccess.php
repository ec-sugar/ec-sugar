<?php

declare(strict_types=1);

namespace App\Storefront\Orders;

use App\Core\Party\Services\CustomerMerges;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Members\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * お客さんが注文を見てよいか（App「ダウンロード販売」のように、署名つきのリンクだけでは開かせない画面のため）。
 *
 * - lookedUp: このセッションで、ご注文の確認の照会（注文番号とメールアドレス）を通した注文か（OrderController::find が印を置く。10 件まで）
 * - member: ログインした会員の自分の注文か（店の人がまとめた記録の注文も。CustomerMerges）
 *
 * 確認のメールの署名つきのリンクで開いた人は、どちらにも入らない（メールを転送された人に渡さないため。ご注文の画面そのものは今までどおり見られる）
 */
final class OrderAccess
{
    /** 照会を通した注文の ID の並び（セッションの鍵） */
    public const string VIEWABLE = 'viewable_orders';

    public static function lookedUp(Request $request, string $orderId): bool
    {
        return $request->hasSession() && in_array(strtolower($orderId), (array) $request->session()->get(self::VIEWABLE, []), true);
    }

    public static function member(Transaction $order): bool
    {
        $member = Auth::guard('customer')->user();

        return $member instanceof Member && $member->belongsHere() && $order->customer_account_id !== null
            && in_array($order->customer_account_id, CustomerMerges::family($member->account), true);
    }

    public static function allowed(Request $request, Transaction $order): bool
    {
        return self::member($order) || self::lookedUp($request, $order->id);
    }
}
