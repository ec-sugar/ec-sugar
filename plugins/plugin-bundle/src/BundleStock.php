<?php

declare(strict_types=1);

namespace EcSugar\PluginBundle;

use App\Core\Inventory\Services\ComponentStock;
use App\Core\Plugin\Contracts\ListingVisibilityProvider;
use EcSugar\PluginBundle\Models\BundleComponent;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * セットの読み出し。「このセットは何から引くか」と「今いくつ作れるか」を答える。
 *
 * 作れるセットの数は、構成の品ごとの「（在庫 − 確保）÷ 1 セットあたりの数」の**いちばん小さい値**。
 * 構成の品が消えた・下書き・公開していない・種類を止めた時は 0（1 セットも作れない＝ショップに出さない）。
 * 数えるのは DB の中で 1 回の問いにまとめる（商品ごとに問いを並べない）。
 *
 * ここは**読むだけ**で、例外を投げない作りにする（在庫の構成を答える口は、落ちると取引が止まる。StockComponents）
 */
final class BundleStock
{
    /** 構成の品が売れない時に使う「作れるセットの数」 */
    private const int NONE = 0;

    /**
     * それぞれの商品の在庫を引く品（商品の ID => 種類の ID => 1 セットあたりの数）。
     * セットでない商品は答えに入らない。核の在庫の口（StockComponentsProvider）が呼ぶので、素直に表を読むだけにする
     *
     * @param  list<string>  $listingIds
     * @return array<string, array<string, int>>
     */
    public static function componentsOf(array $listingIds): array
    {
        $out = [];

        foreach (BundleComponent::query()->whereIn('listing_id', $listingIds)->get(['listing_id', 'variant_id', 'quantity']) as $row) {
            $out[(string) $row->listing_id][(string) $row->variant_id] = (int) $row->quantity;
        }

        return $out;
    }

    /**
     * この店の全部のセットの構成（商品の ID => 種類の ID => 1 セットあたりの数）
     *
     * @return array<string, array<string, int>>
     */
    private static function allComponents(): array
    {
        $out = [];

        foreach (BundleComponent::query()->get(['listing_id', 'variant_id', 'quantity']) as $row) {
            $out[(string) $row->listing_id][(string) $row->variant_id] = (int) $row->quantity;
        }

        return $out;
    }

    /**
     * 今いくつ作れるか（セットの商品の ID => 作れるセットの数）。セットでない商品は答えに入らない。
     * $listingIds を渡すと、その商品だけを数える。
     *
     * **数えるのは核の ComponentStock**（拠点ごとに「構成の品から作れる数」を出して足す）。
     * ショップの見せ方・注文の拠点の決定・在庫を押さえる所がどれも同じ数え方を見るので食い違わない
     *
     * @param  list<string>|null  $listingIds
     * @return array<string, int>
     */
    public static function sets(?array $listingIds = null): array
    {
        return app(ComponentStock::class)->totals($listingIds === null ? self::allComponents() : self::componentsOf($listingIds));
    }

    /**
     * 1 セットも作れないセットの商品の ID（ショップの一覧・商品の画面から外す）。
     * $listingIds を渡すと、その商品のうち作れないものだけ
     *
     * @param  list<string>|null  $listingIds
     * @return list<string>
     */
    public static function unbuildable(?array $listingIds = null): array
    {
        $out = [];

        foreach (self::sets($listingIds) as $listingId => $sets) {
            if ($sets < 1) {
                $out[] = (string) $listingId;
            }
        }

        sort($out);

        return array_slice($out, 0, ListingVisibilityProvider::MAX_HIDDEN);
    }

    /**
     * セットの商品の数（管理画面の件数）
     */
    public static function count(): int
    {
        return BundleComponent::query()->distinct()->count('listing_id');
    }

    /**
     * 構成の品の様子（管理画面と商品の画面の案内に出す。1 セットあたりの数の多い順ではなく、足した順）
     *
     * @return list<array{variant: string, listing: string, title: string, sku: ?string, quantity: int, free: int, sets: int, sellable: bool}>
     */
    public static function parts(string $listingId): array
    {
        $out = [];

        foreach (self::joined()->where('c.listing_id', $listingId)->orderBy('c.created_at')->orderBy('c.id')->get([
            'c.variant_id', 'c.part_listing_id', 'c.quantity', 'p.title', 'v.sku', 'v.status as variant_status', 'p.status as part_status',
            DB::raw('COALESCE(s.free, 0) AS free'),
        ]) as $row) {
            $sellable = $row->variant_status === 'active' && $row->part_status === 'active';
            $quantity = max(1, (int) $row->quantity);
            $free = max(0, (int) $row->free);
            $out[] = [
                'variant' => (string) $row->variant_id,
                'listing' => (string) $row->part_listing_id,
                'title' => is_string($row->title) ? $row->title : '（削除された商品）',
                'sku' => is_string($row->sku) ? $row->sku : null,
                'quantity' => $quantity,
                'free' => $free,
                'sets' => $sellable ? intdiv($free, $quantity) : self::NONE,
                'sellable' => $sellable,
            ];
        }

        return $out;
    }

    /**
     * 構成の品に、種類・商品・空いている在庫をつないだ問い（消した種類・消した商品は欄が空になる）
     */
    private static function joined(): Builder
    {
        $free = DB::table('inventory_stocks')
            ->selectRaw('variant_id, SUM(GREATEST(quantity - reserved, 0)) AS free')
            ->groupBy('variant_id');

        return DB::table('bundle_components as c')
            ->leftJoin('listing_variants as v', static fn (JoinClause $join) => $join->on('v.id', '=', 'c.variant_id')->whereNull('v.deleted_at'))
            ->leftJoin('listings as p', static fn (JoinClause $join) => $join->on('p.id', '=', 'c.part_listing_id')->whereNull('p.deleted_at'))
            ->leftJoinSub($free, 's', 's.variant_id', '=', 'c.variant_id');
    }
}
