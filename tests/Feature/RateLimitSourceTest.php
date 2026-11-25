<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * 回数の制限の接続元（の見直し、中 4）。Cloudflare Tunnel の形で IPv6 のお客さんが来るようになったので、
 * どの制限も IPv6 を /64 にまとめて数える（後ろの 64 ビットを替えるだけで抜けられないように）。IPv4 は住所のまま
 */
final class RateLimitSourceTest extends TestCase
{
    /** 接続元で数える制限（ログインした利用者で数える api は、ログインしていない時だけ接続元） */
    private const array LIMITERS = ['auth', 'api', 'storefront', 'storefront-postal', 'storefront-order-lookup', 'storefront-password', 'storefront-cart', 'storefront-coupon', 'storefront-consent', 'webhooks'];

    public function test_every_limiter_counts_one_ipv6_64_as_one_source(): void
    {
        foreach (self::LIMITERS as $name) {
            $a = $this->key($name, '2001:db8:1:2::1');
            $b = $this->key($name, '2001:db8:1:2:ffff:ffff:ffff:fffe');
            $other = $this->key($name, '2001:db8:1:3::1');

            $this->assertSame($a, $b, "{$name}: 同じ /64 を別に数えている");
            $this->assertNotSame($a, $other, "{$name}: 別の /64 を同じに数えている");
        }
    }

    public function test_ipv4_addresses_are_counted_one_by_one(): void
    {
        foreach (self::LIMITERS as $name) {
            $this->assertNotSame($this->key($name, '198.51.100.7'), $this->key($name, '198.51.100.8'), "{$name}: IPv4 をまとめている");
        }
    }

    private function key(string $name, string $ip): string
    {
        $limiter = RateLimiter::limiter($name);
        $this->assertNotNull($limiter, "{$name} の制限が無い");
        $limit = $limiter(Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip]));
        $limit = is_array($limit) ? $limit[0] : $limit;
        $this->assertInstanceOf(Limit::class, $limit);

        return (string) $limit->key;
    }
}
