<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Party\Services\CustomerSessions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 会員の印の Cookie（ADR 0044 の 3）。カートの Cookie と同じ形: JavaScript から読めず（HttpOnly）、SameSite=Lax、HTTPS では Secure、
 * 中身は Laravel が暗号化する。ショップのホストだけ（Domain を付けない）で、運営のログインとは混ぜない。30 日
 */
final class MemberCookie
{
    public const string NAME = 'sugar_member';

    public static function token(Request $request): ?string
    {
        $value = $request->cookie(self::NAME);

        return is_string($value) ? $value : null;
    }

    public static function make(Request $request, string $token): Cookie
    {
        return cookie(self::NAME, $token, CustomerSessions::DAYS * 24 * 60, '/', null, $request->isSecure(), true, false, 'lax');
    }

    public static function forget(Request $request): Cookie
    {
        return cookie(self::NAME, '', -2628000, '/', null, $request->isSecure(), true, false, 'lax');
    }
}
