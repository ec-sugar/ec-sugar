<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * OAuth の許可の頼み（ADR 0101。中央 DB）。/oauth/authorize で作り（10 分）、許可の画面で許可すると認可コード（ハッシュだけ・10 分・1 回だけ）を持つ。
 * 書くのは AgentOAuthAuthorizations と AgentOAuthGrants だけ
 *
 * @property string $id
 * @property string $handle_hash
 * @property string $client_id
 * @property string $redirect_uri
 * @property string|null $state
 * @property string $code_challenge
 * @property string $requested_scope
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $decided_at
 * @property bool|null $approved
 * @property string|null $operator_id
 * @property list<string>|null $tenant_ids 許可した店（1〜50。ADR 0104）
 * @property string|null $scope
 * @property string|null $code_hash
 * @property CarbonImmutable|null $code_expires_at
 * @property CarbonImmutable|null $used_at
 * @property string|null $grant_id
 */
final class AgentOAuthRequest extends Model
{
    use CentralConnection;
    use HasUlids;

    protected $table = 'agent_oauth_requests';

    protected $fillable = ['handle_hash', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'requested_scope', 'expires_at'];

    protected $hidden = ['handle_hash', 'code_hash', 'code_challenge'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'approved' => 'boolean',
            'tenant_ids' => 'array',
            'code_expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
