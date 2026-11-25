<?php

declare(strict_types=1);

namespace EcSugar\PluginSalePeriod;

use App\Core\Plugin\Contracts\ListingVisibilityProvider;
use Carbon\CarbonImmutable;
use EcSugar\PluginSalePeriod\Models\SaleWindow;

/**
 * 公開の予約と販売期間の読み出し。「今」は PHP の時刻で比べる（SQL の now() ではなく値として渡す。テストの時間旅行でも同じ答え）。
 *
 * 期間の決まり（境界）:
 * - 出す日時（starts_at）のちょうどに出る（その日時より前だけが「まだ出さない」）
 * - 終わる日時（ends_at）のちょうどに終わる（その日時になった瞬間から売り切れ）
 */
final class SalePeriodSchedule
{
    /** 期間の前の商品の断りの文（ふだんは一覧にも商品の画面にも出ないので、古いカートの行に出る） */
    public const string BEFORE = 'この商品はまだ販売を始めていません。';

    /** 期間の後の商品の断りの文 */
    public const string AFTER = 'この商品は販売期間を終えました。';

    /**
     * まだ出す日時になっていない商品の ID（一覧・商品の画面・サイトマップ・フィードから除く）
     *
     * @return list<string>
     */
    public static function notYetOpen(?CarbonImmutable $now = null): array
    {
        return SaleWindow::query()
            ->notYetOpen($now ?? CarbonImmutable::now())
            ->orderBy('starts_at')
            ->limit(ListingVisibilityProvider::MAX_HIDDEN)
            ->pluck('listing_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * 頼んだ商品のうち、終わる日時を過ぎた商品の ID（商品の画面と札を売り切れにする）
     *
     * @param  list<string>  $listingIds
     * @return list<string>
     */
    public static function closed(array $listingIds, ?CarbonImmutable $now = null): array
    {
        if ($listingIds === []) {
            return [];
        }

        return SaleWindow::query()
            ->whereIn('listing_id', $listingIds)
            ->closed($now ?? CarbonImmutable::now())
            ->pluck('listing_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * 頼んだ商品のうち、今は期間の外にある商品（カートと注文の確定で断る。商品の ID => お客さんに見せる断りの文）
     *
     * @param  list<string>  $listingIds
     * @return array<string, string>
     */
    public static function outside(array $listingIds, ?CarbonImmutable $now = null): array
    {
        if ($listingIds === []) {
            return [];
        }

        $now ??= CarbonImmutable::now();
        $refusals = [];

        foreach (SaleWindow::query()->whereIn('listing_id', $listingIds)->get() as $window) {
            if ($window->starts_at !== null && $window->starts_at->greaterThan($now)) {
                $refusals[$window->listing_id] = self::BEFORE;
            } elseif ($window->ends_at !== null && $window->ends_at->lessThanOrEqualTo($now)) {
                $refusals[$window->listing_id] = self::AFTER;
            }
        }

        return $refusals;
    }
}
