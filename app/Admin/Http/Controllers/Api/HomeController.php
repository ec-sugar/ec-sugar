<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorWelcomes;
use App\Admin\Services\ShopSetup;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 管理画面のホームの開設の手順と「ようこそ」（EnterTenant の後）。
 * 開設の手順は読むだけ（済んだかは店のデータから決める）。「ようこそ」を閉じた印は、この運営のこの店の分だけを中央に置く
 */
final class HomeController
{
    public function setup(Request $request, ShopSetup $setup, OperatorWelcomes $welcomes): JsonResponse
    {
        return response()->json($setup->steps() + [
            'welcome' => ['seen' => $welcomes->seen(self::operator($request), (string) Tenant::current()->getTenantKey())],
        ]);
    }

    /**
     * 「ようこそ」を閉じた（もう出さない）。店のデータには触らないので、閲覧の段階の人も閉じられる（経路の tenant-level:read）
     */
    public function welcome(Request $request, OperatorWelcomes $welcomes): JsonResponse
    {
        $welcomes->markSeen(self::operator($request), (string) Tenant::current()->getTenantKey());

        return response()->json(['welcome' => ['seen' => true]]);
    }

    private static function operator(Request $request): Operator
    {
        $operator = $request->user('operator');
        assert($operator instanceof Operator);

        return $operator;
    }
}
