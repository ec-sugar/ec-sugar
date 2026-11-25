<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use App\Core\Trace\RecordsTrace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 処理した決済代行の通知（二度目を処理しないため）。中身は残さない
 *
 * @property string $id
 * @property string $provider
 * @property string $event_id
 * @property string $type
 * @property string $outcome
 * @property string|null $trace_id 通知を受けた処理の ID（ADR 0087）
 */
final class WebhookEvent extends Model
{
    use HasUlids;
    use RecordsTrace;

    public const UPDATED_AT = null;

    protected $table = 'webhook_events';

    protected $fillable = ['provider', 'event_id', 'type', 'outcome'];
}
