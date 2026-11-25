<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Models\Tenant;
use App\Storefront\Access\ListingPreviewLink;
use App\Storefront\Catalog\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 商品のプレビューのリンク（EnterTenant の後）。管理画面のログインはショップのホストに届かないので、
 * ショップのその商品の画面だけを開ける署名つきの期限つきのリンク（30 分）を作って返す。閲覧の段階から（見るだけの操作。ADR 0024）。
 * 売る側の固定価格の商品だけ（買取の商品などはショップに商品の画面が無い）。作ったことは、どの商品のリンクかと一緒に監査ログに 1 行残す
 * （EnterTenant の監査は経路の型だけ）
 */
final class ListingPreviewController
{
    public function store(Request $request, AuditLog $audit, string $listingId): JsonResponse
    {
        $listing = ListingController::find($listingId);

        if (! Catalog::previewable($listing)) {
            return self::refuse('この商品はショップに商品の画面が無いため、プレビューを開けません（プレビューできるのは、販売する商品で価格を設定したものだけです）。');
        }

        $tenant = tenant();
        $link = $tenant instanceof Tenant ? ListingPreviewLink::issue($tenant, $listing->id) : null;

        if (! $tenant instanceof Tenant || $link === null) {
            return self::refuse('ショップの URL が設定されていないため、プレビューを開けません。');
        }

        $operator = Auth::guard('operator')->user();
        $audit->record('operator.listing_preview_issued', $operator instanceof Operator ? $operator : null, [
            'listing_id' => $listing->id,
            'expires_at' => $link['expires_at']->toIso8601String(),
        ], (string) $tenant->getTenantKey(), $request->ip());

        return response()->json(['url' => $link['url'], 'expires_at' => $link['expires_at']->toIso8601String()]);
    }

    private static function refuse(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'user_message' => $message], 422);
    }
}
