<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks\Console;

use App\Core\Plugin\PluginTenants;
use Carbon\CarbonImmutable;
use EcSugar\PluginMemberRanks\Ranks;
use EcSugar\PluginMemberRanks\RankSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * 会員ランクの毎日の決め直し（ADR 0079）。使う店だけを回る（PluginTenants）。同じ日に何度流しても、変わるのは 1 回だけ
 */
final class RunRanksCommand extends Command
{
    protected $signature = 'member-ranks:run';

    protected $description = '会員ランク: 直近 12 か月の購入額で会員のランクを決め直し、上がった会員に知らせる';

    public function handle(PluginTenants $tenants): int
    {
        $total = ['up' => 0, 'down' => 0];
        $tenants->each(RankSettings::PLUGIN, function () use (&$total): void {
            // 1 店が落ちても（DB が無い・移行が足りない）、後ろの店は続ける
            try {
                $result = app(Ranks::class)->run(CarbonImmutable::now());
                $total['up'] += $result['up'];
                $total['down'] += $result['down'];
            } catch (Throwable $e) {
                report($e);
                $total['error'] = ($total['error'] ?? 0) + 1;
            }
        });

        $this->info(json_encode($total, JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
