<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * OAuth の公開のクライアント（ADR 0101。中央 DB）。動的なクライアント登録（RFC 7591）で作る。書くのは AgentOAuthClients だけ。
 * 名前は名乗っただけで信じない（許可の画面では行き先のホストを大きく出す）
 *
 * @property string $id クライアントの ID（client_id。秘密ではない）
 * @property string $name
 * @property list<string> $redirect_uris
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $created_at
 */
final class AgentOAuthClient extends Model
{
    use CentralConnection;
    use HasUlids;

    protected $table = 'agent_oauth_clients';

    protected $fillable = ['name', 'redirect_uris'];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
