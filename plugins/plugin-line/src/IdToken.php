<?php

declare(strict_types=1);

namespace EcSugar\PluginLine;

/**
 * LINE ログインの ID トークン（JWT）を確かめる（ADR 0070。LINE の資料「Get profile information from ID tokens」と
 * OpenID Connect Core 1.0 の「ID Token Validation」のとおり）。ウェブのログインのトークンは HS256（鍵はチャネルシークレット）。
 *
 * - 署名: 頭の alg は HS256 だけ（none・ES256・RS256 などは断る。alg を差し替えて鍵の取り違えを起こさせない）。HMAC-SHA256 を比べる
 * - iss は https://access.line.me、aud はこのショップのチャネル ID（文字列が 1 つ）、exp は今より後、iat は先の時刻でない（ずれは 5 分まで）
 * - nonce はこのブラウザで出した値と同じ（使い回しの防ぎ）
 * - sub は LINE の利用者の ID の形（U と 16 進の 32 文字）
 *
 * どこが違ったかは返さない（呼ぶ側は「ログインできませんでした」とだけ出す）。合えば sub を返す。メールなどほかの値は読まない
 */
final class IdToken
{
    public const string ISSUER = 'https://access.line.me';

    private const int SKEW = 300;

    public static function verify(string $jwt, string $channelId, string $channelSecret, string $nonce, int $now): ?string
    {
        if (strlen($jwt) > 8192 || substr_count($jwt, '.') !== 2) {
            return null;
        }

        [$head, $body, $signature] = explode('.', $jwt);
        $header = self::json($head);
        $claims = self::json($body);
        $given = self::decode($signature);

        if ($header === null || $claims === null || $given === null || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        if (! hash_equals(hash_hmac('sha256', $head.'.'.$body, $channelSecret, true), $given)) {
            return null;
        }

        $sub = $claims['sub'] ?? null;
        $exp = $claims['exp'] ?? null;
        $iat = $claims['iat'] ?? null;
        $claimed = $claims['nonce'] ?? null;

        if (($claims['iss'] ?? null) !== self::ISSUER || ! is_string($claims['aud'] ?? null) || ! hash_equals($channelId, $claims['aud'])) {
            return null;
        }

        if (! is_int($exp) || $exp <= $now || ! is_int($iat) || $iat > $now + self::SKEW) {
            return null;
        }

        if (! is_string($claimed) || $nonce === '' || ! hash_equals($nonce, $claimed)) {
            return null;
        }

        return is_string($sub) && preg_match('/\AU[0-9a-f]{32}\z/', $sub) === 1 ? $sub : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function json(string $part): ?array
    {
        $raw = self::decode($part);
        $value = $raw === null ? null : json_decode($raw, true, 8);

        return is_array($value) && ! array_is_list($value) ? $value : null;
    }

    private static function decode(string $part): ?string
    {
        if ($part === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $part) !== 1) {
            return null;
        }

        $raw = base64_decode(strtr($part, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
