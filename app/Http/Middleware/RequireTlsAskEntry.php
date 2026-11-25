<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 入口の Caddy が証明書を取る前に聞く経路を、内側の入口からだけ通す（ADR 0031）。
 *
 * 本番の nginx（docker/nginx/prod.conf）は、Caddy の住所からの内側のポート 8081 だけでこの経路を受け、
 * FastCGI の値 EC_SUGAR_TLS_ASK=1 を付けて PHP に渡す。外の入口（8080）はこの経路を 404 にし、印も付けない。
 * 要求の見出しは PHP では HTTP_… の名前になるので、外から見出しを送っても印にはならない。
 * Host は送る側が書けるので見ない（管理画面・店・内側の名前に偽っても、印が無ければ 404）。
 *
 * 印が無いときは中を見せない（入力の検査より先に 404）。
 */
final class RequireTlsAskEntry
{
    public const string MARKER = 'EC_SUGAR_TLS_ASK';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->server->get(self::MARKER) !== '1') {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
