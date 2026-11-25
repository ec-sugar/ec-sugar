<?php

declare(strict_types=1);

namespace App\Core\Inventory\Models;

use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 数量在庫（種類 × 拠点。ADR 0036）。更新は StockService 経由で行い、行ロックで競合を防ぐ。
 *
 * @property string $id
 * @property string $listing_id
 * @property string $variant_id
 * @property string $location_id
 * @property int $quantity 物理的にある数
 * @property int $reserved 取引で確保済みの数（quantity に含まれる）
 */
final class InventoryStock extends Model
{
    use HasUlids;

    protected $table = 'inventory_stocks';

    protected $fillable = ['listing_id', 'variant_id', 'location_id', 'quantity', 'reserved'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reserved' => 'integer',
        ];
    }

    public function available(): int
    {
        return $this->quantity - $this->reserved;
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<ListingVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ListingVariant::class, 'variant_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
