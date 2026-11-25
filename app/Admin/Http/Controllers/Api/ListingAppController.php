<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Core\Plugin\Manifest\AppManifest;
use App\Core\Plugin\Services\ListingPanels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 商品の画面の App の欄（EnterTenant の後。ADR 0050）。この店で使っている App のうち、欄を出すものの宣言と値。
 * 読むのは閲覧、保存は運用の段階から（経路の印）。欄は核の部品が描き、App の画面のコードは読み込まない。
 * {listingId} はモデルに結びつけず、テナント文脈の中で探す（ほかの店の商品は見つからない）
 */
final class ListingAppController
{
    public function __construct(private readonly ListingPanels $panels) {}

    public function index(string $listingId): JsonResponse
    {
        return response()->json(['apps' => $this->panels->forListing(ListingController::find($listingId))]);
    }

    /**
     * 1 つの App の欄の値を保存する。使っていない・欄の無い App は 404（値の形より先に見る）、形の誤りは 422（values.<欄の名前>）
     */
    public function update(Request $request, string $listingId, string $plugin): JsonResponse
    {
        $this->panels->definition($plugin);
        $listing = ListingController::find($listingId);
        $data = $request->validate(['values' => ['present', 'array', 'max:'.AppManifest::MAX_SETTINGS]]);

        return response()->json(['app' => $this->panels->write($plugin, $listing, $data['values'], TenantActor::of($request))]);
    }
}
