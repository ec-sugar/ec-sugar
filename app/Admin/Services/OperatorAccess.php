<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Agent\AgentOAuthGrants;
use App\Admin\Models\AgentToken;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorGrant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 運営の役割と店ごとの権限（ADR 0024）。オーナーは全店・全段階、スタッフは与えた店と段階だけ。
 * 変えたことは監査ログに残す。最後の有効なオーナーは外せない（誰も権限を直せなくなるのを防ぐ）
 */
final class OperatorAccess
{
    /** 段階の並び（上は下を含む） */
    public const array LEVELS = ['read' => 1, 'operate' => 2, 'money' => 3];

    public function __construct(private readonly AuditLog $audit) {}

    public function levelFor(Operator $operator, string $tenantId): ?string
    {
        if ($operator->role === 'owner') {
            return 'money';
        }

        $level = OperatorGrant::query()->where('operator_id', $operator->id)->where('tenant_id', $tenantId)->value('level');

        return is_string($level) ? $level : null;
    }

    public function allows(Operator $operator, string $tenantId, string $required): bool
    {
        $level = $this->levelFor($operator, $tenantId);

        return $level !== null && self::LEVELS[$level] >= self::LEVELS[$required];
    }

    /**
     * スタッフが入れる店（オーナーは null = すべて）
     *
     * @return list<string>|null
     */
    public function tenantIds(Operator $operator): ?array
    {
        return $operator->role === 'owner' ? null : OperatorGrant::query()->where('operator_id', $operator->id)->pluck('tenant_id')->all();
    }

    public function setRole(Operator $operator, string $role, string $by, ?Operator $actor = null, ?string $ip = null): void
    {
        if (! in_array($role, ['owner', 'staff'], true)) {
            throw new AccessRuleException('役割は owner か staff です');
        }

        DB::connection($operator->getConnectionName())->transaction(function () use ($operator, $role, $by, $actor, $ip): void {
            $locked = Operator::query()->whereKey($operator->id)->lockForUpdate()->firstOrFail();

            if ($locked->role === $role) {
                return;
            }

            if ($role === 'staff') {
                $this->keepAnOwner($locked);
            }

            $from = $locked->role;
            $locked->forceFill(['role' => $role])->save();
            $this->audit->record('operator.role_changed', $actor ?? $locked, ['operator' => $locked->id, 'from' => $from, 'to' => $role, 'by' => $by], ip: $ip);

            // オーナーから外した（全部の店のお金の段階から、与えた店の段階へ下がる）: 鍵を全部取り消す
            if ($from === 'owner') {
                $this->revokeAgentTokens($locked, null, 'role_lowered', $actor, $ip);
            }
        });
    }

    /**
     * スタッフの店の権限を置く（null で外す）
     */
    public function grant(Operator $operator, string $tenantId, ?string $level, string $by, ?Operator $actor = null, ?string $ip = null): void
    {
        if ($level !== null && ! array_key_exists($level, self::LEVELS)) {
            throw new AccessRuleException('権限は read・operate・money か、外すなら none です');
        }

        DB::connection($operator->getConnectionName())->transaction(function () use ($operator, $tenantId, $level, $by, $actor, $ip): void {
            // 運営の行 → 店の権限の行の順に押さえる（AI エージェントの許可・交換・入れ替え〔AgentOAuthGrants・AgentOAuthAuthorizations〕と同じ向き。
            // 系統の取り消しで行き違いの錠の待ち合いを作らない。PR 187 の見直しの低 5）
            Operator::query()->whereKey($operator->id)->lockForUpdate()->first();
            $current = OperatorGrant::query()->where('operator_id', $operator->id)->where('tenant_id', $tenantId)->lockForUpdate()->first();

            if ($current?->level === $level) {
                return;
            }

            if ($level === null) {
                $current?->delete();
            } else {
                OperatorGrant::query()->updateOrCreate(['operator_id' => $operator->id, 'tenant_id' => $tenantId], ['level' => $level]);
            }

            $this->audit->record('operator.grant_changed', $actor ?? $operator, ['operator' => $operator->id, 'tenant' => $tenantId, 'from' => $current?->level, 'to' => $level, 'by' => $by], ip: $ip);

            // 外した・下げた: その人のその店の AI エージェントの鍵を取り消す（戻しても前の鍵は生き返らない。ADR 0101 の作って決めたこと）
            if ($current !== null && ($level === null || self::LEVELS[$level] < self::LEVELS[$current->level])) {
                $this->revokeAgentTokens($operator, $tenantId, 'grant_lowered', $actor, $ip);
            }
        });
    }

    /**
     * その運営のその店（null なら全部の店）の使える AI エージェントの鍵を取り消す。権限を外す・下げる・オーナーから外す時に、同じトランザクションの中で呼ぶ
     */
    private function revokeAgentTokens(Operator $operator, ?string $tenantId, string $reason, ?Operator $actor, ?string $ip): void
    {
        $now = CarbonImmutable::now();
        // OAuth の許可の系統も一緒に（リフレッシュトークンで新しい鍵を取らせない。ADR 0101）
        $grants = AgentOAuthGrants::revokeFor($operator->id, $tenantId, $reason);
        $count = AgentToken::query()->where('operator_id', $operator->id)->when($tenantId !== null, static fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('revoked_at')->update(['revoked_at' => $now, 'updated_at' => $now]);

        if ($count > 0 || $grants > 0) {
            $this->audit->record('operator.agent_tokens_revoked', $actor ?? $operator, ['operator' => $operator->id, 'count' => $count, 'grants' => $grants, 'reason' => $reason], tenantId: $tenantId, ip: $ip);
        }
    }

    /**
     * この運営を外したり止めたりしても、有効なオーナーが 1 人は残るか
     */
    public function keepAnOwner(Operator $operator): void
    {
        // ロックはトランザクションの中でしか続かない（外から呼ぶと確かめた直後に外れ、2 人が同時に止めて 0 人になる）
        if (DB::connection($operator->getConnectionName())->transactionLevel() === 0) {
            throw new LogicException('keepAnOwner はトランザクションの中で、止める・役割を変える書き込みと一緒に呼ぶ');
        }

        // 数えるだけでなく行をロックする（2 人のオーナーが同時に互いを外して 0 人になるのを防ぐ。Postgres は数えるのとロックを一緒にできない）
        $others = Operator::query()->where('role', 'owner')->whereNull('disabled_at')->whereKeyNot($operator->id)->lockForUpdate()->pluck('id')->all();
        $others = count($others);

        if ($operator->role === 'owner' && $others === 0) {
            throw new AccessRuleException('このメンバーは最後の有効なオーナーのため、スタッフへの変更や停止はできません（先にほかのメンバーをオーナーにしてください）');
        }
    }
}
