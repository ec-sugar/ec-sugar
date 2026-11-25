<?php

declare(strict_types=1);

namespace App\Core\Inventory\Services;

use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\VariantStatus;
use App\Core\Plugin\Services\SaleHolds;
use Illuminate\Support\Facades\DB;

/**
 * 構成する品から、その商品をいくつ作れるか（セット商品）。
 *
 * 作れる数は、構成する品ごとの「（在庫 − 確保）÷ 1 つあたりの数」の **いちばん小さい値**。
 * **拠点ごとに数える**のが肝で、拠点をまたいで足すと「拠点 A にコーヒーだけ・拠点 B にカップだけ」で
 * 1 つも作れないのに作れることになる。買えるかの判定（Catalog）も、どの拠点から出すかの決定
 * （PlaceSingleOrder）も、押さえるとき（LineStock）もこの同じ数え方を見るので食い違わない。
 *
 * 構成する品が消えた・下書き・種類を止めた時は 0（1 つも作れない）。店が非公開にした品を、
 * セットの形で売ってしまわないため。
 *
 * ここは**読むだけ**で、例外を投げない（構成そのものを聞く StockComponents とは役割が違う）
 */
final class ComponentStock
{
    /**
     * 商品ごと・拠点ごとに作れる数（商品の ID => 拠点の ID => 作れる数）。
     * 1 つも作れない拠点は入らない。構成する品を持たない商品は鍵ごと入らない。
     *
     * 問いは 2 つだけ（在庫と、品が売れる状態か）で、商品の数では増えない
     *
     * @param  array<string, array<string, int>>  $componentsByListing  商品の ID => 種類の ID => 1 つあたりの数
     * @return array<string, array<string, int>>
     */
    public function byLocation(array $componentsByListing): array
    {
        $variantIds = [];

        foreach ($componentsByListing as $components) {
            foreach (array_keys($components) as $variantId) {
                $variantIds[(string) $variantId] = true;
            }
        }

        if ($variantIds === []) {
            return [];
        }

        $ids = array_keys($variantIds);
        $free = self::free($ids);
        $sellable = self::sellable($ids);
        $out = [];

        foreach ($componentsByListing as $listingId => $components) {
            if ($components === []) {
                continue;
            }

            $out[(string) $listingId] = self::setsOf($components, $free, $sellable, []);
        }

        return $out;
    }

    /**
     * 1 つの商品について、拠点ごとに作れる数（拠点の ID 順。1 つも作れない拠点は入らない）。
     *
     * $taken は「この注文でもう割り当てた構成する品の数」（`種類の ID|拠点の ID` => 数）。
     * **同じカートの中で、同じ品を複数の行が取り合う**ときに二重に数えないため（PlaceOrder）
     *
     * @param  array<string, int>  $components  種類の ID => 1 つあたりの数
     * @param  array<string, int>  $taken
     * @return array<string, int>
     */
    public function buildable(array $components, array $taken = []): array
    {
        if ($components === []) {
            return [];
        }

        $ids = array_map(static fn (int|string $id): string => (string) $id, array_keys($components));
        $sets = self::setsOf($components, self::free($ids), self::sellable($ids), $taken);
        ksort($sets);

        return $sets;
    }

    /**
     * 商品ごとに、全部の拠点で作れる数の合計（ショップの一覧と商品の画面に出す数）
     *
     * @param  array<string, array<string, int>>  $componentsByListing
     * @return array<string, int>
     */
    public function totals(array $componentsByListing): array
    {
        return array_map(static fn (array $byLocation): int => array_sum($byLocation), $this->byLocation($componentsByListing));
    }

    /**
     * 1 つの拠点のそれぞれで、構成する品がいくつ分あるか
     *
     * @param  array<string, int>  $components
     * @param  array<string, array<string, int>>  $free  種類の ID => 拠点の ID => 空いている在庫
     * @param  array<string, true>  $sellable
     * @param  array<string, int>  $taken  種類の ID|拠点の ID => もう割り当てた数
     * @return array<string, int>
     */
    private static function setsOf(array $components, array $free, array $sellable, array $taken): array
    {
        // 構成する品が 1 つでも売れない状態なら、どの拠点でも作れない
        foreach (array_keys($components) as $variantId) {
            if (! isset($sellable[(string) $variantId])) {
                return [];
            }
        }

        $locations = [];

        foreach (array_keys($components) as $variantId) {
            foreach (array_keys($free[(string) $variantId] ?? []) as $locationId) {
                $locations[(string) $locationId] = true;
            }
        }

        $sets = [];

        foreach (array_keys($locations) as $locationId) {
            $least = null;

            foreach ($components as $variantId => $each) {
                $left = ($free[(string) $variantId][$locationId] ?? 0) - ($taken[$variantId.'|'.$locationId] ?? 0);
                $n = intdiv(max(0, $left), max(1, $each));
                $least = $least === null ? $n : min($least, $n);
            }

            if ($least !== null && $least > 0) {
                $sets[$locationId] = $least;
            }
        }

        return $sets;
    }

    /**
     * 空いている在庫（種類の ID => 拠点の ID => 数）
     *
     * @param  list<string>  $variantIds
     * @return array<string, array<string, int>>
     */
    private static function free(array $variantIds): array
    {
        $out = [];

        foreach (DB::table('inventory_stocks')->whereIn('variant_id', $variantIds)
            ->selectRaw('variant_id, location_id, GREATEST(quantity - reserved, 0) AS free')->get() as $row) {
            $out[(string) $row->variant_id][(string) $row->location_id] = (int) $row->free;
        }

        return $out;
    }

    /**
     * 売れる状態の種類（種類の ID => true）。消した・下書き・止めた品は入らない
     *
     * @param  list<string>  $variantIds
     * @return array<string, true>
     */
    private static function sellable(array $variantIds): array
    {
        $rows = DB::table('listing_variants as v')
            ->join('listings as p', 'p.id', '=', 'v.listing_id')
            ->whereIn('v.id', $variantIds)
            ->whereNull('v.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('v.status', VariantStatus::Active->value)
            ->where('p.status', ListingStatus::Active->value)
            // 別名を付ける（pluck は表の名前を落とすので、'p.id' と 'v.id' はどちらも id になり取り違える）
            ->selectRaw('v.id AS variant_id, p.id AS listing_id')
            ->pluck('listing_id', 'variant_id');

        // ほかの App が売り方を押さえている品（抽選の受付中・予約販売など）は、セットの中身としても売らない。
        // セットの行は「セットの商品」として注文に入るので、押さえる側の関所（行の商品を見る）を素通りしてしまう
        $held = app(SaleHolds::class)->of(array_values(array_unique(array_map(static fn ($id): string => (string) $id, $rows->all()))));
        $out = [];

        foreach ($rows as $variantId => $listingId) {
            if (! isset($held[(string) $listingId])) {
                $out[(string) $variantId] = true;
            }
        }

        return $out;
    }
}
