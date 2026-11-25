<?php

declare(strict_types=1);

namespace App\Storefront\Apps;

use App\Core\Plugin\Manifest\ConsentKind;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * お客さんの同意の Cookie（ADR 0034 の 4）。種類ごとに「同意した・しなかった」を持つ（決めていない種類は持たない）。
 * ショップのホストだけに付く（Domain を付けない。店ごと）。SameSite=Lax、HTTPS では Secure、180 日。
 * HttpOnly にする: 決めるのはサーバー（差し込むかどうか）で、ページの JavaScript（外のタグを含む）に読ませる・書き換えさせる理由が無い。
 * 外のタグが自分で同意を「した」に書き換える道を塞ぐ。変えるのは同意の受付（POST、CSRF つき）だけ。中身は Laravel が暗号化する
 */
final class ConsentCookie
{
    public const string NAME = 'sugar_consent';

    public const int DAYS = 180;

    /**
     * 決めた種類 => 同意したか。読めない・知らない書き方は「決めていない」
     *
     * @return array<string, bool>
     */
    public static function read(Request $request): array
    {
        $value = $request->cookie(self::NAME);

        if (! is_string($value) || $value === '' || strlen($value) > 100) {
            return [];
        }

        $decisions = [];

        foreach (explode('&', $value) as $pair) {
            [$kind, $granted] = array_pad(explode('=', $pair, 2), 2, null);

            if (is_string($kind) && ConsentKind::tryFrom($kind) !== null && in_array($granted, ['0', '1'], true)) {
                $decisions[$kind] = $granted === '1';
            }
        }

        return $decisions;
    }

    /**
     * @param  array<string, bool>  $decisions  種類 => 同意したか
     */
    public static function make(Request $request, array $decisions): Cookie
    {
        $pairs = [];

        foreach (ConsentKind::cases() as $kind) {
            if (array_key_exists($kind->value, $decisions)) {
                $pairs[] = $kind->value.'='.($decisions[$kind->value] ? '1' : '0');
            }
        }

        return cookie(self::NAME, implode('&', $pairs), self::DAYS * 24 * 60, '/', null, $request->isSecure(), true, false, 'lax');
    }
}
