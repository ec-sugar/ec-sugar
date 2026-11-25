<?php

declare(strict_types=1);

namespace App\Storefront\Access;

use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Support\Carbon;

/**
 * 商品のプレビューのリンク。管理画面と店頭はホストが違い、運営のログインは店頭に届かないので、署名つきの期限つきのリンクで開く。
 * 中身は「店の ID・商品の ID・期限」とその署名（APP_KEY から用途で分けた鍵の HMAC）。照らすのは次の 3 つ:
 *   - 署名が合う（定数時間で比べる。店の ID・商品の ID・期限のどれを書き換えても合わない）
 *   - 店の ID が今の店（ほかの店で作ったリンクでは開かない）・商品の ID が経路の商品（ほかの商品へ広げない）
 *   - 期限が過ぎていない（30 分）
 * 開けるのはその商品の画面と、その商品の写真だけ（ほかの画面・ほかの商品へは広げない）
 */
final class ListingPreviewLink
{
    public const int MINUTES = 30;

    /** 署名の長さの上限（sha256 の 16 進で 64 文字。長すぎる値は比べる前に断る） */
    private const int TOKEN_LENGTH = 64;

    /**
     * その店のショップの、その商品のプレビューの URL（店のドメインが無ければ null）
     *
     * @return array{url: string, expires_at: Carbon}|null
     */
    public static function issue(Tenant $tenant, string $listingId): ?array
    {
        $expiresAt = Carbon::now()->addMinutes(self::MINUTES);
        $query = self::query((string) $tenant->getTenantKey(), $listingId, $expiresAt->getTimestamp());
        $url = StorefrontUrls::of($tenant, SecretLinks::path('storefront.preview', ['listingId' => $listingId] + $query));

        return $url === null ? null : ['url' => $url, 'expires_at' => $expiresAt];
    }

    /**
     * リンクに付ける問い合わせの値（写真の URL にも同じものを付ける）
     *
     * @return array{expires: string, token: string}
     */
    public static function query(string $tenantId, string $listingId, int $expires): array
    {
        return ['expires' => (string) $expires, 'token' => self::sign($tenantId, $listingId, (string) $expires)];
    }

    /**
     * 今の店の、その商品の、期限の内の正しい署名か
     */
    public static function grants(string $tenantId, string $listingId, mixed $expires, mixed $token): bool
    {
        if ($tenantId === '' || ! is_string($expires) || ! is_string($token) || strlen($token) !== self::TOKEN_LENGTH || strlen($expires) > 12 || ! ctype_digit($expires)) {
            return false;
        }

        return hash_equals(self::sign($tenantId, $listingId, $expires), $token)
            && (int) $expires > Carbon::now()->getTimestamp();
    }

    private static function sign(string $tenantId, string $listingId, string $expires): string
    {
        // 鍵は APP_KEY から用途ごとに分けて作る（ほかの署名と取り違えない。準備中のショップの Cookie・ご注文の確認のリンクとは別の鍵）
        $secret = hash_hmac('sha256', 'storefront-listing-preview', (string) config('app.key'), true);

        return hash_hmac('sha256', "{$tenantId}|{$listingId}|{$expires}", $secret);
    }
}
