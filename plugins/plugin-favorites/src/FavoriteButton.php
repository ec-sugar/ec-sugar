<?php

declare(strict_types=1);

namespace EcSugar\PluginFavorites;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\CardOptionProvider;
use App\Core\Plugin\Contracts\PurchaseOptionProvider;
use App\Core\Plugin\PurchaseOption;
use App\Storefront\Members\Member;

/**
 * ハートのボタン（ADR 0071）。商品の画面の買い方の口（ADR 0050）と、一覧の札の口（CardOptionProvider）の両方に同じ形で出す。
 * 押す・戻すのボタン（pressed のある PurchaseOption）で、核の部品が POST のフォームで描く。売り切れの商品にも出す。
 * 会員でない人にも出し、押すとログインへ（FavoritesController）。会員の印は 1 回の要求で 1 度だけ読む（札の枚数だけ引かない。ゲストは引かない）
 */
final class FavoriteButton implements CardOptionProvider, PurchaseOptionProvider
{
    public const string ADD = 'お気に入りに追加';

    public const string REMOVE = 'お気に入りから削除';

    public function forListing(Listing $listing): PurchaseOption
    {
        return $this->button($listing->id);
    }

    public function forCard(string $listingId): PurchaseOption
    {
        return $this->button($listingId);
    }

    private function button(string $listingId): PurchaseOption
    {
        $member = self::member();
        $pressed = $member !== null && app(Favorites::class)->has($member, $listingId);

        return new PurchaseOption('favorites', $pressed ? self::REMOVE : self::ADD, '/p/favorites/'.strtolower($listingId), whenSoldOut: true, pressed: $pressed);
    }

    /**
     * ログインしている、この店の会員の台帳の ID（ゲストは null）
     */
    public static function member(): ?string
    {
        $member = request()->user('customer');

        return $member instanceof Member && $member->belongsHere() && $member->account->disabled_at === null ? $member->account->id : null;
    }
}
