<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * メールでの戻しの申し込み（ADR 0038 の区切り 2）。リンクとブラウザの印はハッシュだけを持つ。
 *
 * @property string $id
 * @property string $operator_id
 * @property string $link_hash
 * @property CarbonImmutable $link_expires_at
 * @property string|null $browser_hash
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by
 * @property CarbonImmutable $created_at
 */
final class OperatorRecoveryRequest extends Model
{
    use CentralConnection;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'operator_recovery_requests';

    protected $fillable = ['operator_id', 'link_hash', 'link_expires_at', 'browser_hash', 'ready_at', 'used_at', 'cancelled_at', 'cancelled_by'];

    protected $hidden = ['link_hash', 'browser_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'link_expires_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
