<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * https の要求では、Cookie を `__Host-` の頭の名前でだけやり取りする（見直しの中 1、ADR 0090 の 6.）。EncryptCookies より外に置く。
 *
 * 店と管理画面と別のサーバーのサイト（www・dev）が同じドメイン（ec-sugar.jp）の下に並ぶので、どこか 1 つで JavaScript か Set-Cookie を動かせる人は、
 * `Domain=.ec-sugar.jp; Path=/checkout` の Cookie を置いて隣の店のホストへ届けられる（cookie tossing）。Laravel は知らないセッションの番号も
 * そのまま使うので、お客さんを攻める人のセッションで買わせ、入力を読める（セッション固定）。SameSite は同じサイトの中を分けないので守りにならない。
 * ブラウザは `__Host-` の頭の Cookie を、Secure・Path=/・Domain なしのときだけ受け取る（隣のホストからは置けない）。
 *
 * - 要求: `__Host-<名前>` だけを `<名前>` として内側へ渡す。頭の無い Cookie は渡さない（隣のホストから置かれたものかもしれない）。
 *   例外は移る間の古い名前（legacyNames）だけで、`__Host-` の方が無く、置き換えの期間（legacyAllowed）のときだけ一度読む
 * - 応答: 内側が出した Cookie を全部 `__Host-<名前>`（Secure・Path=/・Domain なし）にする。値はそのまま（EncryptCookies が内側の名前で暗号にしたもの）。
 *   読んだ古い名前は、応答が出し直さなければ `__Host-` の名前で出し直し、古い名前の Cookie（このホストだけのもの）を消す
 * - http（手元の make up の http://*.localhost:8080・テスト）では何もしない（`__Host-` は Secure が要り、http では置けない）
 *
 * JavaScript が名前で読む Cookie は管理画面の XSRF-TOKEN だけで、resources/admin/api.js が `__Host-XSRF-TOKEN` を先に読む
 */
class HostPrefixedCookies
{
    public const string PREFIX = '__Host-';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isSecure()) {
            return $next($request);
        }

        $legacy = $this->legacyAllowed() ? $this->legacyNames() : [];
        $inner = [];
        $plain = [];

        foreach ($request->cookies->all() as $name => $value) {
            $name = (string) $name;

            if (str_starts_with($name, self::PREFIX)) {
                if (strlen($name) > strlen(self::PREFIX)) {
                    $inner[substr($name, strlen(self::PREFIX))] = $value;
                }
            } else {
                $plain[$name] = $value;
            }
        }

        $migrated = [];
        foreach ($plain as $name => $value) {
            if (! array_key_exists($name, $inner) && array_key_exists($name, $legacy) && is_string($value) && $value !== '') {
                $inner[$name] = $value;
                $migrated[$name] = $value;
            }
        }

        $request->cookies->replace($inner);

        $response = $next($request);
        $headers = $response->headers;

        foreach ($headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), self::PREFIX)) {
                continue;
            }

            $headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            $headers->setCookie(Cookie::create(
                self::PREFIX.$cookie->getName(),
                $cookie->getValue(),
                $cookie->getExpiresTime(),
                '/',
                null,
                true,
                $cookie->isHttpOnly(),
                $cookie->isRaw(),
                $cookie->getSameSite(),
                $cookie->isPartitioned(),
            ));
        }

        $set = array_map(static fn (Cookie $cookie): string => $cookie->getName(), $headers->getCookies());

        foreach ($migrated as $name => $value) {
            if (! in_array(self::PREFIX.$name, $set, true)) {
                $headers->setCookie(Cookie::create(self::PREFIX.$name, $value, time() + $legacy[$name] * 60, '/', null, true, true, false, Cookie::SAMESITE_LAX));
            }
        }

        // 自分の古い名前の Cookie（このホストだけのもの）を消す。隣のホストから置かれたもの（Domain つき）は消せないが、読まないので効かない
        $ours = [...array_keys($legacy), ...array_keys($this->legacyNames()), (string) config('session.cookie'), 'XSRF-TOKEN'];
        foreach (array_keys($plain) as $name) {
            if (in_array($name, $ours, true)) {
                $headers->setCookie(Cookie::create($name, '', 1, '/', null, true, true, false, Cookie::SAMESITE_LAX));
            }
        }

        return $response;
    }

    /**
     * 移る間だけ、古い名前（頭の無い名前）で一度読んでよい Cookie と、出し直す時の長さ（分）。既定は無し（古い名前は読まない）
     *
     * @return array<string, int>
     */
    protected function legacyNames(): array
    {
        return [];
    }

    /**
     * 古い名前を読んでよい期間か。既定は読まない
     */
    protected function legacyAllowed(): bool
    {
        return false;
    }
}
