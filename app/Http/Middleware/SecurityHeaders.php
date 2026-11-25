<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * すべての応答に付ける防御ヘッダ（docs/CONCEPT.md 7.2、ADR 0009）。
 * nginx でも最低限は付けているが、入口が nginx 以外になっても同じ防御が効くようアプリ側でも出す。
 * 値は config/security.php。店頭テーマが外部リソースを使う場合はそこで緩める（核のコードは触らない）。
 */
final class SecurityHeaders
{
    /** 要求の印: この応答は枠に入れてよい親を CSP の frame-ancestors で 1 つ許すので、X-Frame-Options を付けない（テーマの下書きのプレビュー。ADR 0039） */
    public const string FRAMED_BY = 'security.framed_by';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // ショップの個人情報の画面は、同じオリジンの窓からも切り離す値を先に付ける（NavigationOnly。ADR 0088）ので、上書きしない
        if (! $headers->has('Cross-Origin-Opener-Policy')) {
            $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        }

        // ほかのサイトがこちらの画面・写真を読み込めないように。Cross-Origin-Embedder-Policy は付けない
        // （後で入れるタグの連携の外のスクリプトが読めなくなるため。開いた窓の分離は Opener-Policy で足りる）
        if (! $headers->has('Cross-Origin-Resource-Policy')) {
            $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        }

        // 古いブラウザ向け（新しいブラウザは CSP の frame-ancestors を見る）
        if (! $headers->has('X-Frame-Options') && ! is_string($request->attributes->get(self::FRAMED_BY))) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        // 管理画面は自分の決まり（AdminSecurityHeaders。受付でカメラを使う）を先に付けるので、上書きしない
        if (! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', (string) config('security.permissions_policy'));
        }

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', (string) config('security.csp'));
        }

        // HSTS は HTTPS で受けた時だけ（開発の http で付けるとブラウザが記憶して困る）
        if ($request->isSecure() && config('security.hsts')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // API の応答は中間キャッシュに残さない
        if ($response instanceof JsonResponse) {
            $headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
