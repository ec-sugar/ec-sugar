<?php

declare(strict_types=1);

namespace App\Core\Event\Console;

use App\Core\Event\Outbox\EventRelay;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * 流し損ねた記録を受け手へ流す見回り（ADR 0089）。店に 1 つずつ入り、受け手ごとの印から先と、届け直しを待つ記録を流す。
 *
 *   events:relay                   全部の店（毎分。routes/console.php）
 *   events:relay --tenant=店の ID   その店だけ
 *   events:relay --resume          止めた記録も待ちに戻してから流す（直した後に。管理画面の「流し直す」と同じ）
 */
final class RelayEventsCommand extends Command
{
    protected $signature = 'events:relay
        {--tenant= : この店だけ}
        {--resume : 止めた記録も待ちに戻してから流す}';

    protected $description = '届け損ねた記録（注文のお知らせなど）を、店ごとにイベントの処理へ届け直す';

    public function handle(EventRelay $relay): int
    {
        $only = $this->option('tenant');

        if ($only !== null && preg_match('/\A[a-z0-9][a-z0-9_-]{0,62}\z/', $only) !== 1) {
            $this->error('店の ID の形が違います。');

            return self::INVALID;
        }

        $resume = (bool) $this->option('resume');
        $seen = 0;
        $failed = 0;
        $rebuilding = 0;
        $totals = ['delivered' => 0, 'failed' => 0, 'stopped' => 0, 'held' => 0, 'skipped' => 0];

        foreach (Tenant::query()->when($only !== null, static fn ($query) => $query->whereKey($only))->orderBy('id')->cursor() as $tenant) {
            $seen++;

            // 店の DB を作り直している最中の店（デモの店の毎晩の戻し）には入らない。DB や表が無くて落ちるのを、失敗に数えない（ログに 1 行）。
            // 作り直しの後の最初のコミットと次の見回りが、その店の記録を流す
            if ($tenant->rebuildingNow()) {
                $rebuilding++;

                continue;
            }

            // 1 店が落ちても（DB が無い・マイグレーションが足りない）、後ろの店は続ける
            try {
                tenancy()->initialize($tenant);

                if ($resume) {
                    $relay->resume();
                }

                foreach ($relay->run() ?? [] as $key => $count) {
                    $totals[$key] += $count;
                }
            } catch (Throwable $e) {
                report($e);
                $failed++;
            } finally {
                tenancy()->end();
            }
        }

        if ($only !== null && $seen === 0) {
            $this->error('その店はありません。');

            return self::FAILURE;
        }

        $this->info("届けた {$totals['delivered']}・落ちて待ち {$totals['failed']}・止めた {$totals['stopped']}・順番待ち {$totals['held']}・届けない {$totals['skipped']}（届けられなかった店 {$failed}・作り直し中で飛ばした店 {$rebuilding}）");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
