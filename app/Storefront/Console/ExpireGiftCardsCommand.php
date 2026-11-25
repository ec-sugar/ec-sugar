<?php

declare(strict_types=1);

namespace App\Storefront\Console;

use App\Core\GiftCard\Services\GiftCardService;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 有効期限を過ぎたギフトカードの残高を、店ごとに期限切れにする（ADR 0076）。毎日動かす（routes/console.php）。
 * 期限を過ぎたカードはこれより前でも使えない（使う時に期限を確かめる）。ここでは帳簿に「期限切れ」を書いて、未使用の残高の合計から外す。
 * 同時に 2 つ走っても、カードごとに台帳のロックの中で確かめ直すので、消すのは 1 回だけ。App をやめた店のカードも同じ
 */
final class ExpireGiftCardsCommand extends Command
{
    protected $signature = 'gift-cards:expire';

    protected $description = '有効期限を過ぎたギフトカードの残高を店ごとに期限切れにする';

    public function handle(): int
    {
        $total = 0;
        $now = CarbonImmutable::now();

        // 店の DB を作り直している最中の店には入らない（Tenant::serving）
        tenancy()->runForMultiple(Tenant::serving(), function () use (&$total, $now): void {
            $total += app(GiftCardService::class)->expireDue($now, 'system:gift-cards');
        });

        $this->info("有効期限を過ぎたギフトカードを {$total} 枚期限切れにしました");

        return self::SUCCESS;
    }
}
