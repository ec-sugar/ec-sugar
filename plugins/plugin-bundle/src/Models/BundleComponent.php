<?php

declare(strict_types=1);

namespace EcSugar\PluginBundle\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * セットの商品 1 つを構成する品の 1 行。書くのは BundleComponents だけ（連鎖のロックと行のロックとイベント）。
 * 持つのは商品・種類の ID と 1 セットあたりの数だけで、個人情報も金額も持たない（値段はセットの商品そのものが持つ）
 *
 * @property string $id
 * @property string $listing_id セットの商品
 * @property string $part_listing_id 構成する品の商品
 * @property string $variant_id 構成する品の種類
 * @property int $quantity 1 セットあたりの数
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BundleComponent extends Model
{
    use HasUlids;

    protected $table = 'bundle_components';

    protected $fillable = ['listing_id', 'part_listing_id', 'variant_id', 'quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
