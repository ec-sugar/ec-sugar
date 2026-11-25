<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * 信じる前段の代理（接続元を X-Forwarded-For などで伝えてくる相手）。既定は無し。
 *
 * nginx から PHP へは FastCGI で直結なので、REMOTE_ADDR が nginx の見た本当の接続元になる。間に HTTP の代理は無い。
 * 私設の住所を丸ごと信じると、家の LAN などから偽の見出しを足すだけで、管理画面の接続元の制限や回数の制限を抜けられた（で見つけた）。
 * 前にロードバランサなどを置いたときだけ、その住所を TRUSTED_PROXIES に書く（カンマ区切りの IP か CIDR）。
 * 誰でも信じる「*」「**」と、住所として読めないものは捨てる（設定の書き間違いで穴を開けない）。
 */
final class TrustedProxies
{
    /**
     * @return list<string>
     */
    public static function parse(string $value): array
    {
        $proxies = [];

        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);

            if ($entry === '' || str_contains($entry, '*') || ! self::valid($entry)) {
                continue;
            }

            $proxies[] = $entry;
        }

        return $proxies;
    }

    private static function valid(string $entry): bool
    {
        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        // 0 ビットの CIDR（0.0.0.0/0・::/0）は誰でも信じるのと同じなので断る
        return ctype_digit($bits) && (int) $bits >= 1 && (int) $bits <= $max && IpUtils::checkIp($address, $entry);
    }
}
