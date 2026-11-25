<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers;

use App\Admin\Agent\AgentFailures;
use App\Admin\Agent\AgentOAuth;
use App\Admin\Agent\AgentOAuthAuthorizations;
use App\Admin\Agent\AgentOAuthClients;
use App\Admin\Agent\AgentOAuthGrants;
use App\Admin\Agent\OAuthError;
use App\Admin\Services\AuditLog;
use App\Core\RateLimit\Throttle;
use App\Storefront\Access\ClientSource;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AI エージェントの OAuth 2.1 の受け口（ADR 0101）。中身は app/Admin/Agent の AgentOAuth*（ここは受け取りと答えの形だけ）。
 *
 * 管理画面の接続元の制限の外（claude.ai などのクラウドのクライアントから届くように。routes/admin.php・RouteAudit の WITHOUT_ADMIN_IP）:
 * - GET /.well-known/oauth-protected-resource（/mcp）: 保護された資源のメタデータ（RFC 9728）
 * - GET /.well-known/oauth-authorization-server: 認可サーバーのメタデータ（RFC 8414）
 * - POST /oauth/register: 動的なクライアント登録（RFC 7591。公開のクライアントだけ）
 * - POST /oauth/token: 鍵の受け渡し（認可コード ＋ PKCE S256、リフレッシュトークンの入れ替え）
 * - POST /oauth/revoke: 鍵の取り消し（RFC 7009）
 *
 * 接続元の制限の中（運営がブラウザで開く）:
 * - GET /oauth/authorize: 頼みを受けて、許可の画面（SPA の /oauth/consent/<合い言葉>）へ送る
 *
 * 鍵の受け渡しと取り消しは、断った回だけを接続元ごとに数える（共有の出口の接続元から来る正しい要求を、ほかの人の失敗で止めない）
 */
final class OAuthController
{
    /** 鍵の受け渡し・取り消しで断った回の、接続元ごとの 1 分の上限 */
    public const int FAILURES_PER_MINUTE = 30;

    /** 登録の、接続元（/64）ごとの 1 時間の上限（/48 はこの AgentFailures::WIDE_FACTOR 倍） */
    public const int REGISTER_PER_HOUR = 20;

    /**
     * /mcp の道筋を足した形（RFC 9728 の 3.1）。resource は /mcp の URL
     */
    public function protectedResource(): JsonResponse
    {
        return self::resourceMetadata(AgentOAuth::resource());
    }

    /**
     * ルートの形。RFC 9728 の決まりで、resource はメタデータの URL から .well-known 以下を除いたもの（このホストのオリジン。PR 186 の見直しの低 11）
     */
    public function protectedResourceRoot(): JsonResponse
    {
        return self::resourceMetadata(AgentOAuth::issuer());
    }

    private static function resourceMetadata(string $resource): JsonResponse
    {
        return self::metadata([
            'resource' => $resource,
            'authorization_servers' => [AgentOAuth::issuer()],
            'scopes_supported' => AgentOAuth::SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'EC-SUGAR',
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        $issuer = AgentOAuth::issuer();

        return self::metadata([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'registration_endpoint' => $issuer.'/oauth/register',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => AgentOAuth::SCOPES,
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    public function register(Request $request, AgentOAuthClients $clients): JsonResponse
    {
        $metadata = json_decode((string) $request->getContent(), true);

        if (! is_array($metadata) || array_is_list($metadata) || strlen((string) $request->getContent()) > 16_384) {
            return self::error(new OAuthError('invalid_client_metadata', 'Send the client metadata as a JSON object (up to 16 KB).'));
        }

        // 接続元（/64）と広い接続元（/48）ごとに 1 時間の上限（住所を替えて全体の枠を使い切らせない。PR 186 の見直しの中 4）
        $limits = [['agent-oauth-register|'.ClientSource::of($request->ip()), self::REGISTER_PER_HOUR, 3600]];
        $wide = ClientSource::wide($request->ip());

        if ($wide !== null) {
            $limits[] = ['agent-oauth-register-wide|'.$wide, self::REGISTER_PER_HOUR * AgentFailures::WIDE_FACTOR, 3600];
        }

        if (Throttle::firstOver($limits) !== null) {
            return response()->json(['error' => 'too_many_requests'], 429, ['Retry-After' => '3600', 'Cache-Control' => 'no-store']);
        }

        try {
            $client = $clients->register($metadata, $request->ip());
        } catch (OAuthError $e) {
            return self::error($e);
        }

        return response()->json([
            'client_id' => $client->id,
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function token(Request $request, AgentOAuthGrants $grants, AuditLog $audit): JsonResponse
    {
        return $this->counted($request, $audit, static function () use ($request, $grants): JsonResponse {
            $input = $request->only(['grant_type', 'code', 'code_verifier', 'redirect_uri', 'client_id', 'refresh_token', 'scope', 'resource']);

            $tokens = match ($input['grant_type'] ?? null) {
                'authorization_code' => $grants->exchangeCode($input, $request->ip()),
                'refresh_token' => $grants->refresh($input, $request->ip()),
                default => throw new OAuthError('unsupported_grant_type', 'Only authorization_code and refresh_token are supported.'),
            };

            return response()->json($tokens, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        });
    }

    public function revoke(Request $request, AgentOAuthGrants $grants, AuditLog $audit): JsonResponse
    {
        return $this->counted($request, $audit, static function () use ($request, $grants): JsonResponse {
            $grants->revokeByToken($request->only(['token', 'token_type_hint', 'client_id']), $request->ip());

            return response()->json((object) [], 200, ['Cache-Control' => 'no-store']);
        });
    }

    /**
     * 頼みを受けて、許可の画面へ送る。client_id か行き先が登録と違う時は行き先へ送らず、許可の画面に誤りだけを出す
     */
    public function authorize(Request $request, AgentOAuthAuthorizations $authorizations): RedirectResponse
    {
        $outcome = $authorizations->start($request->query());

        if (isset($outcome['handle'])) {
            return redirect()->to('/oauth/consent/'.$outcome['handle'])->header('Cache-Control', 'no-store');
        }

        if (isset($outcome['redirect'])) {
            return redirect()->away($outcome['redirect'])->header('Cache-Control', 'no-store');
        }

        return redirect()->to('/oauth/consent?error='.$outcome['invalid'])->header('Cache-Control', 'no-store');
    }

    /**
     * 断った回だけを接続元ごとに数える。上限を越えた接続元の断る答えは 429 にする（正しい要求は数えずに通す）
     *
     * @param  Closure(): JsonResponse  $handle
     */
    private function counted(Request $request, AuditLog $audit, Closure $handle): JsonResponse
    {
        try {
            return $handle();
        } catch (OAuthError $e) {
            // 接続元は IPv6 の /64 と /48 でまとめる（住所を替えて上限を抜けさせない。PR 186 の見直しの高 1）
            if (AgentFailures::over('agent-oauth-fail', $request->ip(), self::FAILURES_PER_MINUTE)) {
                return response()->json(['error' => 'too_many_requests'], 429, ['Retry-After' => '60', 'Cache-Control' => 'no-store']);
            }

            // 鍵の違う要求として、接続元だけを残す（おかしな動きの数え。中身は残さない。接続元ごとに 1 分 1 行）
            if (in_array($e->error, ['invalid_grant', 'invalid_client'], true)) {
                AgentFailures::record($audit, 'agent-oauth-fail', $request->ip(), ['via' => 'oauth']);
            }

            return self::error($e);
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function metadata(array $document): JsonResponse
    {
        return response()->json($document, 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_SLASHES);
    }

    private static function error(OAuthError $e): JsonResponse
    {
        return response()->json($e->body(), $e->status, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
