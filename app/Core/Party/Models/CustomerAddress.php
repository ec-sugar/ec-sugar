<?php

declare(strict_types=1);

namespace App\Core\Party\Models;

use App\Core\Crypto\Casts\TenantEncryptedJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 会員の届け先（店の DB。ADR 0044 の 3）。会員として入ってから入れたものだけ。中身は店の鍵で暗号化した JSON。
 * 書くのは CustomerAddresses だけ
 *
 * @property string $id
 * @property string $customer_account_id
 * @property array{name: string, phone: string, postal: string, prefecture: string, city: string, line1: string, line2: string|null} $address
 * @property bool $is_default
 * @property CarbonImmutable $created_at
 */
final class CustomerAddress extends Model
{
    use HasUlids;

    protected $table = 'customer_addresses';

    protected $fillable = ['customer_account_id', 'address', 'is_default'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => TenantEncryptedJson::class,
            'is_default' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
