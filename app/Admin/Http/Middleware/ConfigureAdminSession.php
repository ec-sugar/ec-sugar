<?php

declare(strict_types=1);

namespace App\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理画面のセッション Cookie を店頭と分ける（StartSession より前に置く）。
 *
 * - Cookie 名を変え、ホスト限定（Domain 属性なし）にする。店頭のセッションと混ざらない
 * - SameSite=Strict。他サイトからの遷移では Cookie を送らない（CSRF の二重の守り）
 * - 無操作の期限を短くし、ブラウザを閉じたら消す。絶対期限は EnsureOperatorIsActive が見る
 * - 他サイトから来た通信（Sec-Fetch-Site が cross-site / same-site）への応答では、セッションと CSRF の Cookie を返さない。
 *   SameSite=Strict の Cookie はその通信に付かないので、サーバーは新しいセッションを作る。それを返すとブラウザの本物の
 *   セッションが上書きされ、リンクを踏むだけ・攻撃サイトを開くだけで運営がログアウトさせられる（ブラウザのテストで発覚）
 */
final class ConfigureAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        config([
            'session.cookie' => config('admin.session_cookie'),
            'session.domain' => null,
            'session.path' => '/',
            'session.same_site' => 'strict',
            'session.http_only' => true,
            'session.secure' => $request->isSecure() || (bool) config('session.secure'),
            'session.lifetime' => config('admin.session_idle_minutes'),
            'session.expire_on_close' => true,
        ]);

        $response = $next($request);

        if (in_array($request->headers->get('Sec-Fetch-Site'), ['cross-site', 'same-site'], true)) {
            $response->headers->removeCookie((string) config('admin.session_cookie'));
            $response->headers->removeCookie('XSRF-TOKEN');
        }

        return $response;
    }
}
