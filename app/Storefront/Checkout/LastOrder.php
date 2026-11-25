<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use Illuminate\Http\Request;

/**
 * このセッションで今確定した注文（注文を受け付けた画面に出す注文）。セッションはサーバーに暗号化して置くので、ほかの人の注文の番号は入らない。
 * App が「この人が今買った注文」を確かめるときにも使う（贈り物の受け取りのリンクの画面。ADR 0055）
 */
final class LastOrder
{
    public const string SESSION = 'checkout_last_order';

    public static function id(Request $request): ?string
    {
        $id = $request->hasSession() ? $request->session()->get(self::SESSION) : null;

        return is_string($id) ? $id : null;
    }
}
