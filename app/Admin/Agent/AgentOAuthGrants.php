<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use App\Admin\Models\AgentOAuthClient;
use App\Admin\Models\AgentOAuthGrant;
use App\Admin\Models\AgentOAuthRefreshToken;
use App\Admin\Models\AgentOAuthRequest;
use App\Admin\Models\AgentToken;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorGrant;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * OAuth の鍵の受け渡し（POST /oauth/token・/oauth/revoke。ADR 0101）と、許可の系統の取り消し。鍵を作る・入れ替える・取り消すのはここだけ。
 *
 * - 受けるのは認可コード ＋ PKCE（S256）と、リフレッシュトークンの入れ替えだけ。クライアントは公開のもの（client_id だけで、秘密は無い）
 * - 認可コードは 1 回だけ・10 分・作ったクライアントの・登録と同じ行き先の・PKCE の合うものだけ。合わない時もコードは使った印を付けて燃やす
 *   （横取りしたコードで何度も試させない）。使ったコードがまた来たら、それで作った系統を取り消す（RFC 6749 の 4.1.2・OAuth 2.1）
 * - アクセストークンは今の AI エージェントの鍵の表（agent_tokens）の行で、期限は 1 時間。鍵の確かめ（AgentTokens::resolve）・段階の引き直し・取り消しは今の鍵と同じ。
 *   1 つの系統で同時に生きるアクセストークンは 2 本まで（入れ替えのたびに、いちばん新しい 1 本を残して前のものの期限を今にする）
 * - リフレッシュトークンは 30 日で、使うたびに入れ替える。使った印のあるものがまた来たら、盗まれたとみて系統ごと取り消す。
 *   ただし入れ替えの直後 GRACE_SECONDS 秒の同じクライアントからの再送（答えを受け取り損ねた送り直し）は、断るだけで取り消さない（記録は残す）
 * - 系統には絶対の期限（書ける 90 日・読むだけ 365 日。管理画面で作る鍵の最長と同じ）。過ぎたら入れ替えを断り、つなぎ直してもらう
 * - 交換と入れ替えの時も、運営が止まっていないか・店の段階が今もあるか（書ける系統は運用以上）を引き直す。運営の行と店の権限の行を押さえてから確かめる
 *   （権限を下げる操作と同時でも、下げる前の段階で鍵を出さない）。だめなら系統を取り消す
 * - 系統は許可の画面で選んだ店（1〜MAX_SHOPS。agent_oauth_grant_shops）を持つ。店は許可した時に決まり、後から増えない（ADR 0104）。
 *   交換・入れ替えの時は、系統の店の全部で段階を引き直す。どれか 1 店でも段階が無くなっていたら系統ごと取り消す（店を黙って減らさない）
 * - 同じ運営 × 同じクライアントの新しい許可は、前の系統を（店にかかわらず）取り消して入れ替える。1 人 × 1 店の、その店を含む使える系統は
 *   MAX_PER_SHOP まで（古いものから取り消す）
 * - 錠の順: 運営の行 → 店の権限の行（店の ID の順）→ 頼みの行・系統の行 → リフレッシュトークンの行（権限を変える OperatorAccess も運営の行から押さえる。
 *   行き違いの錠の待ち合いを作らない）
 */
final class AgentOAuthGrants
{
    public const int MAX_PER_SHOP = 5;

    /** 1 つの系統で選べる店の数（ADR 0104） */
    public const int MAX_SHOPS = 50;

    /** 系統の絶対の期限（書ける） */
    public const int WRITE_GRANT_DAYS = 90;

    /** 系統の絶対の期限（読むだけ） */
    public const int READ_GRANT_DAYS = 365;

    /** 入れ替えの直後に、同じリフレッシュトークンの再送を取り消しにしない秒数 */
    public const int GRACE_SECONDS = 10;

    /** 1 つの系統で同時に生きるアクセストークンの数 */
    public const int LIVE_ACCESS_TOKENS = 2;

    public function __construct(
        private readonly OperatorAccess $access,
        private readonly AuditLog $audit,
    ) {}

    /**
     * 認可コードを鍵に替える（grant_type=authorization_code）
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> 鍵の答え（RFC 6749 の 5.1）
     */
    public function exchangeCode(array $input, ?string $ip = null): array
    {
        $client = self::client($input['client_id'] ?? null);
        $code = $input['code'] ?? null;
        $verifier = $input['code_verifier'] ?? null;
        $redirect = $input['redirect_uri'] ?? null;

        if (! AgentOAuth::acceptsResource($input['resource'] ?? null)) {
            throw new OAuthError('invalid_target', 'resource must be the URL of this MCP endpoint.');
        }

        if (! is_string($code) || preg_match(AgentOAuth::HANDLE_SHAPE, $code) !== 1) {
            throw new OAuthError('invalid_grant', 'The authorization code is invalid or expired.');
        }

        if (! is_string($verifier) || preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/', $verifier) !== 1) {
            throw new OAuthError('invalid_request', 'code_verifier (43 to 128 characters) is required.');
        }

        $hash = hash('sha256', $code);
        // 錠の前に、誰がどの店で許可したかを読む（許可した後は変わらない値）。錠は運営 → 店の権限 → 頼みの順
        $approved = AgentOAuthRequest::query()->where('code_hash', $hash)->first(['operator_id', 'tenant_ids']);

        $result = $this->central()->transaction(function () use ($client, $hash, $approved, $verifier, $redirect, $ip): array|OAuthError {
            if ($approved instanceof AgentOAuthRequest && is_string($approved->operator_id) && is_array($approved->tenant_ids)) {
                $this->lockAccess($approved->operator_id, self::cleanShops($approved->tenant_ids));
            }

            $request = AgentOAuthRequest::query()->where('code_hash', $hash)->lockForUpdate()->first();
            $now = CarbonImmutable::now();

            if (! $request instanceof AgentOAuthRequest || $request->approved !== true) {
                return new OAuthError('invalid_grant', 'The authorization code is invalid or expired.');
            }

            // 使ったコードがまた来た: 横取りされたかもしれないので、それで作った系統を取り消す
            if ($request->used_at !== null) {
                $grant = $request->grant_id === null ? null : AgentOAuthGrant::query()->whereKey($request->grant_id)->lockForUpdate()->first();

                if ($grant instanceof AgentOAuthGrant) {
                    $this->revokeLocked($grant, 'code_reused');
                }
                $shops = self::cleanShops($request->tenant_ids ?? []);
                $this->audit->record('operator.agent_oauth_reuse_detected', null, ['kind' => 'code', 'grant' => $request->grant_id, 'client' => $client->id, 'shops' => $shops], tenantId: self::auditTenant($shops), ip: $ip);

                return new OAuthError('invalid_grant', 'The authorization code is invalid or expired.');
            }

            // ここから先で断る時も、コードは燃やす（1 回だけ）
            $request->forceFill(['used_at' => $now])->save();

            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

            if ($request->code_expires_at === null || $request->code_expires_at->lte($now)
                || ! hash_equals($request->client_id, $client->id)
                || ! is_string($redirect) || ! hash_equals($request->redirect_uri, $redirect)
                || ! hash_equals($request->code_challenge, $challenge)) {
                return new OAuthError('invalid_grant', 'The authorization code is invalid or expired.');
            }

            $operator = Operator::query()->find($request->operator_id);
            $shops = self::cleanShops($request->tenant_ids ?? []);

            if (! $operator instanceof Operator || $shops === [] || ! is_string($request->scope) || ! $this->stillAllowed($operator, $shops, $request->scope)) {
                return new OAuthError('invalid_grant', 'The approving operator no longer has access. Connect again.');
            }

            $grant = $this->openGrant($client, $operator, $shops, $request->scope, $request->redirect_uri);
            $request->forceFill(['grant_id' => $grant->id])->save();
            AgentOAuthClient::query()->whereKey($client->id)->update(['last_used_at' => $now]);
            $tokens = $this->issuePair($grant, $grant->scope);
            $this->audit->record('operator.agent_oauth_token_issued', $operator, ['grant' => $grant->id, 'client' => $client->id, 'scope' => $grant->scope, 'shops' => $shops], tenantId: self::auditTenant($shops), ip: $ip);

            return $tokens;
        });

        if ($result instanceof OAuthError) {
            throw $result;
        }

        return $result;
    }

    /**
     * リフレッシュトークンを入れ替えて、新しい鍵の組を出す（grant_type=refresh_token）
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function refresh(array $input, ?string $ip = null): array
    {
        $client = self::client($input['client_id'] ?? null);
        $plain = $input['refresh_token'] ?? null;

        if (! AgentOAuth::acceptsResource($input['resource'] ?? null)) {
            throw new OAuthError('invalid_target', 'resource must be the URL of this MCP endpoint.');
        }

        if (! is_string($plain) || preg_match(AgentOAuth::REFRESH_SHAPE, $plain) !== 1) {
            throw new OAuthError('invalid_grant', 'The refresh token is invalid or expired.');
        }

        $hash = hash('sha256', $plain);
        $grantId = AgentOAuthRefreshToken::query()->where('token_hash', $hash)->value('grant_id');
        $owner = is_string($grantId) ? AgentOAuthGrant::query()->find($grantId, ['id', 'operator_id']) : null;

        if (! $owner instanceof AgentOAuthGrant) {
            throw new OAuthError('invalid_grant', 'The refresh token is invalid or expired.');
        }

        $result = $this->central()->transaction(function () use ($client, $hash, $grantId, $owner, $input, $ip): array|OAuthError {
            // 運営 → 店の権限 → 系統 → リフレッシュトークンの順にロックする（同時の入れ替えを 1 つずつに・権限を下げる操作と行き違わない）
            $shops = self::shopsOf($owner->id);
            $this->lockAccess($owner->operator_id, $shops);
            $grant = AgentOAuthGrant::query()->whereKey($grantId)->lockForUpdate()->first();
            $tenant = self::auditTenant($shops);
            $token = AgentOAuthRefreshToken::query()->where('token_hash', $hash)->lockForUpdate()->first();
            $now = CarbonImmutable::now();
            $refused = new OAuthError('invalid_grant', 'The refresh token is invalid or expired.');

            if (! $grant instanceof AgentOAuthGrant || ! $token instanceof AgentOAuthRefreshToken || $grant->revoked_at !== null) {
                return $refused;
            }

            $sameClient = hash_equals($grant->client_id, $client->id);

            // 入れ替えの直後の同じクライアントからの再送（答えを受け取り損ねた送り直し）は、断るだけで系統は取り消さない
            if ($token->used_at !== null && $sameClient && $token->used_at->gt($now->subSeconds(self::GRACE_SECONDS))) {
                $this->audit->record('operator.agent_oauth_refresh_retried', null, ['grant' => $grant->id, 'client' => $client->id], tenantId: $tenant, ip: $ip);

                return $refused;
            }

            // 使ったトークンがまた来た・ほかのクライアントが出した: 盗まれたとみて系統ごと取り消す
            if ($token->used_at !== null || ! $sameClient) {
                $this->revokeLocked($grant, $token->used_at !== null ? 'refresh_reused' : 'refresh_wrong_client');
                $this->audit->record('operator.agent_oauth_reuse_detected', null, ['kind' => 'refresh', 'grant' => $grant->id, 'client' => $client->id], tenantId: $tenant, ip: $ip);

                return $refused;
            }

            // リフレッシュトークンの期限と、系統の絶対の期限
            if ($token->expires_at->lte($now) || $grant->expires_at->lte($now)) {
                return $refused;
            }

            // 系統のアクセストークンがどれか取り消されていれば、系統も取り消されたものとして扱う（ほかの道の取り消しの漏れの備え）
            if (AgentToken::query()->where('oauth_grant_id', $grant->id)->whereNotNull('revoked_at')->exists()) {
                $this->revokeLocked($grant, 'token_revoked');

                return $refused;
            }

            // 読むだけの系統に、書けるは頼めない。書ける系統で読むだけを頼まれたら、読むだけの鍵を出す（狭める頼みは狭く。系統は書けるのまま）
            $asked = $input['scope'] ?? null;
            $asked = is_string($asked) && trim($asked) !== '' ? AgentOAuth::requestedScope($asked) : null;

            if ($asked === AgentTokens::WRITE && $grant->scope !== AgentTokens::WRITE) {
                return new OAuthError('invalid_scope', 'This grant is read-only. Connect again and approve write access.');
            }

            $operator = Operator::query()->find($grant->operator_id);

            if (! $operator instanceof Operator || $shops === [] || ! $this->stillAllowed($operator, $shops, $grant->scope)) {
                $this->revokeLocked($grant, 'access_lost');

                return $refused;
            }

            $token->forceFill(['used_at' => $now])->save();
            $grant->forceFill(['last_refreshed_at' => $now])->save();
            AgentOAuthClient::query()->whereKey($client->id)->update(['last_used_at' => $now]);
            $tokens = $this->issuePair($grant, $asked ?? $grant->scope);
            $this->audit->record('operator.agent_oauth_refreshed', $operator, ['grant' => $grant->id, 'client' => $client->id], tenantId: $tenant, ip: $ip);

            return $tokens;
        });

        if ($result instanceof OAuthError) {
            throw $result;
        }

        return $result;
    }

    /**
     * 鍵の取り消し（RFC 7009）。リフレッシュトークンでもアクセストークンでも、そのクライアントの系統ごと取り消す。
     * 知らない・ほかのクライアントの鍵は何もしない（答えは同じ。鍵があるかを見せない）
     *
     * @param  array<string, mixed>  $input
     */
    public function revokeByToken(array $input, ?string $ip = null): void
    {
        $client = self::client($input['client_id'] ?? null);
        $plain = $input['token'] ?? null;

        if (! is_string($plain)) {
            throw new OAuthError('invalid_request', 'token is required.');
        }

        $grantId = null;

        if (preg_match(AgentOAuth::REFRESH_SHAPE, $plain) === 1) {
            $grantId = AgentOAuthRefreshToken::query()->where('token_hash', hash('sha256', $plain))->value('grant_id');
        } elseif (str_starts_with($plain, AgentTokens::PREFIX)) {
            $grantId = AgentToken::query()->where('token_hash', hash('sha256', $plain))->value('oauth_grant_id');
        }

        if (! is_string($grantId)) {
            return;
        }

        $this->central()->transaction(function () use ($grantId, $client, $ip): void {
            $grant = AgentOAuthGrant::query()->whereKey($grantId)->lockForUpdate()->first();

            if ($grant instanceof AgentOAuthGrant && $grant->revoked_at === null && hash_equals($grant->client_id, $client->id)) {
                $this->revokeLocked($grant, 'client_revoked');
                $this->audit->record('operator.agent_oauth_revoked', null, ['grant' => $grant->id, 'reason' => 'client_revoked'], tenantId: self::auditTenant(self::shopsOf($grant->id)), ip: $ip);
            }
        });
    }

    /**
     * 管理画面からの取り消し（本人かオーナー。AgentTokenController が見せてよい人か確かめてから呼ぶ）
     */
    public function revoke(AgentOAuthGrant $grant, Operator $by, ?string $ip = null): void
    {
        $done = $this->central()->transaction(function () use ($grant): bool {
            $locked = AgentOAuthGrant::query()->whereKey($grant->id)->lockForUpdate()->first();

            if (! $locked instanceof AgentOAuthGrant || $locked->revoked_at !== null) {
                return false;
            }

            $this->revokeLocked($locked, 'operator_revoked');

            return true;
        });

        if ($done) {
            $this->audit->record('operator.agent_oauth_revoked', $by, ['grant' => $grant->id, 'owner' => $grant->operator_id, 'reason' => 'operator_revoked'], tenantId: self::auditTenant(self::shopsOf($grant->id)), ip: $ip);
        }
    }

    /**
     * その運営の（店を渡せばその店を含む）使える系統を全部取り消す（複数の店の系統も、ほかの店の分ごと。ADR 0104）。鍵の表の取り消し（全部ログアウト・パスキーの削除・回復・止める・権限を下げる）と
     * 同じ時に呼ぶ（AgentTokens::revokeAllFor・OperatorAccess）。許可した後でまだ鍵に替えていない認可コードも使えなくする（PR 186 の見直しの低 5）。
     * 取り消した系統の数を返す
     */
    public static function revokeFor(string $operatorId, ?string $tenantId, string $reason): int
    {
        $now = CarbonImmutable::now();
        AgentOAuthRequest::query()->where('operator_id', $operatorId)->when($tenantId !== null, static fn ($q) => $q->whereRaw('tenant_ids @> ?::jsonb', [json_encode([$tenantId])]))
            ->where('approved', true)->whereNull('used_at')->where('code_expires_at', '>', $now)->update(['code_expires_at' => $now, 'updated_at' => $now]);

        $ids = AgentOAuthGrant::query()->where('operator_id', $operatorId)
            ->when($tenantId !== null, static fn ($q) => $q->whereIn('id', static fn ($sub) => $sub->select('grant_id')->from('agent_oauth_grant_shops')->where('tenant_id', $tenantId)))
            ->whereNull('revoked_at')->pluck('id')->all();

        if ($ids === []) {
            return 0;
        }

        $count = AgentOAuthGrant::query()->whereIn('id', $ids)->whereNull('revoked_at')->update(['revoked_at' => $now, 'revoked_reason' => mb_substr($reason, 0, 40), 'updated_at' => $now]);
        AgentToken::query()->whereIn('oauth_grant_id', $ids)->whereNull('revoked_at')->update(['revoked_at' => $now, 'updated_at' => $now]);

        return $count;
    }

    /**
     * 見られる系統（自分の分。オーナーは全員の分）。新しい順
     *
     * @return list<AgentOAuthGrant>
     */
    public function visibleTo(Operator $operator): array
    {
        $query = AgentOAuthGrant::query()->with('operator')->orderByDesc('created_at');

        if ($operator->role !== 'owner') {
            $query->where('operator_id', $operator->id);
        }

        return $query->limit(200)->get()->all();
    }

    /**
     * 系統が今も使えるか（取り消していない・絶対の期限の前・入れ替えのできるリフレッシュトークンがある）
     */
    public static function usable(AgentOAuthGrant $grant): bool
    {
        return $grant->revoked_at === null && $grant->expires_at->isFuture()
            && AgentOAuthRefreshToken::query()->where('grant_id', $grant->id)->whereNull('used_at')->where('expires_at', '>', CarbonImmutable::now())->exists();
    }

    /**
     * 系統の店（ID の順）
     *
     * @return list<string>
     */
    public static function shopsOf(string $grantId): array
    {
        return array_values(array_map('strval', self::shopTable()->where('grant_id', $grantId)->orderBy('tenant_id')->pluck('tenant_id')->all()));
    }

    /**
     * いくつかの系統の店（系統の ID → 店の ID の順）
     *
     * @param  list<string>  $grantIds
     * @return array<string, list<string>>
     */
    public static function shopsByGrant(array $grantIds): array
    {
        $out = [];

        if ($grantIds === []) {
            return $out;
        }

        foreach (self::shopTable()->whereIn('grant_id', $grantIds)->orderBy('tenant_id')->get(['grant_id', 'tenant_id']) as $row) {
            $out[(string) $row->grant_id][] = (string) $row->tenant_id;
        }

        return $out;
    }

    /**
     * 監査ログの店の欄（1 店ならその店。複数なら空にして、店はデータの shops に）
     *
     * @param  list<string>  $shops
     */
    public static function auditTenant(array $shops): ?string
    {
        return count($shops) === 1 ? $shops[0] : null;
    }

    /**
     * 店の ID の並びを整える（文字だけ・重なりを除く・順に並べる）
     *
     * @param  array<mixed>  $shops
     * @return list<string>
     */
    public static function cleanShops(array $shops): array
    {
        $ids = array_values(array_unique(array_filter($shops, static fn (mixed $id): bool => is_string($id) && $id !== '')));
        sort($ids, SORT_STRING);

        return $ids;
    }

    private static function shopTable(): Builder
    {
        return DB::connection((string) config('tenancy.database.central_connection'))->table('agent_oauth_grant_shops');
    }

    private static function client(mixed $clientId): AgentOAuthClient
    {
        $client = is_string($clientId) && preg_match('/\A[0-9a-z]{26}\z/', $clientId) === 1 ? AgentOAuthClient::query()->find($clientId) : null;

        if (! $client instanceof AgentOAuthClient) {
            throw new OAuthError('invalid_client', 'Unknown client. Register again.', 401);
        }

        return $client;
    }

    /**
     * 運営の行と、系統の店の権限の行を押さえる（運営は FOR UPDATE、権限は FOR SHARE。権限を下げる・外す・オーナーから外す操作と行き違わない）。
     * 店の権限の行は店の ID の順に押さえる（同時の 2 つの交換が、行き違いの順で待ち合わない）。トランザクションの中で、頼み・系統の行より先に呼ぶ
     *
     * @param  list<string>  $tenantIds
     */
    private function lockAccess(string $operatorId, array $tenantIds): void
    {
        Operator::query()->whereKey($operatorId)->lockForUpdate()->first();

        if ($tenantIds !== []) {
            OperatorGrant::query()->where('operator_id', $operatorId)->whereIn('tenant_id', $tenantIds)->orderBy('tenant_id')->sharedLock()->get();
        }
    }

    /**
     * 運営が止まっていない・店が全部ある・どの店でも段階が今もある（書ける系統は、どの店でも運用以上）。lockAccess の後に呼ぶ
     *
     * @param  list<string>  $tenantIds
     */
    private function stillAllowed(Operator $operator, array $tenantIds, string $scope): bool
    {
        $operator = $operator->fresh();

        if (! $operator instanceof Operator || ! $operator->isActive() || $tenantIds === [] || count($tenantIds) > self::MAX_SHOPS
            || Tenant::query()->whereIn('id', $tenantIds)->count() !== count($tenantIds)) {
            return false;
        }

        foreach ($tenantIds as $tenantId) {
            $level = $this->access->levelFor($operator, $tenantId);

            if ($level === null || ($scope === AgentTokens::WRITE && OperatorAccess::LEVELS[$level] < OperatorAccess::LEVELS['operate'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 新しい系統を開く。同じ運営 × クライアントの前の系統は（店にかかわらず）取り消し、選んだ店のどれかで、その店を含む使える系統が
     * MAX_PER_SHOP を越えたら古いものから取り消す。運営の行は lockAccess で押さえてある（同時の許可で上限を越えない）
     *
     * @param  list<string>  $tenantIds
     */
    private function openGrant(AgentOAuthClient $client, Operator $operator, array $tenantIds, string $scope, string $redirectUri): AgentOAuthGrant
    {
        $live = AgentOAuthGrant::query()->where('operator_id', $operator->id)->whereNull('revoked_at')
            ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
        $revoked = [];

        foreach ($live as $old) {
            if ($old->client_id === $client->id) {
                $this->revokeLocked($old, 'replaced');
                $revoked[$old->id] = true;
            }
        }

        $shopsOf = self::shopsByGrant($live->pluck('id')->all());

        foreach ($tenantIds as $tenantId) {
            $holding = $live->filter(static fn (AgentOAuthGrant $old): bool => ! isset($revoked[$old->id]) && in_array($tenantId, $shopsOf[$old->id] ?? [], true))->values();

            foreach ($holding->take(max(0, $holding->count() - (self::MAX_PER_SHOP - 1))) as $old) {
                $this->revokeLocked($old, 'too_many');
                $revoked[$old->id] = true;
            }
        }

        $grant = AgentOAuthGrant::create([
            'client_id' => $client->id,
            'operator_id' => $operator->id,
            'scope' => $scope,
            'client_name' => AgentOAuth::displayName($client->name, 40, '名前の無いアプリ'),
            'redirect_host' => mb_substr((string) parse_url($redirectUri, PHP_URL_HOST), 0, 255),
            'expires_at' => CarbonImmutable::now()->addDays($scope === AgentTokens::WRITE ? self::WRITE_GRANT_DAYS : self::READ_GRANT_DAYS),
        ]);
        self::shopTable()->insert(array_map(static fn (string $tenantId): array => ['grant_id' => $grant->id, 'tenant_id' => $tenantId], $tenantIds));

        return $grant;
    }

    /**
     * 系統のアクセストークンとリフレッシュトークンを 1 組出す。どちらも系統の絶対の期限を越えない。
     * 同時に生きるアクセストークンを LIVE_ACCESS_TOKENS 本にそろえる（前のものは取り消しでなく期限を今にする。系統の取り消しの確かめに引っかけない）
     *
     * @return array<string, mixed>
     */
    private function issuePair(AgentOAuthGrant $grant, string $scope): array
    {
        $now = CarbonImmutable::now();
        $access = AgentTokens::PREFIX.AgentOAuth::random();
        $refresh = AgentOAuth::REFRESH_PREFIX.AgentOAuth::random();
        $accessUntil = $now->addMinutes(AgentOAuth::ACCESS_MINUTES)->min($grant->expires_at);

        $older = AgentToken::query()->where('oauth_grant_id', $grant->id)->whereNull('revoked_at')->where('expires_at', '>', $now)
            ->orderByDesc('created_at')->orderByDesc('id')->offset(self::LIVE_ACCESS_TOKENS - 1)->limit(100)->get(['id', 'created_at']);

        foreach ($older as $old) {
            // 作った時刻と同じ秒には期限を置けない（CHECK）ので、その時は 1 秒後
            AgentToken::query()->whereKey($old->id)->update(['expires_at' => $now->max($old->created_at->addSecond()), 'updated_at' => $now]);
        }

        AgentToken::create([
            'operator_id' => $grant->operator_id,
            // 店を持たない（どの店かは呼び出しごとに、系統の店の中から選ぶ。ADR 0104）
            'tenant_id' => null,
            'name' => $grant->client_name,
            'token_hash' => hash('sha256', $access),
            'prefix' => substr($access, 0, strlen(AgentTokens::PREFIX) + 8),
            'scope' => $scope,
            'expires_at' => $accessUntil,
            'oauth_grant_id' => $grant->id,
            'oauth_client_id' => $grant->client_id,
        ]);
        AgentOAuthRefreshToken::create([
            'grant_id' => $grant->id,
            'token_hash' => hash('sha256', $refresh),
            'expires_at' => $now->addDays(AgentOAuth::REFRESH_DAYS)->min($grant->expires_at),
        ]);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => max(1, (int) $now->diffInSeconds($accessUntil)),
            'refresh_token' => $refresh,
            'scope' => AgentOAuth::scopeString($scope),
        ];
    }

    /**
     * ロックした系統を取り消す（系統のアクセストークンも全部）。呼ぶ側がトランザクションの中で行をロックしてから
     */
    private function revokeLocked(AgentOAuthGrant $grant, string $reason): void
    {
        $now = CarbonImmutable::now();
        $grant->forceFill(['revoked_at' => $now, 'revoked_reason' => $reason])->save();
        AgentToken::query()->where('oauth_grant_id', $grant->id)->whereNull('revoked_at')->update(['revoked_at' => $now, 'updated_at' => $now]);
    }

    private function central(): Connection
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }
}
