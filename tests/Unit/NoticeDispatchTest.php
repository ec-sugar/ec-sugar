<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 核の知らせ（PaymentConfirmed など）は `EventStore::notice()` で記録に残して出す（ADR 0089）。
 * `event(new …)` で直に出すと、コミットの直後に落ちた時に消え、届け直されない。テストの模擬のほかでは使わない
 */
final class NoticeDispatchTest extends TestCase
{
    private const string DIRECT = '/\b(event|Event::dispatch)\(\s*new\s+(PaymentConfirmed|PaymentAwaiting|PaymentLapsed|FulfillmentShipped|RefundSettled)\(\s*\$/';

    public function test_core_notices_are_not_fired_directly_outside_tests(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach ([$root.'/app', $root.'/plugins'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $path = (string) $file;

                if (! str_ends_with($path, '.php') || str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                    continue;
                }

                if (preg_match(self::DIRECT, (string) file_get_contents($path)) === 1) {
                    $found[] = substr($path, strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $found, '核の知らせは EventStore::notice() で出す（event() で直に出すと届け直されない）');
    }
}
