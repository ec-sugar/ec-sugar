<?php

declare(strict_types=1);

namespace App\Storefront\Http\Middleware;

use App\Core\Party\Services\CustomerSessions;
use App\Http\Middleware\HostPrefixedCookies;
use App\Storefront\Access\ShopPasswordCookie;
use App\Storefront\Apps\ConsentCookie;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\Members\MemberCookie;

/**
 * ショップの `__Host-` の Cookie（HostPrefixedCookies。見直しの中 1、ADR 0090 の 6.）。
 *
 * 前の名前（頭の無い名前）から移る間、カート・会員の印・準備中のパスワード・タグの同意だけは古い名前を一度読んで `__Host-` の名前で出し直す
 * （お客さんのカートとログインを落とさない）。読むのは 1 店舗の形のときだけ: 隣のホストから Cookie を置かれうるのは、同じドメインに店が並ぶ
 * 複数の店の形になってから（1 店舗の間は、同じドメインの別のサイトは持ち主の Plesk の www・dev だけ）。なので本番は、このバージョンへ更新して
 * 1 店舗のまま移ってから広げる（`sudo ec-sugar shops multi` の後は古い名前を読まない）。
 * Laravel のセッション・CSRF の Cookie は移さない（隣のホストから置かれたセッションの番号を使わない。移った時に、入力の途中の注文は入れ直しになる）。
 * 古い名前を読む作りは、移る期間（カートの Cookie の 30 日）の後のバージョンで消す（ADR 0090 の 6.）
 */
final class StorefrontHostPrefixedCookies extends HostPrefixedCookies
{
    protected function legacyNames(): array
    {
        return [
            CartCookie::NAME => CartService::LIFETIME_DAYS * 24 * 60,
            MemberCookie::NAME => CustomerSessions::DAYS * 24 * 60,
            ShopPasswordCookie::NAME => ShopPasswordCookie::DAYS * 24 * 60,
            ConsentCookie::NAME => ConsentCookie::DAYS * 24 * 60,
        ];
    }

    protected function legacyAllowed(): bool
    {
        return config('tls.shops') !== 'multi';
    }
}
