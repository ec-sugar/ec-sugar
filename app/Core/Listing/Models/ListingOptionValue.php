<?php

declare(strict_types=1);

namespace App\Core\Listing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 軸の値（赤・M など）。変更は VariantService を通す。
 *
 * @property string $id
 * @property string $option_id
 * @property int $position
 * @property string $label
 */
final class ListingOptionValue extends Model
{
    use HasUlids;

    protected $table = 'listing_option_values';

    protected $fillable = ['option_id', 'position', 'label'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<ListingOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ListingOption::class, 'option_id');
    }
}
