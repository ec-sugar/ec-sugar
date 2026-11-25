<?php

declare(strict_types=1);

namespace App\Storefront\Access;

use Illuminate\Support\Carbon;

/**
 * 会員だけ・ランクだけの商品の写真のリンク（ADR 0079）。写真の経路（storefront-media の群）はセッションを持たず、誰が見ているかが分からないので、
 * 見てよい会員に商品の画面を描く時に、写真の URL へ「店の ID・商品の ID・期限」の署名を付ける（プレビューのリンク ListingPreviewLink と同じ形で、鍵は別）。
 * 照らすのは、署名が合う・店と商品が同じ・期限の内（1〜2 時間。時の区切りでそろえ、同じ時間のうちは同じ URL にしてブラウザが写真を使い回せるように）。
 * 開けるのはその商品の写真だけで、写真は共有のキャッシュに置かない（private, no-store。MediaController）
 */
final class MemberPhotoLink
{
    /** 期限のそろえ方（秒） */
    private const int WINDOW = 3600;

    private const int TOKEN_LENGTH = 64;

    /**
     * 写真の URL に付ける問い合わせの値
     *
     * @return array{member_expires: string, member_token: string}
     */
    public static function query(string $tenantId, string $listingId, ?int $now = null): array
    {
        $now ??= Carbon::now()->getTimestamp();
        $expires = (string) (intdiv($now, self::WINDOW) * self::WINDOW + 2 * self::WINDOW);

        return ['member_expires' => $expires, 'member_token' => self::sign($tenantId, $listingId, $expires)];
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
        // 鍵は APP_KEY から用途ごとに分けて作る（プレビューのリンクと取り違えない。この署名では商品の画面は開けない）
        $secret = hash_hmac('sha256', 'storefront-member-photo', (string) config('app.key'), true);

        return hash_hmac('sha256', "{$tenantId}|{$listingId}|{$expires}", $secret);
    }
}
