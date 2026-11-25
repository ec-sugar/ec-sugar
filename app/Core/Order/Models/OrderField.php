<?php

declare(strict_types=1);

namespace App\Core\Order\Models;

use App\Core\Order\Enums\OrderFieldType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 注文の項目の決まり（ADR 0066）。店が注文の入力に足す欄。変えるのは OrderFields だけ（連鎖のロックとイベント）。
 * 名前・説明・選択肢は店の人が決める文で、個人情報ではない
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property OrderFieldType $type
 * @property list<string> $options
 * @property bool $required
 * @property int $position
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class OrderField extends Model
{
    use HasUlids;

    protected $table = 'order_fields';

    protected $fillable = ['name', 'description', 'type', 'options', 'required', 'position', 'enabled'];

    protected $attributes = ['options' => '[]', 'required' => false, 'position' => 0, 'enabled' => true];

    protected function casts(): array
    {
        return [
            'type' => OrderFieldType::class,
            'options' => 'array',
            'required' => 'boolean',
            'position' => 'integer',
            'enabled' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
