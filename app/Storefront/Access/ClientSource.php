<?php

declare(strict_types=1);

namespace App\Storefront\Access;

/**
 * 回数の制限で数える「接続元」。IPv4 は住所のまま、IPv6 は前の 64 ビット（/64）にまとめる。
 * IPv6 は 1 つの回線に /64 が丸ごと配られることが多く、住所ごとに数えると、後ろの 64 ビットを変えるだけで制限を抜けられるため
 */
final class ClientSource
{
    public static function of(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'unknown';
        }

        if (! str_contains($ip, ':')) {
            return $ip;
        }

        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return 'unknown';
        }

        // IPv4 を IPv6 の形で書いたもの（::ffff:192.0.2.1）は IPv4 のまま（/64 にまとめると IPv4 の全部が 1 つになる）
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        $prefix = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return ($prefix === false ? 'unknown' : $prefix).'/64';
    }

    /**
     * 2 段目の広い接続元（IPv6 の前の 48 ビット＝/48）。1 つの拠点に /48 が丸ごと配られることがあり、/64 を替えながら数を逃れられるため、
     * /48 でも（/64 より大きな上限で）数える。IPv4（IPv6 の形で書いた IPv4 を含む）と読めない値は null
     */
    public static function wide(?string $ip): ?string
    {
        if ($ip === null || ! str_contains($ip, ':')) {
            return null;
        }

        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16 || str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return null;
        }

        $prefix = inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10));

        return $prefix === false ? null : $prefix.'/48';
    }
}
