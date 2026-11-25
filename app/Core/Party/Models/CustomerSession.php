<?php

declare(strict_types=1);

namespace App\Core\Party\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 会員の印（店の DB。ADR 0044 の 3）。ブラウザの Cookie の合い言葉の SHA-256 だけを持つ。作る・引く・取り消すのは CustomerSessions だけ
 *
 * @property string $id
 * @property string $customer_account_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $confirmed_at
 * @property string|null $via 入り方（code・passkey・App の名前。ADR 0070）
 * @property CarbonImmutable $created_at
 */
final class CustomerSession extends Model
{
    use HasUlids;

    public const null UPDATED_AT = null;

    protected $table = 'customer_sessions';

    protected $fillable = ['customer_account_id', 'token_hash', 'expires_at', 'last_used_at', 'via'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
