<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Core\Sample\Services\ShopSamples;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * 入れたときのサンプルをまとめて消す。管理画面のホームから。オーナーだけ・直前のパスキーの再確認の後（経路の印）。
 * 消すのは印の付いた物だけ。注文に使われた商品と、お店が書き換えた物・使っている物は残す（ShopSamples）。
 * 誰がどの店で消したかを中央の監査ログに残す（中身は数だけ）
 */
final class SampleController
{
    public function __construct(private readonly ShopSamples $samples) {}

    public function destroy(Request $request, AuditLog $audit): JsonResponse
    {
        $operator = $request->user('operator');

        if (! $operator instanceof Operator) {
            throw new LogicException('ログインした運営の経路です');
        }

        $result = $this->samples->remove(TenantActor::of($request));

        if ($result['removed'] > 0 || $result['kept'] !== []) {
            $audit->record('tenant.samples_removed', $operator, ['removed' => $result['removed'], 'kept' => count($result['kept'])], (string) Tenant::current()->getTenantKey(), $request->ip());
        }

        return response()->json($result + ['samples' => $this->samples->summary()]);
    }
}
