<?php

declare(strict_types=1);

namespace EcSugar\PluginFavorites\Console;

use App\Core\Plugin\PluginTenants;
use Carbon\CarbonImmutable;
use EcSugar\PluginFavorites\FavoriteNotices;
use Illuminate\Console\Command;
use Throwable;

/**
 * お気に入りの値下げ・再入荷の知らせ（毎日 1 回）。使う店だけを回る（PluginTenants）。同じ日に何度流しても、1 人に 2 通は送らない
 */
final class RunFavoritesCommand extends Command
{
    protected $signature = 'favorites:notify';

    protected $description = 'お気に入り: 前の日と比べて値下げ・再入荷した商品を、お知らせを選んだ会員に 1 日 1 通で知らせる';

    public function handle(PluginTenants $tenants): int
    {
        $total = ['changed' => 0, 'queued' => 0];
        $tenants->each('favorites', function () use (&$total): void {
            // 1 店が落ちても（DB が無い・移行が足りない）、後ろの店は続ける
            try {
                $result = app(FavoriteNotices::class)->run(CarbonImmutable::now());
                $total['changed'] += $result['changed'];
                $total['queued'] += $result['queued'];
            } catch (Throwable $e) {
                report($e);
                $total['error'] = ($total['error'] ?? 0) + 1;
            }
        });

        $this->info(json_encode($total, JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
