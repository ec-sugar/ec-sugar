<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Services\TraceRecords;
use Illuminate\Http\JsonResponse;

/**
 * 処理の ID（trace ID）で、店の関係する記録を並べる（ADR 0087）。オーナーだけ（経路の RequireOwner）、読むだけ。
 * 店の文脈（EnterTenant）の中で動き、入った店の記録だけを返す
 */
final class TraceController
{
    public function show(TraceRecords $records, string $traceId): JsonResponse
    {
        return response()->json($records->present($traceId));
    }
}
