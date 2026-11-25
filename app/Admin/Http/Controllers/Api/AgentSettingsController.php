<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Core\Shop\Services\AgentSettings;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 設定 › AI エージェントの店の設定（ADR 0101）: 書き込みを「すぐ反映」か「承認してから」と、お客さんの情報を渡すか。
 * 読むのは閲覧、変えるのは運用（EnterTenant の既定）で、直前の再確認も（経路の RequireRecentConfirmation）。お客さんの情報を渡し始めるのはお金の段階だけ。
 * 変えたら中央の監査ログに値だけを残す
 */
final class AgentSettingsController
{
    public function __construct(private readonly AgentSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['settings' => $this->settings->show()]);
    }

    public function update(Request $request, AuditLog $audit): JsonResponse
    {
        // お客さんの情報を渡し始めるのは、お金の段階の人（オーナーはどの店でもお金の段階）だけ。止めるのは運用から（見直しの中 4）
        if ($request->boolean('customer_data') && ! $this->settings->sharesCustomerData() && $request->attributes->get(EnterTenant::LEVEL) !== 'money') {
            abort(403, 'お客さんの情報を渡す設定にできるのは、「会計」の権限のある人かオーナーだけです。');
        }

        $saved = $this->settings->update($request->only(['write_mode', 'customer_data']), TenantActor::of($request));
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);
        $audit->record('operator.agent_settings_changed', $operator, $saved, (string) Tenant::current()->getTenantKey(), $request->ip());

        return response()->json(['settings' => $saved]);
    }
}
