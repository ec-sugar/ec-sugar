<?php

declare(strict_types=1);

namespace App\Core\Party\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use App\Core\Crypto\Casts\TenantEncryptedJson;
use App\Core\Crypto\Concerns\HasBlindIndexes;
use App\Core\Party\Enums\PartyKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 当事者（テナント DB）。顧客と運営側の人を表す。
 *
 * 個人情報の列（name / email / phone / address / notes）はテナント鍵で暗号化して保存する。
 * 検索は name_index / email_index / phone_index（ブラインドインデックス）で完全一致のみ。
 * 並べ替えや部分一致が必要な項目は、ここには置かない（CONCEPT 7.2、ADR 0010）。
 *
 * @property string $id
 * @property PartyKind $kind
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property array<string, mixed>|null $address
 * @property string|null $notes
 */
final class Party extends Model
{
    use HasBlindIndexes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'parties';

    protected $fillable = ['kind', 'name', 'email', 'phone', 'address', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PartyKind::class,
            'name' => TenantEncrypted::class,
            'email' => TenantEncrypted::class,
            'phone' => TenantEncrypted::class,
            'address' => TenantEncryptedJson::class,
            'notes' => TenantEncrypted::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function blindIndexes(): array
    {
        return [
            'name' => 'name_index',
            'email' => 'email_index',
            'phone' => 'phone_index',
        ];
    }

    /**
     * @return HasMany<PartyVerification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(PartyVerification::class);
    }
}
