<?php

declare(strict_types=1);

namespace App\Core\Party\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 会員のパスキー（店の DB。ADR 0044 の 3）。書くのは CustomerPasskeys だけ
 *
 * @property string $id
 * @property string $customer_account_id
 * @property string $name
 * @property string $credential_id
 * @property string $credential
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $created_at
 */
final class CustomerPasskey extends Model
{
    use HasUlids;

    protected $table = 'customer_passkeys';

    protected $fillable = ['customer_account_id', 'name', 'credential_id', 'credential'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => TenantEncrypted::class,
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
