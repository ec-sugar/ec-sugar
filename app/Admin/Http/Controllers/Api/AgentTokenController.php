<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Agent\AgentOAuthGrants;
use App\Admin\Agent\AgentTokens;
use App\Admin\Models\AgentOAuthGrant;
use App\Admin\Models\AgentToken;
use App\Admin\Models\Operator;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * AI エージェントの鍵（ADR 0100）。中央の表だけ（店の DB に入らない）。
 *
 * - GET /api/agent-tokens: 見られる鍵（自分の分。オーナーは全員の分）。鍵そのものは返さない
 * - POST /api/agent-tokens: 自分の分を発行（直前の再確認の後）。{ tenant, name, days, scope（read・write。ADR 0101） } → 鍵は一度だけ返す
 * - DELETE /api/agent-tokens/{tokenId}: 取り消し（本人かオーナー）。ほかの人の鍵はスタッフには 404
 * - 一覧には OAuth の許可の系統（grants。ADR 0101）も出す。DELETE /api/agent-grants/{grantId} で系統ごと取り消す（本人かオーナー）
 */
final class AgentTokenController extends Controller
{
    public function __construct(
        private readonly AgentTokens $tokens,
        private readonly AgentOAuthGrants $grants,
    ) {}

    public function index(): JsonResponse
    {
        $operator = self::operator();
        $names = Tenant::query()->pluck('name', 'id');

        return response()->json([
            'tokens' => array_map(static fn (AgentToken $token): array => self::present($token, $names[$token->tenant_id] ?? null, $operator), $this->tokens->visibleTo($operator)),
            'grants' => self::presentGrants($this->grants->visibleTo($operator), $names->all(), $operator),
        ]);
    }

    /**
     * OAuth の許可の系統を取り消す（本人かオーナー。ADR 0101）。系統のアクセストークンとリフレッシュトークンが全部効かなくなる
     */
    public function destroyGrant(Request $request, string $grantId): Response
    {
        $operator = self::operator();
        $grant = AgentOAuthGrant::query()->find($grantId);

        if (! $grant instanceof AgentOAuthGrant || ($grant->operator_id !== $operator->id && $operator->role !== 'owner')) {
            abort(404);
        }

        $this->grants->revoke($grant, $operator, $request->ip());

        return response()->noContent();
    }

    public function store(Request $request): JsonResponse
    {
        $operator = self::operator();
        $tenant = $request->input('tenant');
        $issued = $this->tokens->issue($operator, is_string($tenant) ? $tenant : '', $request->input('name'), $request->input('days'), ip: $request->ip(), scope: $request->input('scope'));
        $name = Tenant::query()->whereKey($issued['token']->tenant_id)->value('name');

        return response()->json(['token' => self::present($issued['token'], is_string($name) ? $name : null, $operator), 'secret' => $issued['plain']], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $tokenId): Response
    {
        $operator = self::operator();
        $token = AgentToken::query()->find($tokenId);

        if (! $token instanceof AgentToken || ($token->operator_id !== $operator->id && $operator->role !== 'owner')) {
            abort(404);
        }

        $this->tokens->revoke($operator, $token, $request->ip());

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(AgentToken $token, ?string $tenantName, Operator $viewer): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'prefix' => $token->prefix,
            'scope' => $token->scope,
            'tenant' => ['id' => $token->tenant_id, 'name' => $tenantName],
            'mine' => $token->operator_id === $viewer->id,
            'operator' => $token->operator_id === $viewer->id ? null : ['id' => $token->operator_id, 'name' => $token->operator->name],
            'created_at' => $token->created_at->toIso8601String(),
            'expires_at' => $token->expires_at->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'usable' => $token->usable(),
        ];
    }

    /**
     * @param  list<AgentOAuthGrant>  $grants
     * @param  array<mixed>  $names  店の ID → 名前
     * @return list<array<string, mixed>>
     */
    private static function presentGrants(array $grants, array $names, Operator $viewer): array
    {
        $shops = AgentOAuthGrants::shopsByGrant(array_map(static fn (AgentOAuthGrant $grant): string => $grant->id, $grants));

        return array_map(static fn (AgentOAuthGrant $grant): array => self::presentGrant(
            $grant,
            array_map(static fn (string $id): array => ['id' => $id, 'name' => is_string($names[$id] ?? null) ? $names[$id] : null], $shops[$grant->id] ?? []),
            $viewer,
        ), $grants);
    }

    /**
     * OAuth の許可の系統（名前は名乗っただけなので、行き先のホストと並べて出す。店は許可で選んだ全部。ADR 0104）
     *
     * @param  list<array{id: string, name: string|null}>  $shops
     * @return array<string, mixed>
     */
    private static function presentGrant(AgentOAuthGrant $grant, array $shops, Operator $viewer): array
    {
        $lastUsed = AgentToken::query()->where('oauth_grant_id', $grant->id)->max('last_used_at');

        return [
            'id' => $grant->id,
            'client_name' => $grant->client_name,
            'client_id' => $grant->client_id,
            'host' => $grant->redirect_host,
            'scope' => $grant->scope,
            'shops' => $shops,
            'mine' => $grant->operator_id === $viewer->id,
            'operator' => $grant->operator_id === $viewer->id ? null : ['id' => $grant->operator_id, 'name' => $grant->operator->name],
            'created_at' => $grant->created_at->toIso8601String(),
            'last_used_at' => is_string($lastUsed) ? CarbonImmutable::parse($lastUsed)->toIso8601String() : null,
            'expires_at' => $grant->expires_at->toIso8601String(),
            'revoked_at' => $grant->revoked_at?->toIso8601String(),
            'usable' => AgentOAuthGrants::usable($grant),
        ];
    }

    private static function operator(): Operator
    {
        $operator = Auth::guard('operator')->user();
        abort_unless($operator instanceof Operator, 401);

        return $operator;
    }
}
