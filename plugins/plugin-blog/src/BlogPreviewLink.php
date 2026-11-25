<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog;

use App\Models\Tenant;
use App\Storefront\Access\SecretLinks;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;

/**
 * 記事のプレビューのリンク（ADR 0072。商品のプレビュー〔ListingPreviewLink〕と同じ形）。管理画面とショップはホストが違い、運営のログインはショップに届かないので、
 * 署名つきの期限つきのリンクで開く。中身は「店の ID・記事の ID・期限」とその署名（APP_KEY から用途で分けた鍵の HMAC）。照らすのは:
 *   - 署名が合う（定数時間で比べる。店の ID・記事の ID・期限のどれを書き換えても合わない）
 *   - 店の ID が今の店（ほかの店で作ったリンクでは開かない）・記事の ID が経路の記事（ほかの記事へ広げない）
 *   - 期限が過ぎていない（30 分）
 * 道筋は App のショップの経路（/p/blog/preview/{ID}。robots.txt で回らせない）
 */
final class BlogPreviewLink
{
    public const int MINUTES = 30;

    private const int TOKEN_LENGTH = 64;

    /**
     * その店のショップの、その記事のプレビューの URL（店のドメインが無ければ null）
     */
    public static function issue(Tenant $tenant, string $postId): ?string
    {
        $expires = (string) CarbonImmutable::now()->addMinutes(self::MINUTES)->getTimestamp();
        $tenantId = (string) $tenant->getTenantKey();
        // 道筋は核の SecretLinks で作る（行き先が秘密の道筋の宣言を持つかを確かめる）
        $path = SecretLinks::path('storefront.plugin.blog.preview', ['postId' => $postId, 'expires' => $expires, 'token' => self::sign($tenantId, $postId, $expires)]);

        return StorefrontUrls::of($tenant, $path);
    }

    /**
     * 今の店の、その記事の、期限の内の正しい署名か
     */
    public static function grants(string $tenantId, string $postId, mixed $expires, mixed $token): bool
    {
        if ($tenantId === '' || ! is_string($expires) || ! is_string($token) || strlen($token) !== self::TOKEN_LENGTH || strlen($expires) > 12 || ! ctype_digit($expires)) {
            return false;
        }

        return hash_equals(self::sign($tenantId, $postId, $expires), $token)
            && (int) $expires > CarbonImmutable::now()->getTimestamp();
    }

    private static function sign(string $tenantId, string $postId, string $expires): string
    {
        // 鍵は APP_KEY から用途ごとに分けて作る（商品のプレビュー・ご注文の確認のリンクとは別の鍵）
        $secret = hash_hmac('sha256', 'plugin-blog-preview', (string) config('app.key'), true);

        return hash_hmac('sha256', "{$tenantId}|".strtolower($postId)."|{$expires}", $secret);
    }
}
