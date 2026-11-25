<?php

declare(strict_types=1);

namespace App\Core\Inventory\Services;

use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 数量在庫の操作。在庫は種類 × 拠点（ADR 0036）。すべて行ロック付きのトランザクションで行い、同時注文での引き落とし過ぎを防ぐ。
 *
 * 語彙:
 *   receive  入庫（quantity を増やす）
 *   reserve  確保（取引の合意で reserved を増やす。quantity は変えない）
 *   release  確保の解除（取消）
 *   commit   確保分の出庫（受渡で quantity と reserved を同時に減らす）
 *   adjust   棚卸しなどの直接調整
 *
 * 種類の代わりに商品を渡してよいのは、軸の無い商品（種類が 1 つ）だけ。種類のある商品は種類を渡す
 */
final class StockService
{
    public function receive(Listing|ListingVariant $variant, Location $location, int $quantity): InventoryStock
    {
        $this->assertPositive($quantity);

        return $this->mutate($variant, $location, static function (InventoryStock $stock) use ($quantity): void {
            $stock->quantity += $quantity;
        });
    }

    public function reserve(Listing|ListingVariant $variant, Location $location, int $quantity): InventoryStock
    {
        $this->assertPositive($quantity);

        return $this->mutate($variant, $location, static function (InventoryStock $stock) use ($quantity): void {
            if ($stock->available() < $quantity) {
                throw new InsufficientStockException("在庫が足りません（利用可能 {$stock->available()}、要求 {$quantity}）");
            }
            $stock->reserved += $quantity;
        });
    }

    public function release(Listing|ListingVariant $variant, Location $location, int $quantity): InventoryStock
    {
        $this->assertPositive($quantity);

        return $this->mutate($variant, $location, static function (InventoryStock $stock) use ($quantity): void {
            if ($stock->reserved < $quantity) {
                throw new InvalidArgumentException("確保数を超えて解除できません（確保 {$stock->reserved}、要求 {$quantity}）");
            }
            $stock->reserved -= $quantity;
        });
    }

    public function commit(Listing|ListingVariant $variant, Location $location, int $quantity): InventoryStock
    {
        $this->assertPositive($quantity);

        return $this->mutate($variant, $location, static function (InventoryStock $stock) use ($quantity): void {
            if ($stock->reserved < $quantity) {
                throw new InvalidArgumentException("確保していない数は出庫できません（確保 {$stock->reserved}、要求 {$quantity}）");
            }
            $stock->reserved -= $quantity;
            $stock->quantity -= $quantity;
        });
    }

    public function adjust(Listing|ListingVariant $variant, Location $location, int $delta): InventoryStock
    {
        return $this->mutate($variant, $location, static function (InventoryStock $stock) use ($delta): void {
            if ($stock->quantity + $delta < $stock->reserved) {
                throw new InsufficientStockException('確保済みの数を下回る調整はできません');
            }
            $stock->quantity += $delta;
        });
    }

    /**
     * @param  callable(InventoryStock): void  $change
     */
    private function mutate(Listing|ListingVariant $variant, Location $location, callable $change): InventoryStock
    {
        $variant = self::variantOf($variant);

        return DB::transaction(function () use ($variant, $location, $change): InventoryStock {
            $stock = InventoryStock::query()
                ->where('variant_id', $variant->id)
                ->where('location_id', $location->id)
                ->lockForUpdate()
                ->first();

            if ($stock === null) {
                $stock = new InventoryStock([
                    'listing_id' => $variant->listing_id,
                    'variant_id' => $variant->id,
                    'location_id' => $location->id,
                    'quantity' => 0,
                    'reserved' => 0,
                ]);
            }

            $change($stock);

            if ($stock->quantity < 0 || $stock->reserved < 0) {
                throw new InsufficientStockException('在庫が負になります');
            }

            $stock->save();

            return $stock;
        });
    }

    public static function variantOf(Listing|ListingVariant $target): ListingVariant
    {
        return $target instanceof ListingVariant
            ? $target
            : ($target->soleVariant() ?? throw new InvalidArgumentException('種類のある商品は、種類を指定してください'));
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('数量は 1 以上です');
        }
    }
}
