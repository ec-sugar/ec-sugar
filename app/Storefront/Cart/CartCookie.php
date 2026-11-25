<?php

declare(strict_types=1);

namespace App\Storefront\Cart;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * カートの合い言葉の Cookie。JavaScript から読めず（HttpOnly）、ほかのサイトからの移動では送られにくく（SameSite=Lax）、
 * HTTPS では Secure。中身は Laravel が暗号化する（店頭のミドルウェア群の EncryptCookies）。30 日。
 */
final class CartCookie
{
    public const string NAME = 'sugar_cart';

    public static function token(Request $request): ?string
    {
        $value = $request->cookie(self::NAME);

        return is_string($value) ? $value : null;
    }

    public static function make(Request $request, string $token): Cookie
    {
        return cookie(self::NAME, $token, CartService::LIFETIME_DAYS * 24 * 60, '/', null, $request->isSecure(), true, false, 'lax');
    }
}
