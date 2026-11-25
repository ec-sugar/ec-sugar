<?php

declare(strict_types=1);

namespace App\Admin\Http\Middleware;

use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorSessions;
use App\Admin\Services\RevokedSessions;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン中の運営を毎リクエスト確かめ直す（auth:operator の後に置く）。
 *
 * - 止められた運営（disabled_at）は、その次のリクエストでセッションごと締め出す
 * - ログインからの絶対期限（admin.session_max_hours）を過ぎたら、操作中でもログインし直させる
 * - ログアウトしたセッションの番号（RevokedSessions）は、ログインが書き戻されていても締め出す
 * - ログイン中のセッションの行（OperatorSessions）が無い・終えたなら締め出す（パスキーを消した・ほかの端末を全部ログアウト）。
 *   行の無いセッション（この仕組みの前のログイン）もログインし直させる
 */
final class EnsureOperatorIsActive
{
    public const string AUTHENTICATED_AT = 'admin.authenticated_at';

    public function __construct(
        private readonly AuditLog $audit,
        private readonly RevokedSessions $revoked,
        private readonly OperatorSessions $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $operator = Auth::guard('operator')->user();
        $authenticatedAt = $request->session()->get(self::AUTHENTICATED_AT);
        $maxAge = (int) config('admin.session_max_hours') * 3600;

        $reason = match (true) {
            ! $operator instanceof Operator => 'missing',
            $this->revoked->isRevoked($request->session()->getId()) => 'revoked',
            ! $operator->fresh()?->isActive() => 'disabled',
            ! is_int($authenticatedAt) || CarbonImmutable::now()->getTimestamp() - $authenticatedAt > $maxAge => 'expired',
            ! $this->sessions->alive($request, $operator) => 'ended',
            default => null,
        };

        if ($reason !== null) {
            if ($operator instanceof Operator) {
                $current = $this->sessions->current($request);

                // 止めた・期限を過ぎたセッションの行も終えておく（一覧に残さない）
                if ($current !== null && in_array($reason, ['disabled', 'expired', 'revoked'], true)) {
                    $this->sessions->end($operator, $current, $reason === 'revoked' ? 'logout' : $reason);
                }

                $this->audit->record('operator.session_ended', $operator, ['reason' => $reason], ip: $request->ip());
            }

            Auth::guard('operator')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(401, 'ログインし直してください');
        }

        return $next($request);
    }
}
