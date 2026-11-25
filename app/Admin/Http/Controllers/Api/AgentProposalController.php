<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Agent\AgentProposals;
use App\Admin\Agent\Models\AgentProposal;
use App\Admin\Agent\ShopWriter;
use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\Middleware\RequireRecentConfirmation;
use App\Admin\Models\AgentToken;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * AI エージェントの案（承認の一覧。ADR 0101。EnterTenant の後）。一覧・1 件・承認・却下。どれも運用の段階から（EnterTenant の既定）。
 *
 * - 承認は、その案に要る段階を持つ人だけ（お金の操作〔取消・返金〕はお金の段階で、管理画面の取消・返金と同じく直前の再確認も）
 * - 押した時に引数と改訂を確かめ直してから反映する（AgentProposals）。反映できなかった案は failed と理由の文で返す（200）
 * - 案の ID はモデルに結びつけず、店の文脈の中で探す（ほかの店の案は 404）
 */
final class AgentProposalController
{
    private const int PER_PAGE = 50;

    private const array STATUSES = [AgentProposal::PENDING, AgentProposal::APPROVED, AgentProposal::REJECTED, AgentProposal::EXPIRED, AgentProposal::FAILED];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(self::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $now = CarbonImmutable::now();
        $query = AgentProposal::query();

        // 待ちは期限の前のものだけ。期限の過ぎた待ちは期限切れに数える（定期の処理が書き換える前でも）
        match ($data['status'] ?? null) {
            AgentProposal::PENDING => $query->where('status', AgentProposal::PENDING)->where('expires_at', '>', $now),
            AgentProposal::EXPIRED => $query->where(static fn ($q) => $q->where('status', AgentProposal::EXPIRED)->orWhere(static fn ($w) => $w->where('status', AgentProposal::PENDING)->where('expires_at', '<=', $now))),
            null => $query,
            default => $query->where('status', $data['status']),
        };

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE);
        /** @var list<AgentProposal> $items */
        $items = $page->items();

        return response()->json([
            'proposals' => self::present($items, false),
            'counts' => ['pending' => AgentProposals::pendingCount(), 'all' => AgentProposal::query()->count()],
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $proposalId): JsonResponse
    {
        $proposal = self::find($proposalId);

        return response()->json(['proposal' => self::present([$proposal], true)[0] + [
            'can_approve' => self::canApprove($request, $proposal),
            // 作った鍵が取り消された・作った人の権限が足りなくなった（承認すると理由つきで却下になる）
            'creator_blocked' => $proposal->status === AgentProposal::PENDING && ! AgentProposals::creatorStillAllowed($proposal),
            'required_level' => AgentProposals::requiredLevel($proposal),
        ]]);
    }

    public function approve(Request $request, string $proposalId, AgentProposals $proposals, ShopWriter $writer): JsonResponse
    {
        $proposal = self::find($proposalId);

        // お金の操作の承認は、管理画面の取消・返金と同じく直前の再確認（段階は AgentProposals がロックの中で確かめる）
        if (AgentProposals::requiredLevel($proposal) === 'money') {
            RequireRecentConfirmation::require($request);
        }

        $decided = $proposals->approve($proposal->id, self::operator(), self::level($request), $writer);

        return response()->json(['proposal' => self::present([$decided], true)[0]]);
    }

    public function reject(Request $request, string $proposalId, AgentProposals $proposals): JsonResponse
    {
        $decided = $proposals->reject(self::find($proposalId)->id, self::operator(), self::level($request));

        return response()->json(['proposal' => self::present([$decided], true)[0]]);
    }

    /**
     * @param  list<AgentProposal>  $proposals
     * @return list<array<string, mixed>>
     */
    private static function present(array $proposals, bool $detail): array
    {
        $operatorIds = [];

        foreach ($proposals as $p) {
            $operatorIds[] = $p->operator_id;

            if ($p->decided_by !== null) {
                $operatorIds[] = $p->decided_by;
            }
        }

        // 名前は中央の運営と鍵から（見せるためだけ。店の中の判断には使わない）
        $names = Operator::query()->whereIn('id', array_values(array_unique($operatorIds)))->pluck('name', 'id');
        $tokens = AgentToken::query()->whereIn('id', array_values(array_unique(array_map(static fn (AgentProposal $p): string => $p->token_id, $proposals))))->get(['id', 'name', 'prefix', 'revoked_at'])->keyBy('id');
        $now = CarbonImmutable::now();

        return array_map(static function (AgentProposal $p) use ($names, $tokens, $now, $detail): array {
            $token = $tokens->get($p->token_id);
            $status = $p->status === AgentProposal::PENDING && $p->expires_at->lte($now) ? AgentProposal::EXPIRED : $p->status;

            return [
                'id' => $p->id,
                'tool' => $p->tool,
                'level' => $p->level,
                'summary' => $p->summary,
                'status' => $status,
                'status_label' => AgentProposals::label($status),
                'created_at' => $p->created_at->toIso8601String(),
                'expires_at' => $p->expires_at->toIso8601String(),
                'decided_at' => $p->decided_at?->toIso8601String(),
                'operator' => ['id' => $p->operator_id, 'name' => $names[$p->operator_id] ?? null],
                // 取り消した鍵の案は承認できない（承認の時にも確かめて却下にする。見直しの中 1）
                'token' => $token instanceof AgentToken ? ['name' => $token->name, 'prefix' => $token->prefix, 'revoked' => $token->revoked_at !== null] : null,
                // 承認して反映した案の様子: 反映の途中（結果がまだ無い）・確かめ中（Stripe の答えが分からず、見回りが続きを進める）
                'progress' => $p->status !== AgentProposal::APPROVED ? null : ($p->result === null ? 'applying' : (($p->result['checking'] ?? false) === true ? 'checking' : null)),
                'decided_by' => $p->decided_by === null ? null : ['id' => $p->decided_by, 'name' => $names[$p->decided_by] ?? null],
                'error' => is_string($p->result['error'] ?? null) ? $p->result['error'] : null,
            ] + ($detail ? ['arguments' => $p->arguments, 'result' => $p->result] : []);
        }, $proposals);
    }

    private static function canApprove(Request $request, AgentProposal $proposal): bool
    {
        $level = self::level($request);

        return $proposal->status === AgentProposal::PENDING && $proposal->expires_at->isFuture()
            && OperatorAccess::LEVELS[$level] >= OperatorAccess::LEVELS[AgentProposals::requiredLevel($proposal)]
            && AgentProposals::creatorStillAllowed($proposal);
    }

    private static function find(string $proposalId): AgentProposal
    {
        return AgentProposal::query()->findOrFail(strtolower($proposalId));
    }

    private static function level(Request $request): string
    {
        $level = $request->attributes->get(EnterTenant::LEVEL);

        return is_string($level) ? $level : 'read';
    }

    private static function operator(): Operator
    {
        $operator = Auth::guard('operator')->user();
        abort_unless($operator instanceof Operator, 401);

        return $operator;
    }
}
