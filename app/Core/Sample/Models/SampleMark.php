<?php

declare(strict_types=1);

namespace App\Core\Sample\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * サンプルの印（テナント DB）。`shop:sample` が入れた物 1 つに 1 行。変えるのは ShopSamples だけ。
 *
 * @property string $id
 * @property string $key サンプルの中の名前（例 listing:canvas-tote）
 * @property string $subject_type listing・category・shop_media・theme_version・shop_settings
 * @property string|null $subject_id
 * @property array<string, mixed> $snapshot 入れたときの形（書き換えたかを見る）
 * @property CarbonImmutable|null $ended_at
 * @property string|null $outcome removed（消した）・kept（お店の物として残した）
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class SampleMark extends Model
{
    use HasUlids;

    protected $table = 'sample_marks';

    protected $fillable = ['key', 'subject_type', 'subject_id', 'snapshot', 'ended_at', 'outcome'];

    /**
     * まだ終わっていない印（サンプルとして残っている物）
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
