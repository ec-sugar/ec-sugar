<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * 中央の監査ログ（追記専用）。書き込みは AuditLog::record だけ。
 *
 * @property string $id
 * @property int $sequence
 * @property string $type
 * @property string|null $operator_id
 * @property string|null $tenant_id
 * @property string|null $ip
 * @property array<string, mixed> $data
 * @property CarbonImmutable $occurred_at
 * @property string $prev_hash
 * @property string $hash
 * @property string|null $trace_id 書いた処理の ID（ADR 0087。ハッシュの本文の外）
 */
final class AuditEvent extends Model
{
    use CentralConnection;
    use HasUlids;

    public $timestamps = false;

    protected $table = 'audit_events';

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['sequence', 'type', 'operator_id', 'tenant_id', 'ip', 'data', 'occurred_at', 'prev_hash', 'hash', 'trace_id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('監査ログは追記専用です'));
        self::deleting(static fn (): never => throw new LogicException('監査ログは追記専用です'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
