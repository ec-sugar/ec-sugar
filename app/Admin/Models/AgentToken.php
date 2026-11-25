<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * AI エージェントの鍵（ADR 0100・0101。中央 DB）。書くのは AgentTokens だけ。鍵そのものは持たない（ハッシュと頭の 8 文字）
 *
 * @property string $id
 * @property string $operator_id
 * @property string|null $tenant_id 管理画面で作った鍵の店（OAuth で作った鍵は null。どの店かは呼び出しごとに系統の店から選ぶ。ADR 0104）
 * @property string $name
 * @property string $token_hash
 * @property string $prefix
 * @property string $scope 読むだけ（read）か書ける（write）か（ADR 0101）
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property string|null $oauth_grant_id OAuth で作った鍵なら、その許可の系統（期限は 1 時間。ADR 0101）
 * @property string|null $oauth_client_id OAuth で作った鍵なら、そのクライアント
 * @property-read Operator $operator
 */
final class AgentToken extends Model
{
    use CentralConnection;
    use HasUlids;

    protected $table = 'agent_tokens';

    protected $fillable = ['operator_id', 'tenant_id', 'name', 'token_hash', 'prefix', 'scope', 'expires_at', 'oauth_grant_id', 'oauth_client_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
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

    public function usable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
