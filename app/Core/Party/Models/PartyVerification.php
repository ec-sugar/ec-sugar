<?php

declare(strict_types=1);

namespace App\Core\Party\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use App\Core\Party\Enums\VerificationMethod;
use App\Core\Party\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 本人確認の記録（テナント DB）。
 * 書類そのものは S3 互換ストレージに暗号化して置き、ここには参照（document_ref、暗号化）だけ持つ。
 * 保存期間を過ぎたら書類を消し、記録だけ残す（個人情報保護法）。
 *
 * @property string $id
 * @property string $party_id
 * @property VerificationMethod $method
 * @property VerificationStatus $status
 * @property string|null $document_ref
 * @property Carbon|null $verified_at
 * @property Carbon|null $expires_at
 * @property array<string, mixed>|null $data
 */
final class PartyVerification extends Model
{
    use HasUlids;

    protected $table = 'party_verifications';

    protected $fillable = ['party_id', 'method', 'status', 'document_ref', 'verified_at', 'expires_at', 'data'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => VerificationMethod::class,
            'status' => VerificationStatus::class,
            'document_ref' => TenantEncrypted::class,
            'verified_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
