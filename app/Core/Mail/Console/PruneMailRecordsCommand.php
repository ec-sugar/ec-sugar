<?php

declare(strict_types=1);

namespace App\Core\Mail\Console;

use App\Core\Mail\MailRecords;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * 古いメールの記録（90 日より前に送ったもの）を、店ごとに消す（ADR 0095）。毎日動かす（routes/console.php）。
 */
final class PruneMailRecordsCommand extends Command
{
    protected $signature = 'mail-records:prune';

    protected $description = '古いメールの記録を店ごとに消す';

    public function handle(): int
    {
        $total = 0;

        // 店の DB を作り直している最中の店には入らない（Tenant::serving）
        tenancy()->runForMultiple(Tenant::serving(), function () use (&$total): void {
            $total += MailRecords::prune();
        });

        $this->info("古いメールの記録を {$total} 件消しました");

        return self::SUCCESS;
    }
}
