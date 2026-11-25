<?php

declare(strict_types=1);

namespace App\Storefront\Access;

use App\Storefront\Http\Middleware\NavigationOnly;
use Illuminate\Http\Request;

/**
 * 画面の鍵（ADR 0088）。個人情報の画面（NavigationOnly の印の経路）の中で、核の JavaScript が同じ画面の一部を読む時だけ使う
 * （パスキーのオプション・入場の QR の取り直し）。見出し X-Sugar-Page-Key に入れて送る。
 *
 * - 中身は「店の ID・このセッションの CSRF の合い言葉」の HMAC（APP_KEY から用途ごとに作った鍵）。合い言葉そのものはふつうの画面にも出ていて
 *   タグの JavaScript も読めるが、APP_KEY を知らないので鍵は作れない
 * - 鍵を画面に書くのは、印の関所を通った画面（App のタグを差し込まない画面）の中だけ（forPage）。ふつうの画面には出さないので、
 *   タグの JavaScript が鍵を手に入れる道は「印の画面を読む」しか無く、それを関所が断る
 * - ほかの店・ほかのセッション・ログインし直した後（合い言葉が変わる）の鍵は合わない
 */
final class PageKey
{
    public const string HEADER = 'X-Sugar-Page-Key';

    /**
     * 画面に書く鍵。印の関所を通った要求の時だけ値があり、ほかは空（ふつうの画面・ログインの画面などには出さない）
     */
    public static function forPage(Request $request): string
    {
        if ($request->attributes->get(NavigationOnly::PASSED) !== true) {
            return '';
        }

        return self::of($request) ?? '';
    }

    /**
     * 要求に付いた鍵が、この店・このセッションの鍵と合うか
     */
    public static function matches(Request $request): bool
    {
        $given = $request->headers->get(self::HEADER);
        $expected = self::of($request);

        return is_string($given) && $given !== '' && strlen($given) <= 128 && $expected !== null && hash_equals($expected, $given);
    }

    private static function of(Request $request): ?string
    {
        $tenant = tenant()?->getTenantKey();
        $token = $request->hasSession() ? $request->session()->token() : null;

        if (! is_string($tenant) || $tenant === '' || ! is_string($token) || $token === '') {
            return null;
        }

        // 鍵は APP_KEY から用途ごとに分けて作る（ほかの署名と取り違えない）
        $secret = hash_hmac('sha256', 'storefront-page-key', (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $tenant.'|'.$token, $secret, true)), '+/', '-_'), '=');
    }
}
