<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\ShopNames;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * 店の名前を変える。設定 › 基本から。オーナーだけ・直前のパスキーの再確認の後（経路の印）。
 * 名前はメールの差出人の名前と件名にも出る（乗っ取ったセッションで店を装う名前に替えさせない）ので、メンバーと権限・サンプルの片付けと同じ段階にした。
 * 形の検査・デモの店の断り・書く順番・記録は ShopNames
 */
final class ShopNameController
{
    public function __construct(private readonly ShopNames $names) {}

    public function update(Request $request): JsonResponse
    {
        $operator = $request->user('operator');

        if (! $operator instanceof Operator) {
            throw new LogicException('ログインした運営の経路です');
        }

        $tenant = Tenant::current();
        $changed = $this->names->rename($tenant, $request->input('name'), $operator, TenantActor::of($request), $request->ip());

        return response()->json(['tenant' => ['id' => $tenant->getTenantKey(), 'name' => $tenant->getAttribute('name')], 'changed' => $changed]);
    }
}
