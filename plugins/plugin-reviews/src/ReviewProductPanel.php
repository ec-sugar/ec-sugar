<?php

declare(strict_types=1);

namespace EcSugar\PluginReviews;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ProductPanelProvider;
use App\Core\Plugin\ProductPanel;
use App\Core\Plugin\ProductPanelItem;
use App\Core\Plugin\ProductPanelRating;
use EcSugar\PluginReviews\Models\Review;

/**
 * ショップの商品の画面の「レビュー」。公開したレビューの平均の星と件数、新しい順に SHOWN 件（最初から開いて出す）。
 * 無ければ出さない。描くのは核の部品（文はエスケープ。JavaScript なしで読める）。出すのは表示名と書いた日だけで、名前・メール・注文は出さない
 */
final class ReviewProductPanel implements ProductPanelProvider
{
    public const int SHOWN = 20;

    public function forListing(Listing $listing): ?ProductPanel
    {
        $published = Review::query()->where('listing_id', $listing->id)->where('status', 'published');
        /** @var object{count: int|string, total: int|string|null}|null $sum */
        $sum = (clone $published)->selectRaw('count(*) as count, sum(rating) as total')->toBase()->first();
        $count = (int) ($sum->count ?? 0);

        if ($count === 0) {
            return null;
        }

        $reviews = $published->orderByDesc('created_at')->orderByDesc('id')->limit(self::SHOWN)->get();

        return new ProductPanel(
            'reviews',
            'レビュー',
            $reviews->map(static fn (Review $r): ProductPanelItem => new ProductPanelItem(
                (string) $r->title,
                (string) $r->body,
                rating: $r->rating,
                meta: $r->display_name.'・'.$r->created_at->setTimezone('Asia/Tokyo')->format('Y年n月j日'),
                reply: $r->reply,
            ))->values()->all(),
            rating: new ProductPanelRating(self::tenths((int) $sum->total, $count), $count),
            open: true,
        );
    }

    /**
     * 平均を 10 倍して四捨五入した整数（浮動小数を使わない。4.25 は 43）
     */
    public static function tenths(int $total, int $count): int
    {
        return intdiv(20 * $total + $count, 2 * $count);
    }
}
