<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 設定 › ポイント（ADR 0068 の 7）。使う／使わないと率（0〜20%）。読むのは閲覧、変えるのは運用（EnterTenant の既定）。
 * まだ使われていないポイントの合計（店の負債）と、残高のある会員の数は、お金の段階の人にだけ返す（ほかは null。売上と同じ段）。変えたら中央の監査ログに値だけを残す
 */
final class PointSettingsController
{
    public function __construct(
        private readonly PointSettings $settings,
        private readonly PointService $points,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['points' => $this->body($request)]);
    }

    public function update(Request $request, AuditLog $audit): JsonResponse
    {
        $saved = $this->settings->update($request->only(['enabled', 'rate_bp']), TenantActor::of($request));

        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);
        $audit->record('operator.points_settings_changed', $operator, $saved, (string) Tenant::current()->getTenantKey(), $request->ip());

        return response()->json(['points' => $this->body($request)]);
    }

    /**
     * @return array{enabled: bool, rate_bp: int, outstanding: int|null, holders: int|null}
     */
    private function body(Request $request): array
    {
        $money = $request->attributes->get(EnterTenant::LEVEL) === 'money';

        return [...$this->settings->show(), 'outstanding' => $money ? $this->points->outstanding() : null, 'holders' => $money ? $this->points->holders() : null];
    }
}
