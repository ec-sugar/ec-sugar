<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Services\ShopDigests;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * 運営全体の画面の数を集める（ADR 0042）。店に 1 つずつ入り、店の文脈の中で数えて中央に書く（ShopDigests）。
 *
 *   operations:collect                   今月と 30 日の動きの分を数え直す（10 分ごと。routes/console.php）
 *   operations:collect --verify          記録の改竄の検証もする（全件を読むので 1 日 1 回）
 *   operations:collect --rebuild         全部の日を数え直す（入れた直後・数え方を変えた後。記録の検証もする）
 *   operations:collect --tenant=店の ID   その店だけ
 */
final class CollectOperationsCommand extends Command
{
    protected $signature = 'operations:collect
        {--rebuild : 全部の日を数え直す（記録の検証もする）}
        {--verify : 記録の改竄の検証もする}
        {--tenant= : この店だけ}';

    protected $description = '運営全体の画面の数（日ごとの注文と売上・要対応）を、店ごとに数えて中央に書く';

    public function handle(ShopDigests $digests): int
    {
        $only = $this->option('tenant');

        if ($only !== null && preg_match('/\A[a-z0-9][a-z0-9_-]{0,62}\z/', $only) !== 1) {
            $this->error('店の ID の形が違います。');

            return self::INVALID;
        }

        $rebuild = (bool) $this->option('rebuild');
        $verify = $rebuild || (bool) $this->option('verify');
        $now = CarbonImmutable::now();
        $done = 0;
        $failed = 0;
        $rebuilding = 0;

        $tenants = Tenant::query()->when($only !== null, static fn ($query) => $query->whereKey($only))->orderBy('id')->cursor();

        foreach ($tenants as $tenant) {
            // 店の DB を作り直している最中の店（デモの店の毎晩の戻し）には入らない。失敗に数えず、困りごとの印も付けない（ログに 1 行）
            if ($tenant->rebuildingNow()) {
                $rebuilding++;

                continue;
            }

            // 1 店が落ちても（DB が無い・マイグレーションが足りない）、後ろの店は続ける。落ちた店は中央に印を付ける（画面の困りごとに出る）
            try {
                tenancy()->initialize($tenant);
                $digests->collect($now, all: $rebuild, verify: $verify);
                $done++;
            } catch (Throwable $e) {
                report($e);
                $failed++;

                try {
                    ShopDigests::recordFailure((string) $tenant->getTenantKey(), $now);
                } catch (Throwable $inner) {
                    report($inner);
                }
            } finally {
                tenancy()->end();
            }
        }

        if ($only !== null && $done + $failed + $rebuilding === 0) {
            $this->error('その店はありません。');

            return self::FAILURE;
        }

        $this->info("集めた店 {$done}、集められなかった店 {$failed}、作り直し中で飛ばした店 {$rebuilding}".($rebuild ? '（全部の日を数え直しました）' : ''));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
