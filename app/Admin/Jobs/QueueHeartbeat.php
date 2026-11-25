<?php

declare(strict_types=1);

namespace App\Admin\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * キューの脈。定期の処理が 5 分ごとに必ず出す（止まっている間も。小さいので溜まっても困らない）。
 * 印は「まだ片付いていない最も古い脈を出した時刻」。それと同じかより後の脈が片付けば、キューは動いているので印を消す。
 * 印が 10 分より古いまま残っていれば、キューの処理が止まっている（運営の様子の queue_stopped。待ちが少ない店でも気づけるように）。
 * 脈が 1 つ失われても（更新の切り替えの最中など）、次の脈が片付けば消えるので、誤った知らせは 5 分ほどで消える。
 * 載せるのは時刻だけで、店にも個人情報にも触れない
 */
final class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** まだ片付いていない最も古い脈を出した時刻（UNIX 秒）。片付いたら消える */
    public const string SENT = 'operations:queue-heartbeat:sent';

    public int $tries = 1;

    public function __construct(public readonly int $sentAt) {}

    /**
     * 出す（定期の処理から）。印は片付いていない脈が無い時だけ置く（最も古いものを残す）
     */
    public static function send(?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        Cache::add(self::SENT, $now->getTimestamp());
        self::dispatch($now->getTimestamp());
    }

    /**
     * 片付いていない脈を出した時刻（無ければ null）
     */
    public static function pendingSince(): ?CarbonImmutable
    {
        $sent = self::sent();

        return $sent === null ? null : CarbonImmutable::createFromTimestamp($sent);
    }

    /**
     * 置き場の印を整数で。Redis のキャッシュは数を文字で返す（数でない値だけを直列化して置くため）。テストの置き場は整数のまま
     */
    private static function sent(): ?int
    {
        $sent = Cache::get(self::SENT);

        if (is_int($sent)) {
            return $sent;
        }

        return is_string($sent) && preg_match('/\A[0-9]{1,12}\z/', $sent) === 1 ? (int) $sent : null;
    }

    public function handle(): void
    {
        // 印より前に出した脈（前に印を消した後に遅れて片付いたもの）では消さない
        $sent = self::sent();

        if ($sent !== null && $this->sentAt >= $sent) {
            Cache::forget(self::SENT);
        }
    }
}
