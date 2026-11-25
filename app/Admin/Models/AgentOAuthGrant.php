<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * OAuth の許可の系統（ADR 0101。中央 DB）。1 人の運営 × 選んだ店（1〜50。agent_oauth_grant_shops。ADR 0104）× 1 つのクライアントで、運営が許可の画面で許可して作る。
 * 取り消すと、この系統のアクセストークン（agent_tokens の行）とリフレッシュトークンが全部効かなくなる。書くのは AgentOAuthGrants と鍵の取り消しだけ
 *
 * @property string $id
 * @property string $client_id
 * @property string $operator_id
 * @property string $scope
 * @property string $client_name
 * @property string $redirect_host
 * @property CarbonImmutable $expires_at 系統の絶対の期限（書ける 90 日・読むだけ 365 日。過ぎたら入れ替えを断る）
 * @property CarbonImmutable|null $last_refreshed_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_reason
 * @property CarbonImmutable $created_at
 * @property-read Operator $operator
 */
final class AgentOAuthGrant extends Model
{
    use CentralConnection;
    use HasUlids;

    protected $table = 'agent_oauth_grants';

    protected $fillable = ['client_id', 'operator_id', 'scope', 'client_name', 'redirect_host', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'last_refreshed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }
}
