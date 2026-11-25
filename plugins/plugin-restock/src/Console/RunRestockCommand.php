<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock\Console;

use App\Core\Plugin\PluginTenants;
use Carbon\CarbonImmutable;
use EcSugar\PluginRestock\RestockNotifier;
use EcSugar\PluginRestock\RestockRequests;
use Illuminate\Console\Command;
use Throwable;

/**
 * 再入荷のお知らせの定期チェック（1 時間ごと）。使う店だけを回る（PluginTenants）。何度流しても同じ結果。
 * 入荷していれば待っている登録に知らせ（在庫の操作の知らせを取りこぼした時・商品を公開し直した時の分）、確かめの期限の切れた登録を消す
 */
final class RunRestockCommand extends Command
{
    protected $signature = 'restock:run';

    protected $description = '再入荷のお知らせ: 入荷した種類を待っている登録に知らせ、確認の期限の切れた登録を消す';

    public function handle(PluginTenants $tenants): int
    {
        $total = ['notified' => 0, 'pruned' => 0];
        $tenants->each('restock', function () use (&$total): void {
            // 1 店が落ちても（DB が無い・移行が足りない）、後ろの店は続ける
            try {
                $total['notified'] += app(RestockNotifier::class)->sweep();
                $total['pruned'] += app(RestockRequests::class)->prune(CarbonImmutable::now());
            } catch (Throwable $e) {
                report($e);
                $total['error'] = ($total['error'] ?? 0) + 1;
            }
        });

        $this->info(json_encode($total, JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
