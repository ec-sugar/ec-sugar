<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * OAuth のリフレッシュトークン（ADR 0101。中央 DB）。持つのはハッシュだけ。30 日・使うたびに入れ替え、使った印（used_at）のあるものがまた来たら系統ごと取り消す。
 * 書くのは AgentOAuthGrants だけ
 *
 * @property string $id
 * @property string $grant_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable $created_at
 */
final class AgentOAuthRefreshToken extends Model
{
    use CentralConnection;
    use HasUlids;

    public const null UPDATED_AT = null;

    protected $table = 'agent_oauth_refresh_tokens';

    protected $fillable = ['grant_id', 'token_hash', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
