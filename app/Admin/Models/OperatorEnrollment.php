<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * パスキーの登録トークン（CLI・オーナーのメンバーの画面・回復用のコードが発行。ADR 0013・0024・0038）。平文は発行時に 1 度だけ表示し、保存するのは SHA-256 だけ。
 * 1 回使うか期限が来たら無効。新しく発行すると、同じ運営の未使用のトークンは失効する。
 *
 * @property string $id
 * @property string $operator_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable $created_at
 */
final class OperatorEnrollment extends Model
{
    use CentralConnection;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'operator_enrollments';

    protected $fillable = ['operator_id', 'token_hash', 'expires_at', 'used_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }
}
