<?php

declare(strict_types=1);

namespace App\Admin\Http\Middleware;

use App\Admin\Agent\AgentContext;
use App\Admin\Agent\AgentFailures;
use App\Admin\Agent\AgentOAuth;
use App\Admin\Agent\AgentTokens;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Core\RateLimit\Throttle;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AI エージェントの入口（MCP の /mcp。ADR 0100）。管理画面のセッション・Cookie・CSRF を使わず、`Authorization: Bearer <鍵>` だけで入る。
 *
 * - 鍵は管理画面で作った鍵か、OAuth で出したアクセストークン（同じ表の行。ADR 0101）
 * - 鍵が無い・形が違う・期限切れ・取り消し・運営が止まっている・店の権限が無い は、どれも同じ 401（何が違うかを見せない）。
 *   401 には保護された資源のメタデータの場所（WWW-Authenticate の resource_metadata）を付ける
 * - 管理画面の接続元の制限の外（ADR 0101 の要点の 6）。守りは鍵と回数の上限。鍵の違う要求だけを接続元ごとに数え、正しい鍵は数えずに通す
 * - 鍵ごとに 1 分 60 回まで（越えたら 429）。書く道具はさらに 1 分 10 回まで（道具の層の AgentWriteTool が数える。ADR 0101）
 * - 入れたら、鍵の届く店（AgentTokens::resolve が段階を引き直した店）の中から 1 店を選んで、その店の文脈に入り、例外でも必ず閉じる（ADR 0104）。
 *   1 店だけの鍵はいつもその店。複数の店の鍵は、道具の呼び出し（tools/call）の引数 shop が届く店を指した時だけその店に入る。
 *   shop が無い・届かない店・道具の呼び出しでない要求（一覧など）は、どの店にも入らない（道具が「shop を選んで」と断る）。
 *   1 回の要求は 1 つの道具だけ（laravel/mcp は JSON-RPC の一括を断り、道具をまとめて呼ぶ口〔ToolSearch〕も使っていない）なので、1 回の要求で入る店は 1 つ
 * - 答えに Cache-Control: no-store を付ける。流す応答（StreamedResponse）は、店の文脈を閉じた後に動くので断る（406）
 * - 1 回ごとに中央の監査ログへ（どの鍵で・どの店で・MCP のどの操作と道具か）。中身の引数は残さない
 */
final class AuthenticateAgent
{
    public const int PER_MINUTE = 60;

    /** 鍵の違う要求の、接続元ごとの 1 分の上限 */
    public const int FAILURES_PER_MINUTE = 20;

    /** 監査ログに数えない操作（中身を読まない合図だけ） */
    private const array QUIET = ['ping', 'notifications/initialized', 'notifications/cancelled'];

    public function __construct(
        private readonly AgentTokens $tokens,
        private readonly AuditLog $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized) {
            throw new LogicException('テナント文脈の中から AI エージェントの入口には入らない');
        }

        $header = (string) $request->headers->get('Authorization', '');
        // 認証の方式の名前は大文字と小文字を区別しない（RFC 9110）
        $resolved = preg_match('/\ABearer +(\S+)\z/i', $header, $m) === 1 ? $this->tokens->resolve($m[1]) : null;

        if ($resolved === null) {
            // 鍵の違う要求だけを接続元ごとに数える（鍵の当て推量と、取り消した鍵の使い回しを止める。見直しの低 8）。
            // 照らし合わせて通った鍵は数えずに先へ通す: /mcp は接続元の制限の外で、claude.ai などのクラウドのクライアントは
            // 多くの人が同じ出口の接続元から来る。失敗の上限で正しい鍵まで 429 にしない（11-12 の全体の見直しの中 3）。
            // 接続元は IPv6 の /64 と /48 でまとめる（住所を替えて上限を抜けさせない。PR 186 の見直しの高 1）
            if (AgentFailures::over('agent-fail', $request->ip(), self::FAILURES_PER_MINUTE)) {
                return response()->json(['error' => 'too_many_requests'], 429, ['Retry-After' => '60']);
            }

            // 鍵の違う要求を数えるために、接続元だけを中央の監査ログへ（見せられた鍵は残さない。おかしな動きの知らせ。ADR 0103）。
            // 接続元ごとに 1 分 1 行にまとめる（失敗のたびに監査ログの錠を取らせない）
            AgentFailures::record($this->audit, 'agent-fail', $request->ip());

            // OAuth で入るクライアントへ、保護された資源のメタデータの場所を教える（RFC 9728 の 5.1・MCP の認可）
            return response()->json(['error' => 'unauthorized'], 401, ['WWW-Authenticate' => 'Bearer resource_metadata="'.AgentOAuth::resourceMetadataUrl().'"']);
        }

        ['token' => $token, 'operator' => $operator, 'shops' => $shops] = $resolved;

        $body = json_decode((string) $request->getContent(), true);
        $method = is_array($body) && is_string($body['method'] ?? null) ? mb_substr($body['method'], 0, 40) : null;
        $tool = is_array($body) && is_string($body['params']['name'] ?? null) ? mb_substr($body['params']['name'], 0, 40) : null;
        $tenantId = self::targetShop($shops, $body, $method);

        // OAuth の鍵は 1 時間ごとに入れ替わるので、回数は許可の系統で数える（鍵ごとだと入れ替えで上限を割られる。PR 186 の見直しの中 2）
        $context = new AgentContext($token->id, $operator->id, $tenantId, $tenantId !== null ? $shops[$tenantId] : self::highest($shops), $token->scope, $token->oauth_grant_id, $shops);

        if (! Throttle::allow('agent:'.$context->limitKey(), self::PER_MINUTE, 60)) {
            return response()->json(['error' => 'too_many_requests'], 429, ['Retry-After' => '60']);
        }

        if (! in_array($method, self::QUIET, true)) {
            // grant: OAuth の許可の系統（おかしな動きの「1 つの鍵からの書く操作」は系統で数える）。店はこの要求で入った店
            $this->audit->record('operator.agent_request', $operator, array_filter(['token' => $token->id, 'grant' => $token->oauth_grant_id, 'method' => $method, 'tool' => $tool]), tenantId: $tenantId, ip: $request->ip());
        }

        $tenant = $tenantId === null ? null : Tenant::query()->findOrFail($tenantId);

        try {
            app()->instance(AgentContext::class, $context);

            if ($tenant !== null) {
                tenancy()->initialize($tenant);
            }

            $response = $next($request);

            // 流す応答（SSE など）は、本体がこの後（店の文脈を閉じた後）に動くので断る（ADR 0101 の作って決めたこと）
            if ($response instanceof StreamedResponse) {
                return response()->json(['error' => 'streaming_not_supported'], 406, ['Cache-Control' => 'no-store, private']);
            }

            // 答えには店のデータ（個人情報を含みうる）が入るので、どこにも残させない
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        } finally {
            tenancy()->end();
            app()->forgetInstance(AgentContext::class);
        }
    }

    /**
     * この要求で入る店。1 店だけの鍵は、shop が無いかその店を指す時にその店。複数の店の鍵は、道具の呼び出しの shop が届く店を指す時だけ。
     * どちらでもなければ null（店に入らない）
     *
     * @param  non-empty-array<string, string>  $shops
     */
    private static function targetShop(array $shops, mixed $body, ?string $method): ?string
    {
        $arguments = $method === 'tools/call' && is_array($body) && is_array($body['params'] ?? null) ? ($body['params']['arguments'] ?? null) : null;
        $asked = is_array($arguments) && array_key_exists('shop', $arguments) ? $arguments['shop'] : null;

        if ($asked !== null) {
            return is_string($asked) && isset($shops[$asked]) ? $asked : null;
        }

        return count($shops) === 1 ? (string) array_key_first($shops) : null;
    }

    /**
     * 届く店の中のいちばん高い段階（店に入らない要求で、道具を一覧に出すかを決めるだけ）
     *
     * @param  non-empty-array<string, string>  $shops
     */
    private static function highest(array $shops): string
    {
        $best = 'read';

        foreach ($shops as $level) {
            if (OperatorAccess::LEVELS[$level] > OperatorAccess::LEVELS[$best]) {
                $best = $level;
            }
        }

        return $best;
    }
}
