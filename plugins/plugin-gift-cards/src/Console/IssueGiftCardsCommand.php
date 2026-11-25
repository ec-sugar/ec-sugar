<?php

declare(strict_types=1);

namespace EcSugar\PluginGiftCards\Console;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use EcSugar\PluginGiftCards\GiftCardIssuer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * ギフトカードの見回り（ADR 0076。1 時間ごと）: 払われたのに発行していない注文を発行し、コードのメールが届いていないカードを出し直して送り直す。
 * App をやめた店でも、払われた注文には発行する（お金を受け取った約束を守る）
 */
final class IssueGiftCardsCommand extends Command
{
    protected $signature = 'gift-cards:issue';

    protected $description = '払われたギフトカードの注文の発行と、届かなかったコードの送り直し';

    public function handle(): int
    {
        $issued = 0;
        $resent = 0;
        $now = CarbonImmutable::now();

        // 店の DB を作り直している最中の店には入らない（Tenant::serving）
        tenancy()->runForMultiple(Tenant::serving(), function () use (&$issued, &$resent, $now): void {
            // App の表がある店だけ
            if (! Schema::hasTable('gift_cards_orders')) {
                return;
            }

            $done = app(GiftCardIssuer::class)->catchUp($now);
            $issued += $done['issued'];
            $resent += $done['resent'];
        });

        $this->info("ギフトカードを {$issued} 枚発行し、{$resent} 枚のコードを送り直しました");

        return self::SUCCESS;
    }
}
