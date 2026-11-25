<?php

declare(strict_types=1);

namespace App\Storefront\Console;

use App\Models\Tenant;
use App\Storefront\Cart\CartService;
use Illuminate\Console\Command;

/**
 * 期限の切れたカート（最後の操作から 30 日）を、店ごとに消す（ADR 0021）。毎日動かす（routes/console.php）。
 */
final class PruneCartsCommand extends Command
{
    protected $signature = 'storefront:prune-carts';

    protected $description = '期限の切れたカートを店ごとに消す';

    public function handle(): int
    {
        $total = 0;

        // 店の DB を作り直している最中の店には入らない（Tenant::serving）
        tenancy()->runForMultiple(Tenant::serving(), function () use (&$total): void {
            $total += app(CartService::class)->prune();
        });

        $this->info("期限の切れたカートを {$total} 件消しました");

        return self::SUCCESS;
    }
}
