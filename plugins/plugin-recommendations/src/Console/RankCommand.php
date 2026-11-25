<?php

declare(strict_types=1);

namespace EcSugar\PluginRecommendations\Console;

use App\Core\Plugin\PluginTenants;
use Carbon\CarbonImmutable;
use EcSugar\PluginRecommendations\Rankings;
use Illuminate\Console\Command;
use Throwable;

/**
 * 売れ筋ランキングを数え直す（1 時間ごと）。使う店だけを回る（PluginTenants）。何度流しても同じ結果
 */
final class RankCommand extends Command
{
    protected $signature = 'recommendations:rank';

    protected $description = 'おすすめのセクション: 直近 30 日の払われた注文から、売れ筋ランキングを数え直す';

    public function handle(PluginTenants $tenants): int
    {
        $total = ['shops' => 0, 'ranked' => 0];
        $tenants->each('recommendations', function () use (&$total): void {
            // 1 店が落ちても（DB が無い・移行が足りない）、後ろの店は続ける
            try {
                $total['ranked'] += app(Rankings::class)->count(CarbonImmutable::now());
                $total['shops']++;
            } catch (Throwable $e) {
                report($e);
                $total['error'] = ($total['error'] ?? 0) + 1;
            }
        });

        $this->info(json_encode($total, JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
