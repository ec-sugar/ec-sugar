<?php

declare(strict_types=1);

namespace App\Admin\Agent\Models;

use App\Core\Crypto\Casts\TenantEncryptedJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * AI エージェントの書き込みの案（ADR 0101。店の DB）。作る・決めるのは AgentProposals だけ。
 * 引数は店の鍵で暗号化（送り状の番号を含みうる）。要約と照らす値（basis）には個人情報を入れない
 *
 * @property string $id
 * @property string $tool
 * @property string $level 承認に要る段階（operate・money）
 * @property array<string, mixed> $arguments
 * @property array<string, mixed> $basis
 * @property string $summary
 * @property string $token_id
 * @property string $operator_id
 * @property string|null $fingerprint 道具と引数の HMAC（同じ中身の待ちの案を 2 つ作らない）
 * @property string $status pending・approved・rejected・expired・failed
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AgentProposal extends Model
{
    use HasUlids;

    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string EXPIRED = 'expired';

    public const string FAILED = 'failed';

    protected $table = 'agent_proposals';

    protected $fillable = ['tool', 'level', 'arguments', 'basis', 'summary', 'token_id', 'operator_id', 'fingerprint', 'expires_at'];

    protected function casts(): array
    {
        return [
            'arguments' => TenantEncryptedJson::class,
            'basis' => 'array',
            'result' => 'array',
            'decided_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
