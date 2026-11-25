<?php

declare(strict_types=1);

namespace App\Core\Order\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use App\Core\Order\Enums\OrderFieldType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 注文ごとの注文の項目の値（ADR 0066）。その時の欄の名前・説明・種類・並びを写して持つ（後で欄を変えても、過去の注文は当時のまま見せる）。
 * 値は自由な文で個人情報を含みうるので、店の鍵で暗号化する（ADR 0010）。書くのは注文の確定の中の OrderFields だけで、後から書き換えない・消さない（DB も断る）
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $order_field_id
 * @property string $name
 * @property string|null $description
 * @property OrderFieldType $type
 * @property int $position
 * @property string $value
 * @property CarbonImmutable|null $created_at
 */
final class OrderFieldValue extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'order_field_values';

    protected $fillable = ['transaction_id', 'order_field_id', 'name', 'description', 'type', 'position', 'value'];

    protected function casts(): array
    {
        return [
            'type' => OrderFieldType::class,
            'position' => 'integer',
            'value' => TenantEncrypted::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
