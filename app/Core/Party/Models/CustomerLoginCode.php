<?php

declare(strict_types=1);

namespace App\Core\Party\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 会員のログインのコード（店の DB。ADR 0044 の 3）。確かめるのは店の鍵の HMAC。送る間だけ宛先とコードを暗号化して持ち、送ったら消す（SendLoginCode）。
 * 作るのも確かめるのも CustomerLogin だけ
 *
 * @property string $id
 * @property string $email_index
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property string|null $email
 * @property string|null $sealed_code
 */
final class CustomerLoginCode extends Model
{
    use HasUlids;

    public const null UPDATED_AT = null;

    protected $table = 'customer_login_codes';

    protected $fillable = ['email_index', 'code_hash', 'expires_at', 'email', 'sealed_code'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'email' => TenantEncrypted::class,
            'sealed_code' => TenantEncrypted::class,
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
