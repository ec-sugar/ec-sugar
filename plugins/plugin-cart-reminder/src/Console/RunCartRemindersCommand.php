<?php

declare(strict_types=1);

namespace EcSugar\PluginCartReminder\Console;

use App\Core\Plugin\PluginTenants;
use Carbon\CarbonImmutable;
use EcSugar\PluginCartReminder\CartReminders;
use Illuminate\Console\Command;
use Throwable;

/**
 * カゴ落ちのお知らせの毎時の処理（ADR 0067）。この App を使う店だけを回る（PluginTenants。使うのをやめた店では送らない）。何度流しても同じ結果
 */
final class RunCartRemindersCommand extends Command
{
    protected $signature = 'cart-reminders:run';

    protected $description = '払われずに消えたショップの注文へ、カゴ落ちのお知らせを積む（送るかはお知らせごとに決める）';

    public function handle(PluginTenants $tenants): int
    {
        $total = [];
        $tenants->each('cart-reminder', function () use (&$total): void {
            // 1 店が落ちても（DB が無い・移行が足りない）、後ろの店は続ける
            try {
                foreach (app(CartReminders::class)->run(CarbonImmutable::now()) as $what => $n) {
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
