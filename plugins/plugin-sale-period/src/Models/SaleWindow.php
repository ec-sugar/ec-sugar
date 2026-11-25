<?php

declare(strict_types=1);

namespace EcSugar\PluginSalePeriod\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 商品 1 つの公開の予約と販売期間。書くのは SaleWindows だけ（連鎖のロックと行のロックとイベント）。
 * 時刻は UTC で置き、店の人に見せるときだけ日本時間に直す（SalePeriodTime）。個人情報は持たない
 *
 * @property string $id
 * @property string $listing_id
 * @property ?CarbonImmutable $starts_at
 * @property ?CarbonImmutable $ends_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class SaleWindow extends Model
{
    use HasUlids;

    protected $table = 'sale_period_windows';

    protected $fillable = ['listing_id', 'starts_at', 'ends_at'];

    /**
     * まだ出す日時になっていない行（公開の予約の待ち）
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotYetOpen(Builder $query, CarbonImmutable $now): Builder
    {
        return $query->whereNotNull('starts_at')->where('starts_at', '>', $now);
    }

    /**
     * 終わる日時を過ぎた行（その日時になった瞬間から終わり）
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeClosed(Builder $query, CarbonImmutable $now): Builder
    {
        return $query->whereNotNull('ends_at')->where('ends_at', '<=', $now);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
