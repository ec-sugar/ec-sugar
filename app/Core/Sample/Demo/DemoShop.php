<?php

declare(strict_types=1);

namespace App\Core\Sample\Demo;

use App\Models\Tenant;

/**
 * デモの店の印（ADR 0086）。誰でも触れる公開の見本の店で、お金とメールを動かさない。
 *
 * - 印は中央の店の行（tenants.data の demo）に置き、付け外しは CLI の `shop:demo` だけ（管理画面の経路からは変えられない）
 * - 印が効くのは、店の ID が `demo` で始まり、後に英小文字・数字が続くときだけ（例 demosake）（打ち間違いで動いている店に付けても効かない。毎晩の戻しで消える物があるため）
 * - 印のある店では: ショップの全部の画面に帯と noindex・Stripe はテストの鍵だけ（Connect も使わない）・お客さんと店の人へのメールを送らず
 *   来訪者ごとの受け箱に置く・メールアドレスの入力は来訪者だけのアドレスに置き換える・毎晩、作った時の状態に戻す
 */
final class DemoShop
{
    /** デモの店の ID の頭（店の ID は DB の名前にも使うので、ハイフンは使えない。例 demosake） */
    public const string ID_PREFIX = 'demo';

    /** 中央の店の行の data に置く名前 */
    public const string FLAG = 'demo';

    /** 作ったスクリプトの覚え（店の種類・名前・写真の置き場。毎晩の戻しで同じ形に作り直す） */
    public const string BUILD = 'demo_build';

    /**
     * この店がデモの店か（印があり、ID が demo で始まる）
     */
    public static function is(?Tenant $tenant): bool
    {
        return $tenant instanceof Tenant
            && $tenant->getAttribute(self::FLAG) === true
            && self::eligibleId((string) $tenant->getTenantKey());
    }

    /**
     * 今の店の文脈がデモの店か（文脈の外では偽）
     */
    public static function active(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        $tenant = tenant();

        return $tenant instanceof Tenant && self::is($tenant);
    }

    /**
     * デモの印を付けてよい ID か
     */
    public static function eligibleId(string $id): bool
    {
        return preg_match('/\A'.self::ID_PREFIX.'[a-z0-9]{1,28}\z/', $id) === 1;
    }
}
