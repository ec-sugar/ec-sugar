<?php

declare(strict_types=1);

namespace EcSugar\PluginContact;

use App\Storefront\Access\SecretLinks;
use Illuminate\Http\Request;

/**
 * お問い合わせのフォームの道筋。注文を付けて開く道筋は署名と期限つき（ご注文の画面を見てよい人にだけ渡す。商品レビューと同じ形）。
 * 署名は道筋にだけ掛け（作るのも確かめるのも核の SecretLinks）、店の ID（shop）も入って、ほかの店の同じ道筋では通さない。
 * 商品を付ける道筋は署名なし（ショップに出ている商品は誰でも見られる）
 */
final class ContactLinks
{
    /** 注文を付ける道筋の期限（時間）。ご注文の画面を開くたびに作り直す */
    public const int HOURS = 24;

    public static function form(): string
    {
        return route('storefront.plugin.contact.form', [], absolute: false);
    }

    public static function product(string $listingId): string
    {
        return route('storefront.plugin.contact.product', ['listingId' => strtolower($listingId)], absolute: false);
    }

    public static function order(string $orderId): string
    {
        return SecretLinks::signed('storefront.plugin.contact.order', ['orderId' => strtolower($orderId)], now()->addHours(self::HOURS));
    }

    /**
     * 署名つきの道筋で来て、署名が合い、期限の中で、この店のものか
     */
    public static function signed(Request $request): bool
    {
        return SecretLinks::valid($request);
    }
}
