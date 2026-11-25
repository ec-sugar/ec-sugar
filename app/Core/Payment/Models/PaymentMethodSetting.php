<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use App\Core\Crypto\Casts\TenantEncryptedJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 店が使う支払いの手段（ADR 0058。店に 1 行だけ）。変えるのは PaymentMethodSettings と DirectMethodSettings だけ。
 * 銀行振込・代金引換（ADR 0074）の設定も同じ行に持つ。振込先は店の鍵で暗号化する（イベントには入れない）
 *
 * @property string $id
 * @property bool $konbini
 * @property bool $paypay
 * @property int $konbini_days
 * @property bool $bank_transfer
 * @property int $bank_days 銀行振込の払う期限（注文の日から何日後の 23:59 まで。1〜14）
 * @property array{bank: string, branch: string, type: string, number: string, holder: string}|null $bank_account 振込先（暗号化）
 * @property bool $cod
 * @property array<int|string, mixed> $cod_fees 代引きの手数料の段（税込み。below 円未満なら fee。最後の段は below が null。jsonb は鍵の順を変えるので、読むのは DirectMethodSettings がそろえてから）
 * @property int $cod_limit 代引きで受ける合計の上限（手数料を含む）
 * @property bool $direct_members_only 銀行振込・代金引換を会員だけに出す（ADR 0085）
 * @property CarbonImmutable $updated_at
 */
final class PaymentMethodSetting extends Model
{
    use HasUlids;

    protected $table = 'payment_method_settings';

    protected $fillable = ['konbini', 'paypay', 'konbini_days', 'bank_transfer', 'bank_days', 'bank_account', 'cod', 'cod_fees', 'cod_limit', 'direct_members_only'];

    protected $attributes = ['konbini' => false, 'paypay' => false, 'konbini_days' => 3, 'bank_transfer' => false, 'bank_days' => 7, 'cod' => false, 'cod_fees' => '[]', 'cod_limit' => 300000, 'direct_members_only' => false];

    /** 振込先は暗号化して持つ。配列に出しても見せない */
    protected $hidden = ['bank_account'];

    protected function casts(): array
    {
        return [
            'konbini' => 'boolean',
            'paypay' => 'boolean',
            'konbini_days' => 'integer',
            'bank_transfer' => 'boolean',
            'bank_days' => 'integer',
            'bank_account' => TenantEncryptedJson::class,
            'cod' => 'boolean',
            'cod_fees' => 'array',
            'cod_limit' => 'integer',
            'direct_members_only' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
