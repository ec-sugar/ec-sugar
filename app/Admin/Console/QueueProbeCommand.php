<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Jobs\QueueProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * キューが本当に回るかを確かめる（「健全の確かめにキューの見本の仕事」）。HTTP の入口は無く、ホストの ec-sugar が
 * 更新の確かめ（ec_health）で `docker compose exec app php artisan operations:queue-probe` として呼ぶ。
 *
 * 見本の仕事を 1 つ載せ、キューの処理（queue のコンテナ）がそれを片付けるまで待つ。時間内に片付けば 0、片付かなければ 1 で終わる
 * （新しいバージョンのキューの処理が起きない・仕事を読めない時に、更新を前のバージョンへ戻す）
 */
final class QueueProbeCommand extends Command
{
    protected $signature = 'operations:queue-probe {--timeout=90 : 待つ秒数（1〜600）}';

    protected $description = 'キューの処理が見本の仕事を片付けるかを確認する（更新の確認から）';

    public function handle(): int
    {
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 600]]);

        if ($timeout === false) {
            $this->error('--timeout は 1〜600 の秒数にしてください');

            return self::INVALID;
        }

        $token = bin2hex(random_bytes(16));
        QueueProbe::dispatch($token);
        $deadline = microtime(true) + $timeout;

        do {
            if (Cache::pull(QueueProbe::key($token)) === true) {
                $this->info('キューの処理が見本の仕事を片付けました');

                return self::SUCCESS;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        $this->error("キューの処理が {$timeout} 秒のうちに見本の仕事を片付けませんでした");

        return self::FAILURE;
    }
}
