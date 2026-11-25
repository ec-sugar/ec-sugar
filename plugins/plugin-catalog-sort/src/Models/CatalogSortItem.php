<?php

declare(strict_types=1);

namespace EcSugar\PluginCatalogSort\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 一覧の並びを手で決めた商品 1 つ。商品・並び（小さいほど先）・タグ。
 * 書くのは CatalogSortItems だけ（連鎖のロックとイベント）。お客さんの個人情報は持たない（店の人が書いたタグだけ）
 *
 * @property string $id
 * @property string $listing_id
 * @property int $position
 * @property list<string> $tags
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CatalogSortItem extends Model
{
    use HasUlids;

    protected $table = 'catalog_sort_items';

    protected $fillable = ['listing_id', 'position', 'tags'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'tags' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
