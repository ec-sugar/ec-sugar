<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Agent\AgentProposals;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 7 日を過ぎた AI エージェントの待ちの案を、店ごとに期限切れにする（ADR 0101）。1 時間ごとに動かす（routes/console.php）。
 * 期限の過ぎた案はこれより前でも承認できない（承認の時に期限を確かめる）。ここでは状態を expired に書き、記録を残す。
 * 同時に 2 つ走っても、案ごとに連鎖のロックと行のロックの中で確かめ直すので、書くのは 1 回だけ
 */
final class ExpireAgentProposalsCommand extends Command
{
    protected $signature = 'agent:expire-proposals';

    protected $description = '7 日を過ぎた AI エージェントの案を店ごとに期限切れにする';

    public function handle(): int
    {
        $total = 0;
        $now = CarbonImmutable::now();

        // 店の DB を作り直している最中の店には入らない（Tenant::serving）
        tenancy()->runForMultiple(Tenant::serving(), function () use (&$total, $now): void {
            $total += app(AgentProposals::class)->expireDue($now);
        });

        $this->info("期限の過ぎた AI エージェントの案を {$total} 件期限切れにしました");

        return self::SUCCESS;
    }
}
