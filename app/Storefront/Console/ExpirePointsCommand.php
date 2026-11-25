<?php

declare(strict_types=1);

namespace App\Storefront\Console;

use App\Core\Points\Services\PointService;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 最後に動いて（付く・使う・戻す・手で足し引き）から 1 年を過ぎたポイントを、店ごとに消す（ADR 0068 の 5）。毎日動かす（routes/console.php）。
 * 同時に 2 つ走っても、会員ごとに台帳のロックの中で確かめ直すので、消すのは 1 回だけ
 */
final class ExpirePointsCommand extends Command
{
    protected $signature = 'points:expire';

    protected $description = '期限を過ぎたポイントを店ごとに消す';

    public function handle(): int
    {
        $total = 0;
        $now = CarbonImmutable::now();

        // 店の DB を作り直している最中の店には入らない（Tenant::serving）
        tenancy()->runForMultiple(Tenant::serving(), function () use (&$total, $now): void {
            $total += app(PointService::class)->expireDue($now, 'system:points');
        });

        $this->info("期限を過ぎたポイントを {$total} 人分消しました");

        return self::SUCCESS;
    }
}
