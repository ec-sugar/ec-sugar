<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * 店ごと・日ごとの注文の数と売上（中央 DB。ADR 0042）。日は日本の時刻の日付、金額は円の整数。
 * 書くのは ShopDigests だけ（店の文脈の中で数えた結果で置き換える）。読むのは OperationsOverview
 *
 * @property string $tenant_id
 * @property string $day
 * @property int $orders
 * @property int $sales
 * @property int $test_orders
 * @property CarbonImmutable $collected_at
 */
final class ShopDailySale extends Model
{
    use CentralConnection;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'shop_daily_sales';

    protected $keyType = 'string';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['orders' => 'integer', 'sales' => 'integer', 'test_orders' => 'integer', 'collected_at' => 'immutable_datetime'];
    }
}
