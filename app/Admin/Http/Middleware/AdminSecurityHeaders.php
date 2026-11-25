<?php

declare(strict_types=1);

namespace App\Admin\Http\Middleware;

use App\Http\PrintPage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理画面の応答に付ける、店頭より厳しい防御ヘッダ。
 *
 * - CSP は config('security.admin_csp')。インラインの script / style を許さず、Trusted Types で DOM への HTML の書き込みを Vue の経路だけにする
 * - ページの編集の画面の殻だけ、その店のショップのオリジンを枠の読み込み先（frame-src）に足す（allowFrame。ほかの応答は枠を一切読まない）
 * - 印刷用のページ（App の納品書・領収書。PrintPage）は、CSP をスクリプトなしの印刷の決まりに置き換える
 * - 他サイトの枠に入れない、Referer を出さない（登録トークンは URL の断片にあるが、念のため）、他オリジンから読ませない
 * - カメラは自分のオリジンだけ（チケットの受付で QR を読む）。マイク・位置・支払い・USB は使わない
 *
 * 全体の SecurityHeaders は、ここで付けたものを上書きしない。
 */
final class AdminSecurityHeaders
{
    /** 要求の印: この応答の CSP に、枠（iframe）の読み込み先として足すオリジン（ページの編集の画面の殻だけ。ShellController） */
    private const string FRAME_SRC = 'admin.frame_src';

    /**
     * この要求の応答だけ、枠の読み込み先を 1 つ許す（ページの編集の画面の真ん中のプレビュー。ADR 0039 区切り 2）
     */
    public static function allowFrame(Request $request, string $origin): void
    {
        $request->attributes->set(self::FRAME_SRC, $origin);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;
        $csp = (string) config('security.admin_csp');
        $frame = $request->attributes->get(self::FRAME_SRC);

        // 足すのは形を確かめたオリジン 1 つだけ（空白や ; を含む値で CSP に別の決まりを足させない）
        if (is_string($frame) && preg_match('#\Ahttps?://[a-z0-9.-]+(:\d{1,5})?\z#', $frame) === 1) {
            $csp .= "; frame-src {$frame}";
        }

        $headers->set('Content-Security-Policy', PrintPage::csp($request) ?? $csp);
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        // 受付の QR を読むカメラ（自分のオリジンだけ）
        $headers->set('Permissions-Policy', (string) config('security.admin_permissions_policy'));

        return $response;
    }
}
