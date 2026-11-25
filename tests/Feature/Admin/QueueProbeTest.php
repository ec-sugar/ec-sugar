<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Jobs\QueueProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 更新の確かめのキューの見本の仕事。片付いた時だけ 0、片付かない・値が違う時は 0 以外
 */
final class QueueProbeTest extends TestCase
{
    public function test_it_passes_when_the_queue_runs_the_job(): void
    {
        // テストのキューはその場で処理する（sync）
        $this->artisan('operations:queue-probe', ['--timeout' => 5])->assertExitCode(0);
    }

    public function test_it_fails_when_nothing_runs_the_job(): void
    {
        Queue::fake();

        $this->artisan('operations:queue-probe', ['--timeout' => 1])->assertExitCode(1);
        Queue::assertPushed(QueueProbe::class, static fn (QueueProbe $job): bool => preg_match('/\A[0-9a-f]{32}\z/', $job->token) === 1);
    }

    public function test_a_job_from_an_earlier_probe_does_not_count(): void
    {
        // 前の回の印が残っていても、今回の印でなければ通らない
        Queue::fake();
        Cache::put(QueueProbe::key(str_repeat('a', 32)), true, 600);

        $this->artisan('operations:queue-probe', ['--timeout' => 1])->assertExitCode(1);
    }

    public function test_the_timeout_must_be_a_sane_number(): void
    {
        foreach (['0', '601', 'abc', '-5'] as $bad) {
            $this->artisan('operations:queue-probe', ['--timeout' => $bad])->assertExitCode(2);
        }
    }

    public function test_the_job_ignores_a_malformed_token(): void
    {
        (new QueueProbe('../../x'))->handle();

        $this->assertFalse(Cache::has(QueueProbe::key('../../x')));
    }
}
