<?php

declare(strict_types=1);

namespace App\Core\RateLimit;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Redis\Connections\PhpRedisConnection;

/**
 * 同時の要求でも数えを落とさない RateLimiter（ADR 0081）。核の Throttle と、経路の throttle ミドルウェアの両方がこれで数える
 * （AppServiceProvider が Laravel の RateLimiter と差し替える）。
 *
 * Laravel の increment は「無ければ 0 を置く → 足す → 自分で置いていないのに 1 なら、窓が切れたとみなして 1 を置き直す」の 3 手で、
 * 窓の始まりに同時に来ると、ほかの要求が足した数を「1 を置き直す」で消してしまう（40 の処理から同時に数えると、上限 5 で 9 つ通った。で見つけた）。
 *
 * - Redis の置き場（本番）: 足すのと窓の期限を付けるのを、1 つの Lua の中で（ほかの要求が間に入らない）
 * - ほかの置き場: 置き場の錠（キーごと）の中で Laravel の数え方をする（開発・テストの array と database）
 *
 * 数えの形（期限つきの数と「:timer」の窓の終わり）は Laravel と同じなので、attempts・availableIn・clear・tooManyAttempts はそのまま使える
 */
final class AtomicRateLimiter extends RateLimiter
{
    /** 足して、1 回目（か期限の無い数え）なら窓の期限と終わりの時刻を付ける */
    private const string INCREMENT = <<<'LUA'
        local hits = redis.call('INCRBY', KEYS[1], ARGV[3])
        if hits == tonumber(ARGV[3]) or redis.call('TTL', KEYS[1]) == -1 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
            redis.call('SET', KEYS[2], ARGV[2], 'EX', ARGV[1])
        end
        return hits
        LUA;

    /** 錠を待つ秒（ほかの置き場だけ） */
    private const int LOCK_WAIT = 3;

    public function increment($key, $decaySeconds = 60, $amount = 1)
    {
        $store = $this->cache->getStore();
        $seconds = (int) max(1, $decaySeconds);

        if ($store instanceof RedisStore) {
            $key = $this->cleanRateLimiterKey($key);
            $prefix = $store->getPrefix();

            $arguments = [$prefix.$key, $prefix.$key.':timer', $seconds, $this->availableAt($seconds), (int) $amount];
            $connection = $store->connection();

            // phpredis と Predis で eval の引数の並びが違う（Laravel の RedisStore と同じ道で呼ぶ）
            return (int) ($connection instanceof PhpRedisConnection
                ? $connection->command('eval', [self::INCREMENT, $arguments, 2])
                : $connection->command('eval', [self::INCREMENT, 2, ...$arguments]));
        }

        if ($store instanceof LockProvider) {
            return (int) $store->lock('rate-limit-lock:'.$this->cleanRateLimiterKey($key), self::LOCK_WAIT)
                ->block(self::LOCK_WAIT, fn (): int => parent::increment($key, $seconds, $amount));
        }

        return parent::increment($key, $seconds, $amount);
    }
}
