<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Requests\DefineVariantOptionsRequest;
use App\Admin\Http\Requests\UpdateVariantRequest;
use App\Admin\Http\TenantActor;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Services\StaleListingException;
use App\Core\Listing\Services\VariantService;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 商品の種類（色・サイズなど。ADR 0036）の編集（EnterTenant の後）。変えるのは核の VariantService だけ。
 * どちらも商品の詳細を返す（種類の変更で商品のバージョンが進むので、画面は返った詳細のバージョンで次を送る）。
 */
final class VariantController
{
    public function __construct(private readonly VariantService $variants) {}

    /**
     * 選ぶ軸と値を決め直す。値の組み合わせごとに種類ができ、無くなった組み合わせの種類は消える。
     */
    public function define(DefineVariantOptionsRequest $request, string $listingId): JsonResponse
    {
        $listing = ListingController::find($listingId);

        return $this->respond(fn () => $this->variants->define($listing, (int) $request->validated('version'), $request->definedOptions(), TenantActor::of($request), $request->carryTo()), $listingId);
    }

    public function update(UpdateVariantRequest $request, string $listingId, string $variantId): JsonResponse
    {
        $listing = ListingController::find($listingId);
        // 種類はその商品のものだけ探す（別の商品の種類は「無い」扱い）
        $variant = ListingVariant::query()->whereKey($variantId)->where('listing_id', $listing->id)->firstOrFail();

        return $this->respond(fn () => $this->variants->update($variant, (int) $request->validated('version'), $request->changes(), TenantActor::of($request)), $listingId);
    }

    /**
     * 同時編集の衝突は 409、品番の重なりと核の規則違反は入力の誤り（422）に写す。
     *
     * @param  Closure(): mixed  $action
     */
    private function respond(Closure $action, string $listingId): JsonResponse
    {
        try {
            $action();
        } catch (StaleListingException $e) {
            return response()->json([
                'message' => '別の操作で先に更新されています。最新の内容を読み込み直してから、もう一度保存してください。',
                'current_version' => $e->currentVersion,
            ], 409);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => 'この販売者には、同じ品番の商品か種類がすでにあります。']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['variant' => $e->getMessage()]);
        }

        return response()->json(['listing' => ListingController::detail(ListingController::find($listingId))]);
    }
}
