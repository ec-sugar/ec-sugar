<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorGrant;
use App\Admin\Services\AccessRuleException;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\OperatorEnrollments;
use App\Admin\Services\OperatorRecoveryCodes;
use App\Admin\Services\OperatorSessions;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 運営のメンバーと権限（ADR 0024）。オーナーだけ（経路の RequireOwner）、変えるのは直前の再確認の後（RequireRecentConfirmation）。
 * 変えたことは監査ログへ。パスキーの登録のリンクは作ったときに 1 回だけ返す（オーナーが本人に渡す）
 */
final class MemberController
{
    public function __construct(
        private readonly OperatorAccess $access,
        private readonly AuditLog $audit,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->present());
    }

    public function store(Request $request, OperatorEnrollments $enrollments): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'email' => ['required', 'string', 'email:strict', 'max:254'],
        ], [], ['name' => '表示名', 'email' => 'メールアドレス']);
        $email = mb_strtolower(trim($data['email']));

        if (Operator::query()->whereEmail($email)->exists()) {
            return response()->json(['message' => 'このメールアドレスのメンバーは、すでに登録されています。', 'errors' => ['email' => ['このメールアドレスのメンバーは、すでに登録されています。']]], 422);
        }

        $by = self::me($request)->actor();
        $operator = Operator::create(['name' => trim($data['name']), 'email' => $email]);
        $this->audit->record('operator.created', self::me($request), ['operator' => $operator->id, 'by' => $by], ip: $request->ip());

        return response()->json($this->present() + ['enrollment_url' => $enrollments->issue($operator, $by)['url']], 201);
    }

    public function enrollment(Request $request, string $operatorId, OperatorEnrollments $enrollments): JsonResponse
    {
        $operator = Operator::query()->findOrFail($operatorId);
        abort_unless($operator->isActive(), 409, '停止中のメンバーには、登録用のリンクを発行できません。');

        return response()->json(['enrollment_url' => $enrollments->issue($operator, self::me($request)->actor())['url']]);
    }

    public function role(Request $request, string $operatorId): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'string', 'in:owner,staff']]);

        return $this->change(fn () => $this->access->setRole(Operator::query()->findOrFail($operatorId), $data['role'], self::me($request)->actor(), self::me($request), $request->ip()));
    }

    public function grant(Request $request, string $operatorId, string $tenantId): JsonResponse
    {
        $data = $request->validate(['level' => ['required', 'string', 'in:none,read,operate,money']]);
        $operator = Operator::query()->findOrFail($operatorId);
        $tenant = Tenant::query()->findOrFail($tenantId);

        return $this->change(fn () => $this->access->grant($operator, (string) $tenant->getTenantKey(), $data['level'] === 'none' ? null : $data['level'], self::me($request)->actor(), self::me($request), $request->ip()));
    }

    public function status(Request $request, string $operatorId): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $me = self::me($request);

        return $this->change(function () use ($operatorId, $data, $me, $request): void {
            DB::connection($me->getConnectionName())->transaction(function () use ($operatorId, $data, $me, $request): void {
                $operator = Operator::query()->whereKey($operatorId)->lockForUpdate()->firstOrFail();

                if ($operator->id === $me->id) {
                    throw new AccessRuleException('自分自身は停止できません（ほかのオーナーに依頼してください）。');
                }

                if (! $data['active']) {
                    $this->access->keepAnOwner($operator);
                }

                $operator->forceFill(['disabled_at' => $data['active'] ? null : CarbonImmutable::now()])->save();

                // 止めたら回復用のコードも消し、ログイン中のセッションも終える（有効に戻した時に、前のコードや画面で入れないように。ADR 0038）
                if (! $data['active']) {
                    OperatorRecoveryCodes::forget($operator, 'disabled');
                    app(OperatorSessions::class)->endAll($operator, 'disabled');
                }
                $this->audit->record($data['active'] ? 'operator.enabled' : 'operator.disabled', $me, ['operator' => $operator->id, 'by' => $me->actor()], ip: $request->ip());
            });
        });
    }

    /**
     * ログインの履歴（成功・失敗・ログアウト。オーナーは全員、スタッフは自分の分。新しい順に 200 件）
     */
    public function logins(Request $request): JsonResponse
    {
        $me = self::me($request);
        $names = Operator::query()->pluck('name', 'id');

        return response()->json(['logins' => AuditEvent::query()
            ->whereIn('type', ['operator.login_succeeded', 'operator.login_failed', 'operator.logout'])
            ->when($me->role !== 'owner', static fn ($q) => $q->where('operator_id', $me->id))
            ->orderByDesc('sequence')->limit(200)->get()
            ->map(static fn (AuditEvent $e): array => [
                'type' => substr($e->type, strlen('operator.')),
                'operator' => $e->operator_id === null ? null : ['id' => $e->operator_id, 'name' => $names->get($e->operator_id)],
                'ip' => $e->ip,
                'occurred_at' => $e->occurred_at->toIso8601String(),
            ])->values()]);
    }

    /**
     * @param  callable(): void  $change
     */
    private function change(callable $change): JsonResponse
    {
        // 決まりに反するとき（AccessRuleException）は、見せてよい文の JSON にまとめて返る（bootstrap/app.php）
        $change();

        return response()->json($this->present());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(): array
    {
        $grants = OperatorGrant::query()->get()->groupBy('operator_id');

        return [
            'members' => Operator::query()->withCount('passkeys')->orderBy('created_at')->get()->map(static fn (Operator $o): array => [
                'id' => $o->id,
                'name' => $o->name,
                'email' => $o->email,
                'role' => $o->role,
                'active' => $o->isActive(),
                'passkeys' => (int) $o->getAttribute('passkeys_count'),
                'last_login_at' => $o->last_login_at?->toIso8601String(),
                'grants' => ($grants->get($o->id) ?? collect())->mapWithKeys(static fn (OperatorGrant $g): array => [$g->tenant_id => $g->level])->all(),
            ])->values(),
            'tenants' => Tenant::query()->orderBy('id')->get()->map(static fn (Model $t): array => ['id' => $t->getKey(), 'name' => $t->getAttribute('name')])->values(),
        ];
    }

    private static function me(Request $request): Operator
    {
        $operator = $request->user('operator');
        assert($operator instanceof Operator);

        return $operator;
    }
}
