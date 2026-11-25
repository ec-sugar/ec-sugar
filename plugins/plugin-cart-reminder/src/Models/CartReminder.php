<?php

declare(strict_types=1);

namespace EcSugar\PluginCartReminder\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 払われずに消えたショップの注文 1 つへのお知らせ（送った記録。ADR 0067）。書くのは CartReminders・SendCartReminder・CartRestore だけ。
 * 宛先のメールは持たない（送る時に注文の買う人の暗号化された値から読む）。合い言葉はハッシュだけ
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $status
 * @property ?string $reason
 * @property ?string $token_hash
 * @property ?string $unsubscribe_hash
 * @property ?CarbonImmutable $expires_at
 * @property ?CarbonImmutable $sent_at
 * @property ?CarbonImmutable $returned_at
 * @property ?string $recovered_transaction_id
 * @property ?CarbonImmutable $recovered_at
 * @property ?CarbonImmutable $unsubscribed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CartReminder extends Model
{
    use HasUlids;

    public const string QUEUED = 'queued';

    public const string SENDING = 'sending';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    /** 送らなかった理由と、管理画面に出す言葉 */
    public const array REASONS = [
        'no_email' => '注文にメールアドレスが無い',
        'no_consent' => 'お知らせのメールに登録していない',
        'ordered_later' => '後から注文した',
        'recent' => '7 日以内に同じメールアドレスへ送った',
        'unavailable' => '商品が今は買えない',
        'no_sender' => '送り主の表示が足りない',
        'disabled' => 'App を使うのをやめた',
    ];

    protected $table = 'cart_reminders';

    protected $fillable = ['transaction_id', 'status', 'reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'recovered_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
