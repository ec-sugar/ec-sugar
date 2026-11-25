<?php

declare(strict_types=1);

namespace App\Core\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 配送の方法（ADR 0064。通常便・クール便など）。都道府県ごとの額は shipping_rates に持つ。変えるのは ShippingMethods だけ。
 * いちばん上（position が小さい）の方法が、方法を選んでいない商品の方法
 *
 * @property string $id
 * @property string $name
 * @property int $position
 * @property int|null $free_over この小計（値引きの後・税込み）以上で無料
 */
final class ShippingMethod extends Model
{
    use HasUlids;

    protected $table = 'shipping_methods';

    protected $fillable = ['name', 'position', 'free_over'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'free_over' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
