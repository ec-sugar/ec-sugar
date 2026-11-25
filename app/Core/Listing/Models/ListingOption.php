<?php

declare(strict_types=1);

namespace App\Core\Listing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 商品の種類を選ぶ軸（色・サイズ・素材。ADR 0036）。1 つの商品に 3 つまで。position が種類の value_1〜value_3 に対応する。
 * 変更は VariantService を通す。
 *
 * @property string $id
 * @property string $listing_id
 * @property int $position
 * @property string $name
 */
final class ListingOption extends Model
{
    use HasUlids;

    public const int MAX_PER_LISTING = 3;

    protected $table = 'listing_options';

    protected $fillable = ['listing_id', 'position', 'name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return HasMany<ListingOptionValue, $this> 並び順に
     */
    public function values(): HasMany
    {
        return $this->hasMany(ListingOptionValue::class, 'option_id')->orderBy('position');
    }
}
