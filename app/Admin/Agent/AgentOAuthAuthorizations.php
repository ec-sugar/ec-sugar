<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use App\Admin\Models\AgentOAuthClient;
use App\Admin\Models\AgentOAuthRequest;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorGrant;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\OperatorSecurityNotices;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * OAuth の許可の頼み（GET /oauth/authorize）と許可の画面（ADR 0101）。頼みを受ける・見せる・許可する・拒否するのはここだけ。
 *
 * - 頼みはセッションに置かず、中央の表に 10 分だけ置く（他サイトから来た移動には SameSite=Strict のセッションの Cookie が付かないため）。
 *   許可の画面の URL に頼みの合い言葉（乱数 32 バイト）を入れ、表にはハッシュだけを持つ
 * - client_id が無い・行き先が登録と完全に一致しない時は、行き先へ送らない（送り先そのものが信じられない。RFC 6749 の 4.1.2.1）。
 *   ほかの誤り（response_type・PKCE・resource）は、登録した行き先へ error と state を返す
 * - PKCE は S256 だけ（plain・無しは断る）
 * - 許可は運営がログインして、パスキーの直前の再確認の後（経路の RequireRecentConfirmation）。店はその人が見られる店の中から
 *   1〜50 店を選ぶ（既定では何も選ばない。ADR 0104）。「書ける」は選んだどの店でも運用以上の人だけ。許可すると認可コード（10 分・1 回だけ）を作り、登録した行き先へ code・state・iss を返す
 * - 拒否は登録した行き先へ error=access_denied と state を返す
 */
final class AgentOAuthAuthorizations
{
    public function __construct(
        private readonly OperatorAccess $access,
        private readonly AuditLog $audit,
        private readonly OperatorSecurityNotices $notices,
    ) {}

    /**
     * 頼みを受ける。返すのは、許可の画面の合い言葉（handle）か、行き先へ返す誤りの URL（redirect）か、行き先へ送れない誤り（invalid）
     *
     * @param  array<string, mixed>  $query
     * @return array{handle: string}|array{redirect: string}|array{invalid: string}
     */
    public function start(array $query): array
    {
        $clientId = $query['client_id'] ?? null;
        $client = is_string($clientId) && preg_match('/\A[0-9a-z]{26}\z/', $clientId) === 1 ? AgentOAuthClient::query()->find($clientId) : null;

        if (! $client instanceof AgentOAuthClient) {
            return ['invalid' => 'invalid_client'];
        }

        $redirect = $query['redirect_uri'] ?? null;

        // 行き先は登録したものと文字のまま完全に一致するものだけ（似た URL・問いの付け足し・フラグメントは別のもの）
        if (! is_string($redirect) || ! in_array($redirect, $client->redirect_uris, true)) {
            return ['invalid' => 'invalid_redirect_uri'];
        }

        $state = $query['state'] ?? null;

        if ($state !== null && (! is_string($state) || strlen($state) > 512 || preg_match('/[^\x20-\x7e]/', $state) === 1)) {
            // state が返せない形なら、state を付けずに返す
            return ['redirect' => self::error($redirect, 'invalid_request', null, 'state must be printable ASCII up to 512 characters.')];
        }

        $state = is_string($state) ? $state : null;

        if (($query['response_type'] ?? null) !== 'code') {
            return ['redirect' => self::error($redirect, 'unsupported_response_type', $state, 'response_type must be code.')];
        }

        $challenge = $query['code_challenge'] ?? null;

        if (($query['code_challenge_method'] ?? null) !== 'S256' || ! is_string($challenge) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $challenge) !== 1) {
            return ['redirect' => self::error($redirect, 'invalid_request', $state, 'PKCE with code_challenge_method S256 is required.')];
        }

        if (! AgentOAuth::acceptsResource($query['resource'] ?? null)) {
            return ['redirect' => self::error($redirect, 'invalid_target', $state, 'resource must be the URL of this MCP endpoint.')];
        }

        $handle = AgentOAuth::random();
        AgentOAuthRequest::create([
            'handle_hash' => hash('sha256', $handle),
            'client_id' => $client->id,
            'redirect_uri' => $redirect,
            'state' => $state,
            'code_challenge' => $challenge,
            'requested_scope' => AgentOAuth::requestedScope($query['scope'] ?? null),
            'expires_at' => CarbonImmutable::now()->addMinutes(AgentOAuth::REQUEST_MINUTES),
        ]);

        return ['handle' => $handle];
    }

    /**
     * 許可の画面に出すもの。待ちの頼みが無ければ null
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $handle, Operator $operator): ?array
    {
        $request = self::pending($handle);

        if ($request === null) {
            return null;
        }

        $client = AgentOAuthClient::query()->findOrFail($request->client_id);
        $ids = $this->access->tenantIds($operator);
        $names = Tenant::query()->when($ids !== null, static fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->limit(200)->pluck('name', 'id')->all();
        $tenants = [];

        foreach ($names as $id => $name) {
            $level = (string) $this->access->levelFor($operator, (string) $id);
            $tenants[] = [
                'id' => (string) $id,
                'name' => is_string($name) ? $name : '',
                'level' => $level,
                'can_write' => OperatorAccess::LEVELS[$level] >= OperatorAccess::LEVELS['operate'],
            ];
        }

        return [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'registered_at' => $client->created_at->toIso8601String(),
            ],
            'redirect' => [
                // 登録できる名前（大きく出す）・ホスト・URL の全部（画面で全部見せる。PR 186 の見直しの低 8）
                'site' => AgentOAuthClients::siteOf((string) parse_url($request->redirect_uri, PHP_URL_HOST)),
                'host' => (string) parse_url($request->redirect_uri, PHP_URL_HOST),
                'uri' => $request->redirect_uri,
                'loopback' => str_starts_with($request->redirect_uri, 'http://'),
            ],
            'requested_scope' => $request->requested_scope,
            'expires_at' => $request->expires_at->toIso8601String(),
            'tenants' => $tenants,
        ];
    }

    /**
     * 許可する。$tenantIds は選んだ店の ID の配列（1〜50）。返すのは登録した行き先へ送る URL（code・state・iss つき）。頼みが無い・期限切れなら null
     */
    public function approve(string $handle, Operator $operator, mixed $tenantIds, mixed $scope, ?string $ip = null): ?string
    {
        if (! in_array($scope, AgentOAuth::SCOPES, true)) {
            throw ValidationException::withMessages(['scope' => '「閲覧のみ」か「閲覧と変更」を選んでください。']);
        }

        if (! is_array($tenantIds) || ! array_is_list($tenantIds) || $tenantIds === []) {
            throw ValidationException::withMessages(['tenants' => '接続する店を 1 つ以上選んでください。']);
        }

        if (count($tenantIds) > AgentOAuthGrants::MAX_SHOPS) {
            throw ValidationException::withMessages(['tenants' => '一度に接続できる店は '.AgentOAuthGrants::MAX_SHOPS.' までです。']);
        }

        $shops = AgentOAuthGrants::cleanShops($tenantIds);

        // 文字でない・重なった・空の ID は、黙って捨てずに断る
        if (count($shops) !== count($tenantIds)) {
            throw ValidationException::withMessages(['tenants' => '接続する店の選び方が正しくありません。']);
        }

        $this->checkShops($operator, $shops, $scope);

        $code = AgentOAuth::random();
        $request = $this->decide($handle, function (AgentOAuthRequest $request, CarbonImmutable $now) use ($operator, $shops, $scope, $code): void {
            // 錠の中で確かめ直す（確かめてから書くまでの間に権限を下げられても、下げる前の段階でコードを作らない。PR 187 の見直しの低 3）
            $this->checkShops($operator, $shops, $scope);
            $request->forceFill([
                'decided_at' => $now,
                'approved' => true,
                'operator_id' => $operator->id,
                'tenant_ids' => $shops,
                'scope' => $scope,
                'code_hash' => hash('sha256', $code),
                'code_expires_at' => $now->addMinutes(AgentOAuth::CODE_MINUTES),
            ])->save();
        }, $operator, $shops);

        if ($request === null) {
            return null;
        }

        $host = (string) parse_url($request->redirect_uri, PHP_URL_HOST);
        $this->audit->record('operator.agent_oauth_approved', $operator, ['client' => $request->client_id, 'host' => $host, 'scope' => $scope, 'shops' => $shops], tenantId: AgentOAuthGrants::auditTenant($shops), ip: $ip);
        // 本人へ知らせる（盗まれたセッションから許可された接続に気づけるように。鍵の発行と同じ）。名前は名乗っただけなので、行き先のホストで
        $this->notices->agentTokenIssued($operator, 'OAuth で '.$host.' から');

        return AgentOAuth::redirect($request->redirect_uri, ['code' => $code, 'state' => $request->state, 'iss' => AgentOAuth::issuer()]);
    }

    /**
     * 拒否する。返すのは登録した行き先へ送る URL（error=access_denied・state・iss）。頼みが無い・期限切れなら null
     */
    public function deny(string $handle, Operator $operator, ?string $ip = null): ?string
    {
        $request = $this->decide($handle, static function (AgentOAuthRequest $request, CarbonImmutable $now) use ($operator): void {
            $request->forceFill(['decided_at' => $now, 'approved' => false, 'operator_id' => $operator->id])->save();
        });

        if ($request === null) {
            return null;
        }

        $this->audit->record('operator.agent_oauth_declined', $operator, ['client' => $request->client_id], ip: $ip);

        return self::error($request->redirect_uri, 'access_denied', $request->state, 'The operator denied the request.');
    }

    /**
     * 選んだ店が全部あり、どの店にも段階があり、書けるならどの店でも運用以上か。だめなら 422
     *
     * @param  list<string>  $shops
     */
    private function checkShops(Operator $operator, array $shops, string $scope): void
    {
        $operator = $operator->fresh();
        $known = Tenant::query()->whereIn('id', $shops)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        foreach ($shops as $tenantId) {
            $level = $operator instanceof Operator && $operator->isActive() && in_array($tenantId, $known, true) ? $this->access->levelFor($operator, $tenantId) : null;

            if ($level === null) {
                throw ValidationException::withMessages(['tenants' => '接続できない店が入っています。']);
            }

            // 書けるのは、選んだどの店でも運用以上の人だけ（鍵の発行と同じ。ADR 0101）
            if ($scope === AgentTokens::WRITE && OperatorAccess::LEVELS[$level] < OperatorAccess::LEVELS['operate']) {
                throw ValidationException::withMessages(['scope' => '「閲覧と変更」は、選んだすべての店で「運用」か「会計」の権限を持つ人だけが選べます。']);
            }
        }
    }

    /**
     * 待ちの頼みをロックして決める（同時の許可と拒否・二度押しは、後から来た方が「頼みが無い」になる）。
     * 運営を渡すと、頼みの行より先に運営の行 → 店の権限の行（ID の順）を押さえる（権限を下げる操作と同じ向き）
     *
     * @param  callable(AgentOAuthRequest, CarbonImmutable): void  $apply
     * @param  list<string>  $shops
     */
    private function decide(string $handle, callable $apply, ?Operator $operator = null, array $shops = []): ?AgentOAuthRequest
    {
        if (preg_match(AgentOAuth::HANDLE_SHAPE, $handle) !== 1) {
            return null;
        }

        return DB::connection((string) config('tenancy.database.central_connection'))->transaction(static function () use ($handle, $apply, $operator, $shops): ?AgentOAuthRequest {
            if ($operator !== null) {
                Operator::query()->whereKey($operator->id)->lockForUpdate()->first();

                if ($shops !== []) {
                    OperatorGrant::query()->where('operator_id', $operator->id)->whereIn('tenant_id', $shops)->orderBy('tenant_id')->sharedLock()->get();
                }
            }

            $request = AgentOAuthRequest::query()->where('handle_hash', hash('sha256', $handle))->lockForUpdate()->first();
            $now = CarbonImmutable::now();

            if (! $request instanceof AgentOAuthRequest || $request->decided_at !== null || $request->expires_at->lte($now)) {
                return null;
            }

            $apply($request, $now);

            return $request;
        });
    }

    private static function pending(string $handle): ?AgentOAuthRequest
    {
        if (preg_match(AgentOAuth::HANDLE_SHAPE, $handle) !== 1) {
            return null;
        }

        $request = AgentOAuthRequest::query()->where('handle_hash', hash('sha256', $handle))->first();

        return $request instanceof AgentOAuthRequest && $request->decided_at === null && $request->expires_at->isFuture() ? $request : null;
    }

    private static function error(string $redirect, string $error, ?string $state, string $description): string
    {
        return AgentOAuth::redirect($redirect, ['error' => $error, 'error_description' => $description, 'state' => $state, 'iss' => AgentOAuth::issuer()]);
    }
}
