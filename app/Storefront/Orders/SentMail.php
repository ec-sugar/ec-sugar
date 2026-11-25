<?php

declare(strict_types=1);

namespace App\Storefront\Orders;

use App\Core\Trace\RecordsTrace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * お客さんへ送ったメールの履歴（宛先は残さない）
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $kind
 * @property string|null $fulfillment_id
 * @property string|null $refund_id 返金の知らせは返金ごと（ADR 0048）
 * @property string $subject
 * @property string $status
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable $created_at
 * @property string|null $trace_id 送った処理の ID（ADR 0087）
 */
final class SentMail extends Model
{
    use HasUlids;
    use RecordsTrace;

    public const UPDATED_AT = null;

    protected $table = 'sent_mails';

    protected $fillable = ['transaction_id', 'kind', 'fulfillment_id', 'refund_id', 'subject', 'status', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
