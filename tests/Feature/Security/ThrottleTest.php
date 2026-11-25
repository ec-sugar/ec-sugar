<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\RateLimit\AtomicRateLimiter;
use App\Core\RateLimit\Throttle;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 回数の上限の核の部品（ADR 0081）。どの機能の回数の上限もここを通るので、部品の誤りは全部へ広がる。
 * 上限ちょうど・1 つ越え・窓の切り替わりの前後・同時の要求・キーごと・店ごと・狭い上限から数えて止める・戻す、を確かめる
 */
final class ThrottleTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_it_lets_exactly_the_limit_through_and_refuses_the_next(): void
    {
        $results = array_map(static fn (): bool => Throttle::allow('probe|exact', 3, 60), range(1, 4));

        $this->assertSame([true, true, true, false], $results);
        // 断った回も数える（断られ続けても窓は延びない。数は 4）
        $this->assertSame(4, RateLimiter::attempts('probe|exact'));
    }

    public function test_the_window_opens_again_only_when_it_has_passed_since_the_first_attempt(): void
    {
        // 秒の頭にそろえる（窓の終わりは秒で数える）
        $this->freezeSecond();
        $this->assertTrue(Throttle::allow('probe|window', 2, 60));
        $this->travel(30)->seconds();
        $this->assertTrue(Throttle::allow('probe|window', 2, 60));
        $this->assertFalse(Throttle::allow('probe|window', 2, 60));

        // 最初の 1 回から 59 秒: まだ同じ窓（断った回で窓は延びない）
        $this->travel(29)->seconds();
        $this->assertFalse(Throttle::allow('probe|window', 2, 60));
        $this->assertGreaterThanOrEqual(1, Throttle::availableIn('probe|window'));

        // 60 秒: 新しい窓。また上限まで通る
        $this->travel(1)->seconds();
        $this->assertTrue(Throttle::allow('probe|window', 2, 60));
        $this->assertTrue(Throttle::allow('probe|window', 2, 60));
        $this->assertFalse(Throttle::allow('probe|window', 2, 60));
    }

    public function test_different_keys_are_counted_apart(): void
    {
        $this->assertTrue(Throttle::allow('probe|a', 1, 60));
        $this->assertFalse(Throttle::allow('probe|a', 1, 60));
        $this->assertTrue(Throttle::allow('probe|b', 1, 60));
    }

    public function test_shop_keys_count_each_shop_apart_and_cannot_be_made_outside_a_shop(): void
    {
        $this->tenant('tha');
        $this->tenant('thb');

        $first = $this->inside('tha', fn (): array => [Throttle::shopKey('probe', 'same'), Throttle::allow(Throttle::shopKey('probe', 'same'), 1, 60), Throttle::allow(Throttle::shopKey('probe', 'same'), 1, 60)]);
        $second = $this->inside('thb', fn (): array => [Throttle::shopKey('probe', 'same'), Throttle::allow(Throttle::shopKey('probe', 'same'), 1, 60)]);

        $this->assertSame(['probe|tha|same', true, false], $first);
        // ほかの店の同じ名前・同じ部分は別に数える（tha が使い切っても thb は通る）
        $this->assertSame(['probe|thb|same', true], $second);

        $this->expectException(\LogicException::class);
        Throttle::shopKey('probe', 'same');
    }

    public function test_several_limits_are_counted_from_the_narrowest_and_stop_at_the_first_over(): void
    {
        $limits = [['probe|pair', 2, 3600], ['probe|source', 3, 3600], ['probe|email', 10, 86_400]];

        $results = array_map(static fn (): ?string => Throttle::firstOver($limits), range(1, 4));
        $this->assertSame([null, null, 'probe|pair', 'probe|pair'], $results);

        // 狭い上限で断った分は、後ろの広い上限（メールごと）を使わない（1 つの接続元が断られ続けても、その人のメールの枠を使い切らせない）
        $this->assertSame(4, RateLimiter::attempts('probe|pair'));
        $this->assertSame(2, RateLimiter::attempts('probe|source'));
        $this->assertSame(2, RateLimiter::attempts('probe|email'));

        // 接続元の上限は、ほかの組でも数える
        $this->assertNull(Throttle::firstOver([['probe|pair2', 2, 3600], ['probe|source', 3, 3600], ['probe|email', 10, 86_400]]));
        $this->assertSame('probe|source', Throttle::firstOver([['probe|pair3', 2, 3600], ['probe|source', 3, 3600], ['probe|email', 10, 86_400]]));
        $this->assertSame(3, RateLimiter::attempts('probe|email'));
    }

    public function test_a_refund_gives_back_one_counted_attempt_and_never_goes_below_zero(): void
    {
        $this->assertTrue(Throttle::allow('probe|refund', 1, 60));
        Throttle::refund('probe|refund', 60);
        $this->assertTrue(Throttle::allow('probe|refund', 1, 60));
        $this->assertFalse(Throttle::allow('probe|refund', 1, 60));

        Throttle::refund('probe|empty', 60);
        $this->assertSame(0, RateLimiter::attempts('probe|empty'));
    }

    public function test_route_throttles_and_the_part_count_with_the_same_atomic_limiter(): void
    {
        // 経路の throttle ミドルウェアも、核の Throttle と同じ数え方（窓の始まりの同時の要求で数えを落とさない）
        $this->assertInstanceOf(AtomicRateLimiter::class, app(CacheRateLimiter::class));
        $this->assertNotNull(RateLimiter::limiter('storefront-cart'), '経路の上限の定義は差し替えた後にも残る');
    }

    public function test_it_refuses_meaningless_limits(): void
    {
        foreach ([['', 1, 60], ['probe', 0, 60], ['probe', 1, 0]] as [$key, $max, $seconds]) {
            try {
                Throttle::allow($key, $max, $seconds);
                $this->fail("{$key}/{$max}/{$seconds} を受けた");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * 同時の要求（本物の Redis に、別々の処理から同じ瞬間に数える）。通るのは上限の数だけ。
     * 「見てから数える」形に戻すと、上限の手前で同時に来た要求がどれも「まだ」と見て通り、このテストが落ちる
     */
    public function test_concurrent_requests_never_get_past_the_limit(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl が無い');
        }

        // 本番と同じく Redis の置き場で数える（数えるのは差し替えた AtomicRateLimiter）
        config(['cache.limiter' => 'redis']);
        $this->app->forgetInstance(CacheRateLimiter::class);
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);
        $this->assertInstanceOf(AtomicRateLimiter::class, app(CacheRateLimiter::class));
        $store = Cache::store('redis');
        $key = 'probe-concurrent|'.Str::random(16);
        $passed = $key.'|passed';
        $children = 40;
        $max = 5;

        // 子は親の Redis の接続を使わない（それぞれ繋ぎ直す）。親も分ける前に閉じておく
        Redis::purge('cache');
        $start = microtime(true) + 1.0;
        $pids = [];

        for ($i = 0; $i < $children; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                Redis::purge('cache');
                time_sleep_until($start);

                if (Throttle::allow($key, $max, 60)) {
                    $store->increment($passed);
                }

                // 親のテストの後始末（DB の接続を閉じるなど）を子で走らせない
                posix_kill(posix_getpid(), SIGKILL);
            }

            $this->assertGreaterThan(0, $pid, '子の処理を作れなかった');
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        try {
            $this->assertSame($max, (int) $store->get($passed), '同時の要求が上限を越えて通った');
            $this->assertSame($children, (int) RateLimiter::attempts($key), 'どの要求も 1 回に数える');
            // 窓の期限と終わりの時刻も付いている（期限の無い数えで締め出し続けない）
            $this->assertGreaterThan(0, Throttle::availableIn($key));
            $this->assertFalse(Throttle::allow($key, $max, 60));
        } finally {
            RateLimiter::clear($key);
            $store->forget($passed);
        }
    }
}
