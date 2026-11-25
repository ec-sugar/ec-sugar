<?php

declare(strict_types=1);

namespace EcSugar\PluginContact\Console;

use App\Core\Plugin\PluginTenants;
use Carbon\CarbonImmutable;
use EcSugar\PluginContact\ContactInquiries;
use EcSugar\PluginContact\ContactStatus;
use EcSugar\PluginContact\Models\ContactInquiry;
use Illuminate\Console\Command;
use Throwable;

/**
 * お問い合わせの片付け（毎日）。済んでから店の決めた期間（既定 1 年）がたったお問い合わせを、名前・メール・本文・返信ごと消す。
 * 使うのをやめた店でも、消す分が残っていれば回す（使わなくなった後に個人情報を残し続けない）。何度流しても同じ結果
 */
final class PruneContactCommand extends Command
{
    protected $signature = 'contact:prune';

    protected $description = 'お問い合わせ: 済んでから期間のたったお問い合わせを、名前・メール・本文・返信ごと消す';

    public function handle(PluginTenants $tenants): int
    {
        $total = ['purged' => 0];
        $tenants->each('contact', function () use (&$total): void {
            // 1 店が落ちても（DB が無い・移行が足りない）、後ろの店は続ける
            try {
                $total['purged'] += app(ContactInquiries::class)->purge(CarbonImmutable::now());
            } catch (Throwable $e) {
                report($e);
                $total['error'] = ($total['error'] ?? 0) + 1;
            }
        }, static fn (): bool => ContactInquiry::query()->where('status', ContactStatus::Closed->value)->exists());

        $this->info(json_encode($total, JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
