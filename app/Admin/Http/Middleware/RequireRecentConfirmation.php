<?php

declare(strict_types=1);

namespace App\Admin\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 重要な操作（パスキーの追加・削除など）の直前に、パスキーでの再確認を求める。
 * 再確認は POST /auth/confirm が記録する（Laravel の auth.password_confirmed_at を流用。中身はパスキー）。
 * 期限は admin.confirm_seconds。切れていたら 423 を返し、SPA が再確認の流れに入る。
 */
final class RequireRecentConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        self::require($request);

        return $next($request);
    }

    /**
     * 経路の印ではなく、中身で決める操作（App の画面の取り消せない操作など）から呼ぶ
     */
    public static function require(Request $request): void
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');

        if (! is_int($confirmedAt) || CarbonImmutable::now()->getTimestamp() - $confirmedAt > (int) config('admin.confirm_seconds')) {
            abort(423, 'パスキーでもう一度確認してください');
        }
    }
}
