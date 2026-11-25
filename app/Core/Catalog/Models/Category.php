<?php

declare(strict_types=1);

namespace App\Core\Catalog\Models;

use App\Core\Listing\Models\Listing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 商品カテゴリ。木で、深さは 3 段まで。変えるのは CategoryService だけ。
 *
 * @property string $id
 * @property string|null $parent_id
 * @property string $name
 * @property string $slug
 * @property int $position
 * @property string|null $image_id
 * @property string|null $seo_title 検索の結果に出す題（空ならカテゴリの名前。ADR 0061）
 * @property string|null $seo_description 検索の結果に出す説明（空なら自動）
 */
final class Category extends Model
{
    use HasUlids;

    public const int MAX_DEPTH = 3;

    protected $fillable = ['parent_id', 'name', 'slug', 'position', 'image_id', 'seo_title', 'seo_description'];

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this> 並び順に
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * @return BelongsToMany<Listing, $this>
     */
    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'listing_categories')->withPivot('is_primary');
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
