<?php

declare(strict_types=1);

namespace App\Admin\Http\Middleware;

use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 運営がテナントに立ち入る唯一の入口（ADR 0014）。`/api/tenants/{tenant}/…` にだけ付ける。
 * auth:operator と EnsureOperatorIsActive の後に置く。
 *
 * - テナント ID は経路の引数だけから取る（ヘッダ・本文・クエリからは取らない）。形を確かめてから中央 DB で探す
 * - 読んだら経路の引数から外す。controller は tenant() だけを見る（ID を取り違えて別のテナントを引かない）
 * - 例外でも必ずテナント文脈を閉じる（同じプロセスの次のリクエストに文脈を持ち越さない）
 * - 1 リクエストごとに中央の監査ログへ 1 行（誰が・どのテナントで・何を・結果）。読み取りも残す（個人情報の閲覧の記録）
 * - テナントの中での変更は、TenantActor::of() を actor にしてドメインイベントにも残す
 */
final class EnterTenant
{
    public const string ACTOR = 'admin.actor';

    public const string LEVEL = 'admin.tenant_level';

    /** 経路に付ける段階の印（tenant-level:operate など）。付いていなければ、読むのは閲覧・変えるのは運用 */
    public const string LEVEL_MARK = 'tenant-level:';

    private const string ID_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,62}\z/';

    public function __construct(
        private readonly AuditLog $audit,
        private readonly OperatorAccess $access,
    ) {}

    /**
     * この経路に要る段階（read・operate・money）
     */
    public static function requiredLevel(Request $request, Route $route): string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, self::LEVEL_MARK)) {
                return substr($middleware, strlen(self::LEVEL_MARK));
            }
        }

        return in_array($request->method(), ['GET', 'HEAD'], true) ? 'read' : 'operate';
    }

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $operator = Auth::guard('operator')->user();

        if (! $route instanceof Route || ! $operator instanceof Operator) {
            throw new LogicException('EnterTenant は auth:operator の後で、経路の中で使う');
        }

        if (tenancy()->initialized) {
            throw new LogicException('テナント文脈の中からさらにテナントへは入らない');
        }

        $id = $route->parameter('tenant');
        $route->forgetParameter('tenant');

        $tenant = is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 ? Tenant::query()->find($id) : null;

        if (! $tenant instanceof Tenant) {
            $this->audit->record('operator.tenant_denied', $operator, [
                'reason' => 'not_found',
                'method' => $request->method(),
                'route' => $route->uri(),
            ], ip: $request->ip());

            abort(404);
        }

        // 店ごとの権限（ADR 0024）。与えられていない店は 404（あるかどうかも見せない）、段階が足りなければ 403
        $required = self::requiredLevel($request, $route);
        $level = $this->access->levelFor($operator, (string) $tenant->getTenantKey());

        if ($level === null || OperatorAccess::LEVELS[$level] < OperatorAccess::LEVELS[$required]) {
            $this->audit->record('operator.tenant_denied', $operator, [
                'reason' => $level === null ? 'not_granted' : 'insufficient_level',
                'required' => $required,
                'method' => $request->method(),
                'route' => $route->uri(),
            ], tenantId: $tenant->getTenantKey(), ip: $request->ip());
            abort($level === null ? 404 : 403, $level === null ? '' : 'この店でのこの操作の権限がありません。');
        }

        $request->attributes->set(self::LEVEL, $level);
        $request->attributes->set(self::ACTOR, $operator->actor());
        $status = 500;

        tenancy()->initialize($tenant);

        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } finally {
            tenancy()->end();

            $this->audit->record('operator.tenant_request', $operator, [
                'method' => $request->method(),
                'route' => $route->uri(),
                'status' => $status,
            ], tenantId: $tenant->getTenantKey(), ip: $request->ip());
        }
    }
}
