<?php

declare(strict_types=1);

namespace EcSugar\PluginReferral;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 紹介のリンクから来た印の Cookie（ADR 0075 の 2）。中身は紹介のリンクの印と、リンクを開いた時刻（30 日を数えるため）だけで、個人情報は入れない。
 * Laravel が暗号化して改ざんを見分ける（ショップの群の EncryptCookies。書き換えた値は読めずに無いことになる）。
 * 会員の印と同じ形: JavaScript から読めず（HttpOnly）、SameSite=Lax、HTTPS では Secure、ショップのホストだけ
 */
final class ReferralCookie
{
    public const string NAME = 'sugar_referral';

    /**
     * 30 日のうちの紹介のリンクの印（無い・形が違う・期限を過ぎた・先の時刻は null）
     */
    public static function code(Request $request, CarbonImmutable $now): ?string
    {
        $value = $request->cookie(self::NAME);

        if (! is_string($value) || preg_match('/\A('.ReferralCodes::PATTERN.')\.([0-9]{1,12})\z/', $value, $m) !== 1) {
            return null;
        }

        $at = (int) $m[2];
        $age = $now->getTimestamp() - $at;

        // 時計のずれを 5 分まで許す。30 日ちょうどまでは効く
        if ($age < -300 || $age > ReferralSettings::COOKIE_DAYS * 86400) {
            return null;
        }

        return $m[1];
    }

    public static function make(Request $request, string $code, CarbonImmutable $now): Cookie
    {
        return cookie(self::NAME, $code.'.'.$now->getTimestamp(), ReferralSettings::COOKIE_DAYS * 24 * 60, '/', null, $request->isSecure(), true, false, 'lax');
    }

    public static function forget(Request $request): Cookie
    {
        return cookie(self::NAME, '', -2628000, '/', null, $request->isSecure(), true, false, 'lax');
    }
}
