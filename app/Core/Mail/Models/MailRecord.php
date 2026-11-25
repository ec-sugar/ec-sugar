<?php

declare(strict_types=1);

namespace App\Core\Mail\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use App\Core\Crypto\Casts\TenantEncryptedJson;
use App\Core\Trace\RecordsTrace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 店が送ったメールの記録（テナント DB。ADR 0095）。書くのは MailRecords だけ（送る口から）。
 *
 * @property string $id
 * @property string $kind <core か App の名前>.<何のメールか>
 * @property string $subject 件名（暗号化）
 * @property string $status sending | sent | failed
 * @property string|null $reason 送れなかった理由の語（MailRecords::REASONS）
 * @property int|null $smtp_code SMTP の応答の番号
 * @property string|null $smtp_status 拡張の状態の番号（5.1.1 など）
 * @property int $attempts 試した回数（キューの仕事のやり直しで増える）
 * @property array{addresses?: list<string>}|null $recipients 宛先（暗号化）
 * @property string|null $recipient_index 最初の宛先のブラインドインデックス（お客さんの台帳の email_index と同じ値）
 * @property int $recipient_count
 * @property string|null $transaction_id 関係する注文
 * @property string|null $job_id 送ったキューの仕事
 * @property string|null $trace_id 送った処理の ID（ADR 0087）
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MailRecord extends Model
{
    use HasUlids;
    use RecordsTrace;

    protected $table = 'mail_records';

    protected $fillable = ['kind', 'subject', 'status', 'reason', 'smtp_code', 'smtp_status', 'attempts', 'recipients', 'recipient_index', 'recipient_count', 'transaction_id', 'job_id', 'sent_at'];

    protected function casts(): array
    {
        return [
            'subject' => TenantEncrypted::class,
            'recipients' => TenantEncryptedJson::class,
            'smtp_code' => 'integer',
            'attempts' => 'integer',
            'recipient_count' => 'integer',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
