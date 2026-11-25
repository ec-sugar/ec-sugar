<?php

declare(strict_types=1);

namespace App\Admin\Demo;

use App\Core\Event\EventStore;
use App\Core\Inventory\Enums\AdjustmentReason;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InventoryOperations;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * デモの店の売れる数を、作った時の数へ戻す（ADR 0086。1 時間ごとの demo:restock から、デモの店の文脈の中で）。
 *
 * デモの店は誰でも払わずに（銀行振込・代引き・テストのカードで）注文でき、来訪者ごとの注文の数の上限（DemoOrders）があっても、
 * 合い言葉を作り直せば別の来訪者になれる。売り切れのまま翌朝の戻しまで残さないよう、1 時間ごとに次をそろえる:
 * - 在庫: 商品（種類）ごとの「売れる数（在庫 − 押さえた数）」を、作った時の在庫の数にする。核の在庫の調整（InventoryOperations::adjust。
 *   理由は棚卸し、連鎖のロックとイベント）。押さえた数（払う前の注文）はそのまま
 * - App が数える売れる数（予約の枠・予約販売の数・席）: その App の戻しの口（DemoRestockHandler）に、作った時の欄の値を渡す
 * 注文・予約・チケットは取り消さない（お客さんの見えるものを勝手に変えない）。毎晩の戻しで全部消える
 */
final class DemoRestock
{
    public const string ACTOR = 'system:demo-restock';

    public function __construct(
        private readonly EventStore $events,
        private readonly InventoryOperations $inventory,
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * @return int 直した数（在庫の行・App の枠などの数）
     */
    public function run(DemoDefinition $def): int
    {
        $changed = 0;

        foreach ($def->listings() as $key => $spec) {
            $listing = DemoShopBuilder::find($def, $key);

            if ($listing === null) {
                continue;
            }

            foreach (self::targets($listing, $spec) as [$variant, $target]) {
                // 1 つの商品が落ちても、ほかの商品は続ける（次の回にもう一度）
                try {
                    $changed += $this->stock($listing, $variant, $target);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        foreach ($def->panels() as $key => $byPlugin) {
            $listing = DemoShopBuilder::find($def, $key);

            if ($listing === null) {
                continue;
            }

            foreach ($byPlugin as $plugin => $values) {
                $handler = $this->plugins->has($plugin) ? $this->plugins->get($plugin)->demoRestock : null;

                if ($handler === null || ! $this->states->enabled($plugin)) {
                    continue;
                }

                try {
                    $changed += $handler->restockDemo($listing, $values, self::ACTOR);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $changed;
    }

    /**
     * 商品の種類ごとの作った時の在庫の数
     *
     * @param  array<string, mixed>  $spec  入れたときのサンプルの形の商品
     * @return list<array{0: ListingVariant, 1: int}>
     */
    private static function targets(Listing $listing, array $spec): array
    {
        $out = [];

        if (isset($spec['variants']) && is_array($spec['variants'])) {
            foreach ($spec['variants'] as $variantSpec) {
                $variant = is_array($variantSpec) && is_string($variantSpec['sku'] ?? null)
                    ? ListingVariant::query()->where('listing_id', $listing->id)->where('sku', $variantSpec['sku'])->first()
                    : null;

                if ($variant !== null) {
                    $out[] = [$variant, max(0, (int) ($variantSpec['stock'] ?? 0))];
                }
            }

            return $out;
        }

        $variant = $listing->soleVariant();

        return $variant === null ? [] : [[$variant, max(0, (int) ($spec['stock'] ?? 0))]];
    }

    /**
     * 売れる数を作った時の数にする（違えば 1）。連鎖のロックの中で今の数を読み、核の在庫の調整で差を足し引きする
     */
    private function stock(Listing $listing, ListingVariant $variant, int $target): int
    {
        // 入れたときのサンプルと同じ拠点（出品者のいちばん古い拠点）
        $location = Location::query()->where('seller_id', $listing->seller_id)->orderBy('created_at')->first();

        if ($location === null) {
            return 0;
        }

        return DB::transaction(function () use ($variant, $location, $target): int {
            $this->events->lock();
            $stock = InventoryStock::query()->where('variant_id', $variant->id)->where('location_id', $location->id)->lockForUpdate()->first();
            $delta = $target - ($stock?->available() ?? 0);

            if ($delta === 0) {
                return 0;
            }

            $this->inventory->adjust($variant, $location, $delta, AdjustmentReason::Stocktake, self::ACTOR);

            return 1;
        });
    }
}
