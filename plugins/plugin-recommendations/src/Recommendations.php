<?php

declare(strict_types=1);

namespace EcSugar\PluginRecommendations;

use App\Storefront\Catalog\Catalog;
use App\Storefront\Catalog\ListingCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * おすすめの区画に並べる商品（ADR 0071）。どれもショップに出ていて、いま買える（売り切れでない）商品だけ（Catalog の札）。
 * ショップの画面で重い問い合わせをしない: 注文の明細は読まない（ランキングは 1 時間ごとに数えた表を読む）。1 つの区画で数回の小さな問い合わせだけ
 *
 * - 関連商品: お客さんが最後に見た商品（最近見た商品の Cookie の先頭）と同じカテゴリの商品。足りない分と、まだ見ていないお客さんには新着
 * - 最近見た商品: Cookie の ID の順（新しい順）
 * - 売れ筋ランキング: 数えた順位の順
 */
final class Recommendations
{
    public const array COUNTS = ['four' => 4, 'eight' => 8, 'twelve' => 12];

    public function __construct(private readonly Catalog $catalog) {}

    /**
     * 欄「並べる数」の値から数（選んでいなければ 4）
     */
    public static function count(mixed $choice): int
    {
        return self::COUNTS[is_string($choice) ? $choice : ''] ?? 4;
    }

    /**
     * @return list<ListingCard>
     */
    public function related(Request $request, int $count): array
    {
        $base = RecentlyViewed::ids($request)[0] ?? null;
        $cards = [];

        if ($base !== null) {
            $category = DB::table('listing_categories')->where('listing_id', $base)->orderBy('category_id')->value('category_id');
            $cards = is_string($category) ? $this->catalog->inCategory($category, $count * 2 + 1) : [];
        }

        $cards = self::buyable($cards, $base === null ? [] : [$base]);

        if (count($cards) < $count) {
            $cards = [...$cards, ...self::buyable($this->catalog->featured($count * 2 + 1), [...array_map(static fn (ListingCard $c): string => $c->id, $cards), ...($base === null ? [] : [$base])])];
        }

        return array_slice($cards, 0, $count);
    }

    /**
     * @return list<ListingCard>
     */
    public function recent(Request $request, int $count): array
    {
        return array_slice(self::buyable($this->catalog->picked(RecentlyViewed::ids($request)), []), 0, $count);
    }

    /**
     * @return list<ListingCard>
     */
    public function ranking(int $count): array
    {
        $ids = DB::table('recommendations_ranks')->orderBy('position')->limit($count * 3)->pluck('listing_id')->map(static fn ($id): string => (string) $id)->all();

        return array_slice(self::buyable($this->catalog->picked($ids), []), 0, $count);
    }

    /**
     * いま買える札だけ（売り切れと、外す商品を除く）
     *
     * @param  list<ListingCard>  $cards
     * @param  list<string>  $except
     * @return list<ListingCard>
     */
    private static function buyable(array $cards, array $except): array
    {
        return array_values(array_filter($cards, static fn (ListingCard $c): bool => $c->availability !== ListingCard::SOLD_OUT && ! in_array($c->id, $except, true)));
    }
}
