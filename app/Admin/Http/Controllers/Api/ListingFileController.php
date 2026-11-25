<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Core\Plugin\Services\ListingFileUploads;
use App\Core\Plugin\Services\ListingPanels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 商品の画面の App の欄のファイル（EnterTenant の後。ADR 0084。App「ダウンロード販売」の売るファイル）。
 * 大きなファイルを切れ端に分けて受け取る（始める → 切れ端を順に → 終える）。受け取り・確かめは核（ListingFileUploads）、置く場所と表は App。
 * どれも運用の段階から（EnterTenant の既定）。{listingId}・{upload}・{fileId} はモデルに結びつけず、テナント文脈の中で探す。
 * 使っていない・ファイルを置かない・この商品に出さない App、ほかの運営が始めた上げる記録は 404
 */
final class ListingFileController
{
    public function __construct(
        private readonly ListingFileUploads $uploads,
        private readonly ListingPanels $panels,
    ) {}

    public function start(Request $request, string $listingId, string $plugin): JsonResponse
    {
        $listing = ListingController::find($listingId);
        $this->uploads->handler($plugin, $listing);
        $data = $request->validate(['name' => ['required', 'string', 'max:1000'], 'size' => ['required', 'integer', 'min:1']]);

        return response()->json($this->uploads->start($plugin, $listing, $data['name'], (int) $data['size'], TenantActor::of($request)), 201);
    }

    public function chunk(Request $request, string $listingId, string $plugin, string $upload): JsonResponse
    {
        $listing = ListingController::find($listingId);

        return response()->json(['received' => $this->uploads->chunk($plugin, $listing, strtolower($upload), $request->input('offset'), $request->file('chunk'), TenantActor::of($request))]);
    }

    /**
     * 上げ終える。置いた後の App の欄（ファイルの並び）を返す
     */
    public function finish(Request $request, string $listingId, string $plugin, string $upload): JsonResponse
    {
        $listing = ListingController::find($listingId);
        $this->uploads->finish($plugin, $listing, strtolower($upload), TenantActor::of($request));

        return response()->json(['app' => $this->panelOf($plugin, $listingId)]);
    }

    public function abort(Request $request, string $listingId, string $plugin, string $upload): JsonResponse
    {
        $this->uploads->abort($plugin, ListingController::find($listingId), strtolower($upload), TenantActor::of($request));

        return response()->json(['aborted' => true]);
    }

    public function destroy(Request $request, string $listingId, string $plugin, string $fileId): JsonResponse
    {
        $this->uploads->delete($plugin, ListingController::find($listingId), strtolower($fileId), TenantActor::of($request));

        return response()->json(['app' => $this->panelOf($plugin, $listingId)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function panelOf(string $plugin, string $listingId): ?array
    {
        foreach ($this->panels->forListing(ListingController::find($listingId)) as $app) {
            if ($app['plugin'] === $plugin) {
                return $app;
            }
        }

        return null;
    }
}
