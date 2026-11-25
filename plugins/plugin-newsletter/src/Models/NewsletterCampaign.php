<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter\Models;

use Carbon\CarbonImmutable;
use EcSugar\PluginNewsletter\CampaignStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 配信（題と本文。文だけで HTML は使わない）。書くのは NewsletterCampaigns と配信の係（NewsletterDispatcher）だけ。
 * どちらも連鎖のロックの後に行のロックを取り、同じトランザクションで記録（イベント）を残す
 *
 * @property string $id
 * @property string $subject
 * @property string $body
 * @property CampaignStatus $status
 * @property ?CarbonImmutable $scheduled_at
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class NewsletterCampaign extends Model
{
    use HasUlids;

    protected $table = 'newsletter_campaigns';

    protected $fillable = ['subject', 'body', 'status', 'scheduled_at', 'started_at', 'finished_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
