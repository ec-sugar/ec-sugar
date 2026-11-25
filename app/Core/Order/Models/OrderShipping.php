<?php

declare(strict_types=1);

namespace App\Core\Order\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 注文の便ごとの送料の決め方（ADR 0064）。変えるのは OrderShippingCharges だけ。お届け先（都道府県）は持たない
 *
 * @property string $id
 * @property string $transaction_id
 * @property int $position
 * @property string|null $method_id
 * @property string $method_name
 * @property int $items_amount
 * @property int $fee
 * @property string $basis rate・highest・free_over・free_item・coupon
 * @property array<string, int>|null $rates highest のときだけ: 注文の時の 47 県の額
 * @property int|null $settled_fee highest の便の、受け取りの住所で決まった額
 */
final class OrderShipping extends Model
{
    use HasUlids;

    protected $table = 'order_shipping';

    protected $fillable = ['transaction_id', 'position', 'method_id', 'method_name', 'items_amount', 'fee', 'basis', 'rates'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'items_amount' => 'integer',
            'fee' => 'integer',
            'rates' => 'array',
            'settled_fee' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
