<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 1 通ごとの送った記録（配信 × 購読者）。メールは持たない（購読者の ID だけ）。停止のリンクの合い言葉はハッシュだけ。
 * status: queued（キューに積んだ）→ sending（送っている）→ sent・failed・skipped（止めた配信・もう届けない人）
 *
 * @property string $id
 * @property string $campaign_id
 * @property string $subscriber_id
 * @property string $status
 * @property ?string $token_hash
 * @property ?CarbonImmutable $sent_at
 * @property ?CarbonImmutable $unsubscribed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class NewsletterDelivery extends Model
{
    use HasUlids;

    public const string QUEUED = 'queued';

    public const string SENDING = 'sending';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    protected $table = 'newsletter_deliveries';

    protected $fillable = ['campaign_id', 'subscriber_id', 'status', 'token_hash', 'sent_at', 'unsubscribed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
