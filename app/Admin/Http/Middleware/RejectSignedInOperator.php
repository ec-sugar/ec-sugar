<?php

declare(strict_types=1);

namespace App\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン・登録の入口は、ログインしていないときだけ使える（Laravel の guest の代わり）。
 *
 * guest はリダイレクトを返すが、管理画面は JSON だけなので 409 を返す。
 * ログインしたまま別の運営の登録トークンを使う（セッションの取り違え）ことも、これで防ぐ。
 */
final class RejectSignedInOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Auth::guard('operator')->check(), 409, 'すでにログインしています。先にログアウトしてください');

        return $next($request);
    }
}
