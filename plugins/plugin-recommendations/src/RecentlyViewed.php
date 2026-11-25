<?php

declare(strict_types=1);

namespace EcSugar\PluginRecommendations;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 最近見た商品（ADR 0071）。お客さんのブラウザの Cookie に、商品の ID だけを新しい順に 20 件まで持つ（会員でも、お店の側には何も残さない）。
 * Cookie はショップの鍵で暗号化される（ショップのミドルウェアの EncryptCookies）が、読むときは改ざんされた前提で確かめる:
 * 形の違う値・長すぎる値は捨て、ID の形（ULID）に合うものだけを 20 件まで。知らない ID・ほかの店の ID は、商品を読むとき（Catalog。この店の DB だけ）に落ちる
 */
final class RecentlyViewed
{
    public const string COOKIE = 'sugar_recent';

    public const int MAX = 20;

    /** 覚えておく日数 */
    public const int DAYS = 30;

    private const string ULID = '/\A[0-9a-hjkmnp-tv-z]{26}\z/';

    /**
     * Cookie の商品の ID（新しい順・重なりなし・20 件まで）
     *
     * @return list<string>
     */
    public static function ids(Request $request): array
    {
        $value = $request->cookie(self::COOKIE);

        if (! is_string($value) || $value === '' || strlen($value) > self::MAX * 27 + 27) {
            return [];
        }

        $ids = [];

        foreach (explode('.', $value) as $id) {
            if (preg_match(self::ULID, $id) === 1 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }

            if (count($ids) === self::MAX) {
                break;
            }
        }

        return $ids;
    }

    /**
     * 見た商品を先頭に足した Cookie
     */
    public static function remember(Request $request, string $listingId): Cookie
    {
        $ids = array_slice(array_values(array_diff(self::ids($request), [$listingId])), 0, self::MAX - 1);

        return Cookie::create(self::COOKIE, implode('.', [$listingId, ...$ids]))
            ->withExpires(time() + self::DAYS * 86_400)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    public static function isId(string $value): bool
    {
        return preg_match(self::ULID, $value) === 1;
    }
}
