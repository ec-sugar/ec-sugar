<?php

declare(strict_types=1);

namespace App\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理画面の接続元を制限する（CONCEPT 7.2）。拒否は 404（管理画面の存在を教えない）。
 *
 * 本番で ADMIN_ALLOWED_IPS が空なら全部拒否する（設定漏れで全世界に開かないように、閉じる側に倒す）。
 * 本当にどこからでも受けるなら、0.0.0.0/0,::/0 と明示する。
 */
final class RestrictAdminIp
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowed */
        $allowed = config('admin.allowed_ips');

        if ($allowed === []) {
            abort_if(app()->isProduction(), 404);

            return $next($request);
        }

        $ip = $request->ip();

        abort_unless($ip !== null && IpUtils::checkIp($ip, $allowed), 404);

        return $next($request);
    }
}
