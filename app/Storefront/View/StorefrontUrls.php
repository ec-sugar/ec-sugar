<?php

declare(strict_types=1);

namespace App\Storefront\View;

use App\Models\Tenant;

/**
 * 店頭の URL（店の 1 つ目のドメイン。http か https と番号は APP_URL に合わせる）。管理画面から店頭を開くリンクと、Stripe に登録する通知の送信先に使う。
 * 管理画面のソースには外の URL を書かない決まりなので、サーバーが組み立てて渡す
 */
final class StorefrontUrls
{
    public static function of(Tenant $tenant, string $path = '/'): ?string
    {
        $domain = $tenant->domains()->orderBy('id')->value('domain');

        return is_string($domain) ? self::base($domain).$path : null;
    }

    public static function base(string $domain): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'http' ? 'http' : 'https';
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return "{$scheme}://{$domain}".(is_int($port) ? ":{$port}" : '');
    }
}
