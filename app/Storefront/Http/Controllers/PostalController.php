<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Storefront\Postal\PostalAddress;
use App\Storefront\Postal\PostalLookup;
use Illuminate\Http\JsonResponse;

/**
 * 郵便番号から住所。注文の入力の画面の JavaScript が呼ぶ。7 桁の数字だけ。見つからなければ空の一覧
 */
final class PostalController
{
    public function show(PostalLookup $lookup, string $code): JsonResponse
    {
        // 答えはサーバーで 1 日覚える（ZipcloudPostalLookup）。ブラウザには覚えさせない
        return response()->json(['addresses' => array_map(static fn (PostalAddress $a): array => $a->toArray(), $lookup->find($code))]);
    }
}
