<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Admin\Services\EgressWatch;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * 外への通信の出口の代理の数を、運営の様子の機械の調子と困りごとにする（ADR 0102）。読むのは代理が書く状態のファイルだけ（DB は使わない）
 */
final class EgressWatchTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'egress-state-') ?: throw new \RuntimeException('仮のファイルを作れない');
        config(['egress.proxy' => 'http://egress:3128', 'egress.state' => $this->path]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $hour
     */
    private function state(string $mode, CarbonImmutable $at, array $counts = [], array $hour = []): void
    {
        file_put_contents($this->path, (string) json_encode([
            'version' => 1, 'mode' => $mode, 'smtp_relay' => true, 'started_at' => $at->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $at->utc()->format('Y-m-d\TH:i:s\Z'),
            'last24h' => $counts + ['allowed' => 0, 'unlisted' => 0, 'refused' => 0, 'failed' => 0, 'smtp' => 0],
            'last1h' => $hour + ['allowed' => 0, 'unlisted' => 0, 'refused' => 0, 'failed' => 0, 'smtp' => 0],
            'last_unlisted_at' => null, 'last_refused_at' => null,
        ]));
    }

    public function test_quiet_proxy_is_ok(): void
    {
        $now = CarbonImmutable::parse('2026-11-13 10:00:00', 'Asia/Tokyo');
        $this->state('watch', $now->subSeconds(10), ['allowed' => 12, 'smtp' => 3]);

        $result = EgressWatch::present($now);

        $this->assertSame([], $result['issues']);
        $this->assertSame('ok', $result['health']['egress']['status']);
        $this->assertSame('watch', $result['health']['egress']['mode']);
        $this->assertSame(12, $result['health']['egress']['last24h']['allowed']);
    }

    public function test_watch_mode_reports_unlisted_and_refused_as_soon(): void
    {
        $now = CarbonImmutable::now();
        $this->state('watch', $now, ['unlisted' => 4, 'refused' => 1]);

        $result = EgressWatch::present($now);

        $this->assertSame([
            ['key' => 'egress_unlisted', 'level' => 'soon', 'count' => 4, 'tenant' => null],
            ['key' => 'egress_refused', 'level' => 'soon', 'count' => 1, 'tenant' => null],
        ], $result['issues']);
        $this->assertSame('warn', $result['health']['egress']['status']);
    }

    public function test_enforce_mode_refusals_are_urgent(): void
    {
        $now = CarbonImmutable::now();
        $this->state('enforce', $now, ['refused' => 2, 'unlisted' => 0]);

        $this->assertSame([['key' => 'egress_refused', 'level' => 'urgent', 'count' => 2, 'tenant' => null]], EgressWatch::present($now)['issues']);
    }

    /**
     * 直近 1 時間に、通った先へつながらないのに 1 本も通っていない（名前を引けない・代理から外へ出られない）: 要対応
     */
    public function test_failures_without_any_success_in_the_last_hour_are_urgent(): void
    {
        $now = CarbonImmutable::now();
        $this->state('watch', $now, ['failed' => 5, 'allowed' => 30], ['failed' => 3]);
        $this->assertSame([['key' => 'egress_failing', 'level' => 'urgent', 'count' => 3, 'tenant' => null]], EgressWatch::present($now)['issues']);

        // 1 本でも通っていれば（相手の一時の不調）困りごとにしない
        $this->state('watch', $now, ['failed' => 5, 'allowed' => 30], ['failed' => 3, 'allowed' => 1]);
        $this->assertSame([], EgressWatch::present($now)['issues']);
        $this->state('watch', $now, ['failed' => 5, 'unlisted' => 30], ['failed' => 3, 'unlisted' => 1]);
        $this->assertSame([['key' => 'egress_unlisted', 'level' => 'soon', 'count' => 30, 'tenant' => null]], EgressWatch::present($now)['issues']);
    }

    public function test_a_stale_or_missing_or_broken_state_means_the_proxy_is_down(): void
    {
        $now = CarbonImmutable::now();
        $down = [['key' => 'egress_proxy_down', 'level' => 'urgent', 'count' => 1, 'tenant' => null]];

        $this->state('watch', $now->subMinutes(EgressWatch::STALE_MINUTES + 1));
        $this->assertSame($down, EgressWatch::present($now)['issues'], '5 分より古い');
        $this->assertSame('bad', EgressWatch::present($now)['health']['egress']['status']);

        foreach (['{"mode":"watch"', '{"mode":"other","updated_at":"2026-11-13T00:00:00Z","last24h":{}}', str_repeat('x', 5000)] as $broken) {
            file_put_contents($this->path, $broken);
            $this->assertSame($down, EgressWatch::present($now)['issues'], '読めない・形が違う・大きすぎる');
        }

        $this->state('watch', $now, ['refused' => -1]);
        $this->assertSame($down, EgressWatch::present($now)['issues'], '数が負');

        unlink($this->path);
        $this->assertSame($down, EgressWatch::present($now)['issues'], 'ファイルが無い');
    }

    public function test_without_the_proxy_there_is_no_badge_or_issue(): void
    {
        $now = CarbonImmutable::now();
        config(['egress.proxy' => null]);
        unlink($this->path);

        $this->assertSame(['health' => [], 'issues' => []], EgressWatch::present($now), '開発の形');

        // 代理を使わない形（off）: 代理は動いて数を書くが、アプリは通らない
        $this->state('watch', $now, ['unlisted' => 9]);
        $result = EgressWatch::present($now);
        $this->assertSame([], $result['issues']);
        $this->assertSame('off', $result['health']['egress']['mode']);
    }
}
