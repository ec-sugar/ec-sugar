<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 「ログインなしで開いてよい」と決めた経路の印。それ自体は何もしない。
 *
 * すべての経路は、ログインを求める（auth:…）か、この印を付けるかのどちらかでなければならない。
 * tests/Feature/RouteAuthorizationTest がどちらも無い経路を見つけると落ちる（認可の付け忘れを人の目に頼らない）。
 * 付けるときは、なぜ公開してよいかを経路の横にコメントで書く。
 */
final class PublicRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
