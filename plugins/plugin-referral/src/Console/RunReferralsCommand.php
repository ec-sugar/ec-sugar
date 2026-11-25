<?php

declare(strict_types=1);

namespace EcSugar\PluginReferral\Console;

use App\Core\Plugin\PluginTenants;
use EcSugar\PluginReferral\Referrals;
use Illuminate\Console\Command;
use Throwable;

/**
 * 友だち紹介の毎時の取りこぼしの拾い（ADR 0075）。この App を使う店だけを回る（PluginTenants）。
 * 払われたのに結んでいない・発送したのに決めていない・閉じたのに残っている紹介を進める。何度流しても同じ結果
 */
final class RunReferralsCommand extends Command
{
    protected $signature = 'referral:run';

    protected $description = '友だち紹介の取りこぼし（払われた・発送した・取り消した注文の通知を受け損ねた紹介）を進める';

    public function handle(PluginTenants $tenants): int
    {
        $total = [];
        $tenants->each('referral', function () use (&$total): void {
            // 1 店が落ちても（DB が無い・移行が足りない）、後ろの店は続ける
            try {
                foreach (app(Referrals::class)->sweep() as $what => $n) {
                    $total[$what] = ($total[$what] ?? 0) + $n;
                }
            } catch (Throwable $e) {
                report($e);
                $total['error'] = ($total['error'] ?? 0) + 1;
            }
        });

        $this->info(json_encode($total, JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
