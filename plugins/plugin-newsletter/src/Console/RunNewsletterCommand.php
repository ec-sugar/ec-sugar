<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter\Console;

use App\Core\Plugin\PluginTenants;
use Carbon\CarbonImmutable;
use EcSugar\PluginNewsletter\NewsletterDispatcher;
use EcSugar\PluginNewsletter\NewsletterSubscribers;
use Illuminate\Console\Command;
use Throwable;

/**
 * メルマガ・一斉メールの 1 分ごとの処理。使う店だけを回る（PluginTenants）。何度流しても同じ結果（2 通は送らない）。
 * 予約の日時が来た配信を始め、配信中の配信を 1 分の上限まで送り、終わった配信を閉じ、確かめの期限切れと止めた人の古いメールを片付ける
 */
final class RunNewsletterCommand extends Command
{
    protected $signature = 'newsletter:run';

    protected $description = 'メルマガ・一斉メール: 予約の配信を始め、配信中のメールを 1 分の上限まで送り、確認の期限切れと止めた人の古いメールを片付ける';

    public function handle(PluginTenants $tenants): int
    {
        $total = ['started' => 0, 'queued' => 0, 'finished' => 0, 'lost' => 0, 'expired' => 0, 'erased' => 0];
        $tenants->each('newsletter', function () use (&$total): void {
            // 1 店が落ちても（DB が無い・移行が足りない）、後ろの店は続ける
            try {
                $now = CarbonImmutable::now();
                foreach ([...app(NewsletterDispatcher::class)->run($now), ...app(NewsletterSubscribers::class)->prune($now)] as $key => $count) {
                    $total[$key] += $count;
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
