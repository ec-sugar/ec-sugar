<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Core\Shop\Services\ShippingMethods;
use App\Core\Shop\Shipping\Regions;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 設定 › 配送（ADR 0064）: 配送の方法ごとの都道府県の送料と「この金額以上で無料」。読むのは閲覧から、変えるのは運用の段階から（経路の印）。
 * 保存は表をまとめて置き換える。変えたら中央の監査ログに 1 行（誰が・どの店で。額は店の記録のイベントに）
 */
final class ShippingMethodController
{
    public function __construct(
        private readonly ShippingMethods $methods,
        private readonly AuditLog $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->present());
    }

    public function update(Request $request): JsonResponse
    {
        $this->methods->replace($request->only(['methods']), TenantActor::of($request));
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);
        $this->audit->record('operator.shipping_changed', $operator, ['methods' => count($this->methods->all())], (string) Tenant::current()->getTenantKey(), $request->ip());

        return response()->json($this->present());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(): array
    {
        return [
            'methods' => $this->methods->all(),
            // 入力をまとめる地域（額は都道府県ごとに持つ）
            'regions' => array_map(static fn (string $name, array $prefectures): array => ['name' => $name, 'prefectures' => $prefectures], array_keys(Regions::ALL), Regions::ALL),
            'limits' => ['methods' => ShippingMethods::MAX_METHODS, 'fee' => ShippingMethods::MAX_FEE, 'free_over' => ShippingMethods::MAX_FREE_OVER, 'name' => ShippingMethods::MAX_NAME],
        ];
    }
}
