<?php

declare(strict_types=1);

namespace App\Core\Discount\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * クーポンを使った記録（ADR 0054 の 6・7）。1 つの注文に 1 つ。reserved と used を使った数に数え、払われずに消えた注文は released
 *
 * @property string $id
 * @property string $coupon_id
 * @property string $transaction_id
 * @property string|null $customer_account_id
 * @property int $amount
 * @property array<int|string, int> $allocations 明細の位置 => 値引きの額
 * @property string $status
 */
final class CouponRedemption extends Model
{
    use HasUlids;

    public const array COUNTED = ['reserved', 'used'];

    protected $fillable = ['coupon_id', 'transaction_id', 'customer_account_id', 'amount', 'allocations', 'status'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'allocations' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
