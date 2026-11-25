<?php

declare(strict_types=1);

namespace App\Storefront\Http\Middleware;

use Closure;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ショップの Cookie（セッション・CSRF・カート・会員の印・App の Cookie）を、そのショップのホストだけのもの（Domain 属性なし）にする
 * （ADR 0090）。EncryptCookies と StartSession より前に置く。
 *
 * 複数の店の形では、店が <店>.<ショップのドメイン> に並び、同じドメインに運営の管理画面や、別のサーバーのサイト（ec-sugar.jp なら www・dev）もある。
 * env の SESSION_DOMAIN を .<ドメイン> にすると、ある店の Cookie がほかの店・管理画面・同じドメインのほかのサイトへも送られる
 * （ほかのサイトの持ち主や、乗っ取られたほかのサイトに、お客さんのセッション・カート・会員の印を読まれる）。env の値によらず、ショップでは Domain を付けない。
 *
 * セッションと CSRF の Cookie は config の session.domain を、カート（CartCookie）・会員の印（MemberCookie）など cookie() で作るものは
 * Cookie の入れ物の既定のドメイン（起動の時に session.domain から決まる）を使うので、両方を空にする（片方だけだと、カートと会員の印の Cookie が
 * .<ドメイン> に広がった。試験で見つけた）。管理画面は ConfigureAdminSession が自分の Cookie を同じ形にする
 */
final class HostOnlySessionCookie
{
    public function __construct(private readonly CookieJar $cookies) {}

    public function handle(Request $request, Closure $next): Response
    {
        config(['session.domain' => null]);
        // ドメインのほかは起動の時と同じ値（CookieServiceProvider と同じ並び。secure の null は「要求が https なら付ける」のまま）
        $secure = config('session.secure');
        $sameSite = config('session.same_site');
        $this->cookies->setDefaultPathAndDomain(
            (string) config('session.path', '/'),
            null,
            is_bool($secure) ? $secure : null,
            is_string($sameSite) ? $sameSite : null,
        );

        return $next($request);
    }
}
