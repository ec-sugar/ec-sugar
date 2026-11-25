<?php

declare(strict_types=1);

namespace App\Storefront\Http\Middleware;

use App\Core\Plugin\Manifest\PageKind;
use App\Http\Middleware\SecurityHeaders;
use App\Http\PrintPage;
use App\Storefront\Apps\AppCsp;
use App\Storefront\Apps\AppsView;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 店頭の応答に付ける防御ヘッダ。CSP は config('storefront.csp')（インラインの script・style、外部の読み込みなし）。
 * 店頭のミドルウェア群の一番外側に置き、断った応答（400・404）にも付ける。全体の SecurityHeaders はここで付けたものを上書きしない。
 * App のスクリプトを差し込んだ画面だけ、その App のマニフェストの宛先を足す（ADR 0034 の 2）。要求ごとに組み立て、
 * 何を差し込んだかは画面を描いたとき（StorefrontView）に要求へ残したものを読む。描いていない応答・個人情報の画面は元のまま。
 * 印刷用のページ（App の領収書。PrintPage）は、CSP をスクリプトなしの印刷の決まりに置き換える（App のスクリプトも差し込まない）。
 * 秘密の道筋の宣言（SecretLink）のある経路は、参照元を渡さない・残さない・検索に載せないの 3 つをどの応答にも掛ける
 */
final class StorefrontSecurityHeaders
{
    /** 要求の印: この応答は Referrer-Policy を no-referrer にする（経路でなく応答ごとに決まる時。公開の写真の経路で、プレビューの署名を持つ時だけ） */
    private const string NO_REFERRER = 'storefront.no_referrer';

    /**
     * この要求の応答で、次の画面や読み込み先へ URL を渡さない（署名つきの URL が document.referrer や Referer で外へ出ないように）。
     * 秘密の道筋の経路は、宣言（SecretLink）で核が自動で掛けるので呼ばなくてよい。これは同じ経路で秘密を持つ時と持たない時がある応答のため
     */
    public static function withoutReferrer(Request $request): void
    {
        $request->attributes->set(self::NO_REFERRER, true);
    }

    /**
     * この要求の応答だけ、枠（iframe）に入れてよい親を 1 つ許す（テーマの下書きのプレビューを管理画面の編集の画面に入れる。ADR 0039 区切り 2）。
     * CSP の frame-ancestors をそのオリジンだけにし、X-Frame-Options（親を 1 つ選べない古い印）は付けない
     */
    public static function framedBy(Request $request, string $origin): void
    {
        $request->attributes->set(SecurityHeaders::FRAMED_BY, $origin);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;
        $apps = $request->attributes->get(AppsView::class);
        $csp = (string) config('storefront.csp');

        $framedBy = $request->attributes->get(SecurityHeaders::FRAMED_BY);

        // 枠に入れてよい親は、形を確かめたオリジン 1 つだけ（空白や ; を含む値で CSP に別の決まりを足させない）。
        // 置き換えられなかった（設定の CSP の書き方が変わった）ときは許さず、'none' と DENY に倒す
        $framed = is_string($framedBy) && preg_match('#\Ahttps?://[a-z0-9.-]+(:\d{1,5})?\z#', $framedBy) === 1
            ? preg_replace("/frame-ancestors 'none'/", "frame-ancestors {$framedBy}", $csp, 1, $replaced)
            : null;

        if (is_string($framed) && ($replaced ?? 0) === 1) {
            $csp = $framed;
        } else {
            $csp = str_contains($csp, 'frame-ancestors') ? $csp : "{$csp}; frame-ancestors 'none'";
            $request->attributes->remove(SecurityHeaders::FRAMED_BY);
            $headers->set('X-Frame-Options', 'DENY');
        }

        $headers->set('Content-Security-Policy', PrintPage::csp($request)
            ?? ($apps instanceof AppsView && $apps->kind !== PageKind::Private && $apps->csp !== [] ? AppCsp::merge($csp, $apps->csp) : $csp));
        // 秘密の道筋の宣言（SecretLink）のある経路は、どの応答（エラー・転送も）にも 3 つをまとめて掛ける（ADR 0081）。
        // 受け口や App が付けた値より強いので上書きする
        $secret = SecretLink::declaredOn($request->route());
        $headers->set('Referrer-Policy', $secret || $request->attributes->get(self::NO_REFERRER) === true ? 'no-referrer' : 'strict-origin-when-cross-origin');

        if ($secret) {
            $headers->set('Cache-Control', 'no-store, private');
            $headers->set('X-Robots-Tag', SecretLink::NO_INDEX);
        }

        return $response;
    }
}
