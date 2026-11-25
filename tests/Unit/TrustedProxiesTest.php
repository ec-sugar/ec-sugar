<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\TrustedProxies;
use PHPUnit\Framework\TestCase;

/**
 * 信じる前段の代理の読み方。既定は無し、誰でも信じる書き方と読めない住所は捨てる
 */
final class TrustedProxiesTest extends TestCase
{
    public function test_nothing_is_trusted_by_default(): void
    {
        $this->assertSame([], TrustedProxies::parse(''));
        $this->assertSame([], TrustedProxies::parse(' , '));
    }

    public function test_written_addresses_and_ranges_are_kept(): void
    {
        $this->assertSame(['10.0.0.5', '10.0.0.0/24', '2001:db8::/32'], TrustedProxies::parse('10.0.0.5, 10.0.0.0/24 ,2001:db8::/32'));
    }

    public function test_anything_that_would_trust_everyone_or_is_not_an_address_is_dropped(): void
    {
        foreach (['*', '**', '0.0.0.0/0', '::/0', '10.0.0.0/33', '10.0.0.0/x', 'nginx', '10.0.0.0/', '999.1.1.1'] as $bad) {
            $this->assertSame([], TrustedProxies::parse($bad), $bad);
        }
        $this->assertSame(['192.168.1.1'], TrustedProxies::parse('*,192.168.1.1,0.0.0.0/0'));
    }
}
