<?php

declare(strict_types=1);

namespace App\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * キューが本当に回るかの見本の仕事（operations:queue-probe）。載せるのは使い捨ての印だけで、店にも個人情報にも触れない。
 * 処理されたら印をキャッシュに置き、コマンドがそれを待つ
 */
final class QueueProbe implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $this->token) === 1) {
            Cache::put(self::key($this->token), true, 600);
        }
    }

    public static function key(string $token): string
    {
        return 'operations:queue-probe:'.$token;
    }
}
