<?php

declare(strict_types=1);

namespace EcSugar\PluginPurchaseLimits\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 商品 1 つの「1 回のご注文の上限」。書くのは ListingLimits だけ（連鎖のロックと行のロックとイベント）。
 * 主キーは商品の ID（1 商品に 1 行）。個人情報は持たない
 *
 * @property string $listing_id
 * @property int $per_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ListingLimit extends Model
{
    public $incrementing = false;

    protected $table = 'purchase_limits_listings';

    protected $primaryKey = 'listing_id';

    protected $keyType = 'string';

    protected $fillable = ['listing_id', 'per_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'per_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
