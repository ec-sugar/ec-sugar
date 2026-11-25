<?php

declare(strict_types=1);

namespace EcSugar\PluginReviews;

use App\Storefront\Access\SecretLinks;
use Illuminate\Http\Request;

/**
 * レビューの画面の道筋。ゲストに渡すものは署名と期限つき（ショップのドメインを Laravel が知らない処理でも作れるよう、署名は道筋にだけ掛ける。
 * 作るのも確かめるのも核の SecretLinks）。署名には店の ID（shop）も入り、ほかの店の同じ道筋では通さない
 */
final class ReviewLinks
{
    /** ゲストの署名つきの道筋の期限（時間）。ご注文の確認の画面を開くたびに作り直す */
    public const int HOURS = 24;

    public static function order(string $orderId, bool $signed): string
    {
        $parameters = ['orderId' => strtolower($orderId)];

        return $signed
            ? SecretLinks::signed('storefront.plugin.reviews.order', $parameters, now()->addHours(self::HOURS))
            : route('storefront.plugin.reviews.order', $parameters, absolute: false);
    }

    public static function form(string $orderId, string $listingId, bool $signed): string
    {
        $parameters = ['orderId' => strtolower($orderId), 'listingId' => strtolower($listingId)];

        return $signed
            ? SecretLinks::signed('storefront.plugin.reviews.form', $parameters, now()->addHours(self::HOURS))
            : route('storefront.plugin.reviews.form', $parameters, absolute: false);
    }

    /**
     * 署名つきの道筋で来て、署名が合い、期限の中で、この店のものか
     */
    public static function signed(Request $request): bool
    {
        return SecretLinks::valid($request);
    }
}
