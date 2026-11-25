<?php

declare(strict_types=1);

namespace App\Core\Inventory\Services;

use App\Core\Event\EventStore;
use App\Core\Inventory\Enums\AdjustmentReason;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 人が行う在庫の操作（入庫と調整）。数の増減は StockService に任せ、ここでは誰が・なぜを記録に残す。
 *
 * 取引に伴う確保・出庫は TransactionService が StockService を直接使う（取引のイベントが記録になる）。
 * 書き込みは EventStore::lock() を先に取ってから行ロック（ADR 0012）。
 */
final class InventoryOperations
{
    public function __construct(
        private readonly StockService $stocks,
        private readonly EventStore $events,
    ) {}

    public function receive(Listing|ListingVariant $variant, Location $location, int $quantity, ?string $actor = null): InventoryStock
    {
        return $this->run($variant, $location, 'inventory.received', ['quantity' => $quantity], $actor,
            fn (): InventoryStock => $this->stocks->receive($variant, $location, $quantity));
    }

    public function adjust(Listing|ListingVariant $variant, Location $location, int $delta, AdjustmentReason $reason, ?string $actor = null): InventoryStock
    {
        if ($delta === 0) {
            throw new InvalidArgumentException('調整の数は 0 以外です');
        }

        return $this->run($variant, $location, 'inventory.adjusted', ['delta' => $delta, 'reason' => $reason->value], $actor,
            fn (): InventoryStock => $this->stocks->adjust($variant, $location, $delta));
    }

    /**
     * @param  array<string, int|string>  $payload
     * @param  Closure(): InventoryStock  $change
     */
    private function run(Listing|ListingVariant $variant, Location $location, string $type, array $payload, ?string $actor, Closure $change): InventoryStock
    {
        $variant = StockService::variantOf($variant);
        $listing = $variant->listing()->firstOrFail();

        // 別の出品者の拠点には置けない（DB のトリガーでも拒む）
        if ($location->seller_id !== $listing->seller_id) {
            throw new InvalidArgumentException('出品物と拠点の販売者が違います');
        }

        return DB::transaction(function () use ($listing, $variant, $location, $type, $payload, $actor, $change): InventoryStock {
            $this->events->lock();

            $stock = $change();

            $this->events->append($type, 'listing', $listing->id, $payload + [
                'variant_id' => $variant->id,
                'location_id' => $location->id,
                'quantity_after' => $stock->quantity,
                'reserved_after' => $stock->reserved,
            ], $actor);

            return $stock;
        });
    }
}
