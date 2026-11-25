<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Points\Models\PointEntry;
use App\Core\Points\Services\PointService;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 管理画面のお客さんの 1 人の画面のポイント（ADR 0068 の 6）。
 *
 * - 見る（show）: 残高・期限・付く予定・帳簿（新しい順に 100 行）。数と日時と注文の ID だけで、閲覧の段階から。
 *   店の人の手の足し引きの理由（自由な文）は運用の段階からだけ返す（個人情報を書き込まれうるため。personal と同じ段）
 * - 足す・引く（adjust）: お金の段階で、直前のパスキーの再確認の後（経路の印。ADR 0024）。理由は 200 文字まで。中央の監査ログに数だけを残す（理由は残さない）
 */
final class CustomerPointsController
{
    private const int HISTORY = 100;

    public function __construct(private readonly PointService $points) {}

    public function show(Request $request, string $customerId): JsonResponse
    {
        $account = $this->find($customerId);
        $level = $request->attributes->get(EnterTenant::LEVEL);
        $notes = is_string($level) && (OperatorAccess::LEVELS[$level] ?? 0) >= OperatorAccess::LEVELS['operate'];

        return response()->json(['points' => [
            'member' => PointService::isMember($account),
            'balance' => $this->points->balance($account->id),
            'expires_at' => $this->points->expiresAt($account->id)?->toIso8601String(),
            'pending' => array_map(static fn (array $o): array => ['transaction_id' => $o['transactionId'], 'points' => $o['points'], 'placed_at' => $o['placedAt']->toIso8601String()], $this->points->pendingOrders($account->id)),
            'history' => $this->points->history($account->id, self::HISTORY)->map(static fn (PointEntry $e): array => [
                'id' => $e->id,
                'kind' => $e->kind,
                'cause' => $e->cause,
                'points' => $e->points,
                'balance_after' => $e->balance_after,
                'shortfall' => $e->shortfall,
                'transaction_id' => $e->transaction_id,
                'reason' => $notes ? $e->note : null,
                'by_operator' => str_starts_with((string) $e->actor, 'operator:'),
                'occurred_at' => $e->occurred_at->toIso8601String(),
            ])->values(),
            'can_adjust' => $level === 'money',
        ]]);
    }

    /**
     * 手で足す・引く（ADR 0068 の 6）。会員だけ・残高より多くは引けない（422）
     */
    public function adjust(Request $request, string $customerId, AuditLog $audit): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'not_in:0', 'between:-'.PointService::MAX_ADJUST.','.PointService::MAX_ADJUST],
            'reason' => ['required', 'string', 'max:'.PointService::REASON_MAX],
        ], attributes: ['points' => '調整するポイント', 'reason' => '理由']);
        $account = $this->find($customerId);
        $entry = $this->points->adjust($account, (int) $data['points'], (string) $data['reason'], TenantActor::of($request));

        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);
        // 理由（自由な文）は中央に残さない。だれが・どの台帳に・いくつ
        $audit->record('operator.points_adjusted', $operator, ['customer_account_id' => $account->id, 'points' => $entry->points], (string) Tenant::current()->getTenantKey(), $request->ip());

        return response()->json(['balance' => $entry->balance_after], 201);
    }

    private function find(string $customerId): CustomerAccount
    {
        return CustomerAccount::query()->findOrFail(strtolower($customerId));
    }
}
