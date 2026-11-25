<?php

declare(strict_types=1);

namespace App\Storefront\Access;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 準備中のショップにパスワードを入れた印の Cookie。
 * 中身は「店の ID・パスワードの世代・期限」とその署名（APP_KEY から作った鍵の HMAC）。照らすのは次の 4 つ:
 *   - 店の ID が今の店（ほかの店で入れた Cookie では入れない）
 *   - 世代が今の世代（パスワードを変えたら、前の Cookie はすべて無効）
 *   - 期限が過ぎていない（Cookie の寿命だけに頼らず、中身でも見る）
 *   - 署名に混ぜた店の設定の行の ID（Cookie には書かない）が今のもの。同じ店の ID で作り直した店・マイグレーションを戻した店では
 *     世代が 1 から数え直しになるが、行の ID が変わるので前の Cookie は効かない
 * そのうえで Laravel が暗号化する（店頭のミドルウェア群の EncryptCookies）。署名は暗号化が外れても偽れないための二重の守り。
 * ショップのホストだけに付く（Domain を付けない）。HttpOnly・SameSite=Lax、HTTPS と本番では Secure
 */
final class ShopPasswordCookie
{
    public const string NAME = 'sugar_shop_pass';

    public const int DAYS = 7;

    /**
     * 今の店・今の世代の、期限の内の正しい Cookie か
     */
    public static function grants(Request $request, string $tenantId, int $generation, ?string $key): bool
    {
        $value = $request->cookie(self::NAME);

        if ($key === null || ! is_string($value) || $value === '' || strlen($value) > 200) {
            return false;
        }

        $parts = explode('|', $value);

        if (count($parts) !== 4) {
            return false;
        }

        [$tenant, $gen, $expires, $mac] = $parts;

        if (! hash_equals(self::sign($key, "{$tenant}|{$gen}|{$expires}"), $mac)) {
            return false;
        }

        return hash_equals($tenantId, $tenant)
            && $gen === (string) $generation
            && ctype_digit($expires)
            && (int) $expires > Carbon::now()->getTimestamp();
    }

    public static function make(Request $request, string $tenantId, int $generation, string $key): Cookie
    {
        $payload = $tenantId.'|'.$generation.'|'.Carbon::now()->addDays(self::DAYS)->getTimestamp();

        return cookie(self::NAME, $payload.'|'.self::sign($key, $payload), self::DAYS * 24 * 60, '/', null, $request->isSecure() || app()->isProduction(), true, false, 'lax');
    }

    private static function sign(string $key, string $payload): string
    {
        // 鍵は APP_KEY から用途ごとに分けて作る（ほかの署名と取り違えない）。店の設定の行の ID は署名にだけ混ぜる
        $secret = hash_hmac('sha256', 'storefront-shop-password-cookie', (string) config('app.key'), true);

        return hash_hmac('sha256', $key.'|'.$payload, $secret);
    }
}
