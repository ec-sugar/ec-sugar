<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Requests\DefineAddonsRequest;
use App\Admin\Http\TenantActor;
use App\Core\Listing\Services\AddonService;
use App\Core\Listing\Services\StaleListingException;
use Illuminate\Http\JsonResponse;

/**
 * 商品のオプション（名入れ・ギフト包装など。ADR 0036）の編集（EnterTenant の後）。変えるのは核の AddonService だけ。
 * 商品の詳細を返す（オプションの変更で商品のバージョンが進むので、画面は返った詳細のバージョンで次を送る）。
 */
final class AddonController
{
    public function __construct(private readonly AddonService $addons) {}

    /**
     * オプションの並び全体を決め直す。形の誤りは核の ValidationException がそのまま入力の誤り（422）になる。
     */
    public function define(DefineAddonsRequest $request, string $listingId): JsonResponse
    {
        $listing = ListingController::find($listingId);

        try {
            $this->addons->define($listing, (int) $request->validated('version'), $request->definedAddons(), TenantActor::of($request));
        } catch (StaleListingException $e) {
            return response()->json([
                'message' => '別の操作で先に更新されています。最新の内容を読み込み直してから、もう一度保存してください。',
                'current_version' => $e->currentVersion,
            ], 409);
        }

        return response()->json(['listing' => ListingController::detail(ListingController::find($listingId))]);
    }
}
