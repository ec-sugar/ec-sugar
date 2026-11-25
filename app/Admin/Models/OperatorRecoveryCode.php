<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * 運営の回復用のコード（ADR 0038）。保存するのは鍵つきのハッシュだけ。1 回使ったら used_at が入り、二度と使えない。
 *
 * @property string $id
 * @property string $operator_id
 * @property string $code_hash
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable $created_at
 */
final class OperatorRecoveryCode extends Model
{
    use CentralConnection;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'operator_recovery_codes';

    protected $fillable = ['operator_id', 'code_hash', 'used_at'];

    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['used_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
