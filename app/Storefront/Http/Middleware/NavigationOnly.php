<?php

declare(strict_types=1);

namespace App\Storefront\Http\Middleware;

use App\Storefront\Access\PageKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 個人情報を出す画面は、ブラウザがその画面を開く時だけ通す（ADR 0088。経路の印 navigation-only）。
 *
 * ショップのふつうの画面では、App のタグ（解析・広告。ADR 0034）が同じオリジンの JavaScript として動く。その JavaScript は
 * お客さんの Cookie を持ったまま fetch('/account') などを読めてしまう。ブラウザが付ける見出し Sec-Fetch-Mode・Sec-Fetch-Dest は
 * JavaScript から変えられないので、画面を開く要求（navigate と document）のほかは中身を出さずに断る:
 *   - fetch・XHR（cors / no-cors / same-origin、宛先 empty）、枠（iframe・object・embed）、画像やスクリプトとしての読み込み
 *   - 見出しが無い要求（Sec-Fetch を送らない古いブラウザ・ブラウザでない呼び出し）は通す。断ると画面が開けなくなるため（理由は ADR 0088）
 *
 * navigation-only:page-key の経路だけは、画面の鍵（PageKey。印の画面の中にだけ書く）の付いた同じオリジンの要求も通す
 * （核の JavaScript が同じ画面の一部を読む: パスキーのオプション・入場の QR の取り直し）。
 *
 * 通した応答には、どこにも残さない（no-store。タグの JavaScript が fetch の cache: 'force-cache' でブラウザの控えから読まないように）と、
 * 開いた窓の分離（Cross-Origin-Opener-Policy: noopener-allow-popups。タグの window.open が開いた窓の中身を読めないように。
 * この値を知らないブラウザでは unsafe-none になり、ふつうの画面〔same-origin〕と食い違うので、やはり窓は切り離される）を付ける。
 * 断った応答は中身の無い 403（どの画面か・ログインしているかを見せない）
 */
final class NavigationOnly
{
    /** 要求の印: 関所を通った（この画面に画面の鍵を書いてよい。App のタグは差し込まない） */
    public const string PASSED = 'storefront.navigation_only';

    /** 画面の鍵の付いた要求も通す経路の引数 */
    public const string WITH_PAGE_KEY = 'page-key';

    public function handle(Request $request, Closure $next, ?string $allow = null): Response
    {
        if (! self::opensPage($request) && ! ($allow === self::WITH_PAGE_KEY && self::fromOwnPage($request))) {
            return self::protect(response('', 403));
        }

        $request->attributes->set(self::PASSED, true);

        return self::protect($next($request));
    }

    /**
     * ブラウザが画面を開く要求か（リンク・アドレス欄・フォームの送信・リダイレクトの後）。Sec-Fetch を送らない古いブラウザは通す
     */
    public static function opensPage(Request $request): bool
    {
        $mode = $request->headers->get('Sec-Fetch-Mode');

        if ($mode === null) {
            return true;
        }

        return $mode === 'navigate' && $request->headers->get('Sec-Fetch-Dest') === 'document';
    }

    /**
     * 印の画面の中の核の JavaScript からの要求か（同じオリジンで、画面の鍵が合う）
     */
    private static function fromOwnPage(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');

        return ($site === null || $site === 'same-origin') && PageKey::matches($request);
    }

    private static function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Cross-Origin-Opener-Policy', 'noopener-allow-popups');

        // 見出しで答えが変わることを、間の控え（キャッシュ）に伝える（no-store に加えての念押し）
        $vary = array_values(array_unique([...$response->getVary(), 'Sec-Fetch-Mode', 'Sec-Fetch-Dest', PageKey::HEADER]));
        $response->setVary($vary);

        return $response;
    }
}
