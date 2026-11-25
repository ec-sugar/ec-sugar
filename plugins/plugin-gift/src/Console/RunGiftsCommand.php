<?php

declare(strict_types=1);

namespace EcSugar\PluginGift\Console;

use App\Core\Plugin\PluginTenants;
use Carbon\CarbonImmutable;
use EcSugar\PluginGift\GiftRounds;
use Illuminate\Console\Command;
use Throwable;

/**
 * 贈り物の毎時の処理（ADR 0055）。贈り物を使う店を回る（PluginTenants）。何度流しても同じ結果。
 * 使うのをやめた店でも、受け取り待ちなどの贈り物が残っていれば回す（期限の知らせと期限での返金を続け、お金を宙に浮かせない。受け取りの画面は止まる）
 */
final class RunGiftsCommand extends Command
{
    protected $signature = 'gifts:run';

    protected $description = '贈り物を進める（リンクの作り直し・期限の通知・期限を過ぎた贈り物の取り消しと返金）';

    public function handle(PluginTenants $tenants): int
    {
        $total = [];
        $tenants->each('gift', function () use (&$total): void {
            // 1 店が落ちても（DB が無い・移行が足りない）、後ろの店は続ける
            try {
                foreach (app(GiftRounds::class)->run(CarbonImmutable::now()) as $what => $n) {
                    $total[$what] = ($total[$what] ?? 0) + $n;
                }
            } catch (Throwable $e) {
                report($e);
                $total['error'] = ($total['error'] ?? 0) + 1;
            }
        }, static fn (): bool => GiftRounds::hasLeftover());

        $this->info(json_encode($total, JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
