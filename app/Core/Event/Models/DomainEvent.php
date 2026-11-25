<?php

declare(strict_types=1);

namespace App\Core\Event\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * ドメインイベント（テナント DB、追記専用）。書き込みは EventStore::append だけ。
 * 更新と削除は DB のトリガーでも拒否する。
 *
 * @property string $id
 * @property int $sequence
 * @property string $type
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property array<string, mixed> $payload
 * @property string|null $actor
 * @property CarbonImmutable $occurred_at
 * @property string $prev_hash
 * @property string $hash
 * @property string|null $trace_id 書いた処理の ID（ADR 0087。ハッシュの本文の外）
 */
final class DomainEvent extends Model
{
    use HasUlids;

    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public $timestamps = false;

    protected $table = 'domain_events';

    // オフセット付きで保存し、接続のタイムゾーン設定に左右されないようにする（連鎖の検証に効く）
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = [
        'sequence', 'type', 'aggregate_type', 'aggregate_id', 'payload', 'actor', 'occurred_at', 'prev_hash', 'hash', 'trace_id',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('ドメインイベントは追記専用です'));
        self::deleting(static fn (): never => throw new LogicException('ドメインイベントは追記専用です'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
