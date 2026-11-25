<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter;

/**
 * 配信の状態。draft（下書き）→ scheduled（予約）→ sending（配信中）→ sent（配信済み）。配信中に止めると stopped
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Stopped = 'stopped';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Scheduled => '予約',
            self::Sending => '配信中',
            self::Sent => '配信済み',
            self::Stopped => '中止',
        };
    }

    /**
     * 管理画面の状態の札の色（核の色の種類）
     */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Scheduled, self::Sending => 'warn',
            self::Sent => 'good',
            self::Stopped => 'bad',
        };
    }
}
