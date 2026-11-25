<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Requests\StockOperationRequest;
use App\Admin\Http\TenantActor;
use App\Core\Inventory\Enums\AdjustmentReason;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InsufficientStockException;
use App\Core\Inventory\Services\InventoryOperations;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 出品物の在庫の入庫と調整（EnterTenant の後）。拠点は出品物と同じ出品者のものだけ。
 */
final class StockController
{
    public function __construct(private readonly InventoryOperations $inventory) {}

    public function receive(StockOperationRequest $request, string $listingId): JsonResponse
    {
        $listing = ListingController::find($listingId);
        $location = $this->location($request, $listing->seller_id);
        $variant = $this->variant($request, $listing);

        $this->guard(fn (): InventoryStock => $this->inventory->receive($variant, $location, (int) $request->validated('quantity'), TenantActor::of($request)));

        return response()->json(['listing' => ListingController::detail($listing)]);
    }

    public function adjust(StockOperationRequest $request, string $listingId): JsonResponse
    {
        $listing = ListingController::find($listingId);
        $location = $this->location($request, $listing->seller_id);
        $reason = AdjustmentReason::from((string) $request->validated('reason'));
        $variant = $this->variant($request, $listing);

        $this->guard(fn (): InventoryStock => $this->inventory->adjust($variant, $location, (int) $request->validated('delta'), $reason, TenantActor::of($request)));

        return response()->json(['listing' => ListingController::detail($listing)]);
    }

    /**
     * 種類はその出品物のものだけ探す（別の商品の種類は「無い」扱い）。指定が無ければ軸の無い商品のただ 1 つの種類
     */
    private function variant(StockOperationRequest $request, Listing $listing): ListingVariant
    {
        $id = $request->validated('variant_id');
        $variant = is_string($id) && $id !== ''
            ? ListingVariant::query()->whereKey($id)->where('listing_id', $listing->id)->first()
            : $listing->soleVariant();

        if ($variant === null) {
            throw ValidationException::withMessages(['variant_id' => '種類を選んでください（この商品の種類ではありません）。']);
        }

        return $variant;
    }

    /**
     * 拠点は出品物の出品者のものだけ探す（別の出品者の拠点は「無い」扱い）。
     */
    private function location(StockOperationRequest $request, string $sellerId): Location
    {
        $location = Location::query()->whereKey((string) $request->validated('location_id'))->where('seller_id', $sellerId)->first();

        if ($location === null) {
            throw ValidationException::withMessages(['location_id' => 'この商品の販売者の拠点ではありません。']);
        }

        return $location;
    }

    /**
     * @param  Closure(): InventoryStock  $action
     */
    private function guard(Closure $action): void
    {
        try {
            $action();
        } catch (InsufficientStockException) {
            throw ValidationException::withMessages(['delta' => '在庫が足りません。注文で確保済みの数より少なくはできません。']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['stock' => $e->getMessage()]);
        }
    }
}
