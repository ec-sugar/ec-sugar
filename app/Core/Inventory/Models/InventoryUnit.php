<?php

declare(strict_types=1);

namespace App\Core\Inventory\Models;

use App\Core\Inventory\Enums\UnitStatus;
use App\Core\Listing\Models\Listing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 個体在庫（1 点ずつ ID を持つ）。中古品・買取品はこちら。
 * listing_id は出品されるまで null（受け入れ直後、検品中）。
 * acquired_transaction_id は入手元の取引（買取）。取引の表は第 3 片で作るため、外部キーはそのとき付ける。
 *
 * @property string $id
 * @property string|null $listing_id
 * @property string|null $variant_id 出品したら種類も（ADR 0036。商品だけ渡されたら、軸の無い商品のただ 1 つの種類）
 * @property string $location_id
 * @property string|null $identifier シリアル番号など
 * @property string|null $condition 状態ランク（S/A/B/C 等。意味は縦のプラグインが決める）
 * @property UnitStatus $status
 * @property string|null $acquired_transaction_id
 * @property array<string, mixed>|null $attributes_data
 */
final class InventoryUnit extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'inventory_units';

    protected $fillable = [
        'listing_id', 'variant_id', 'location_id', 'identifier', 'condition', 'status', 'acquired_transaction_id', 'attributes_data',
    ];

    protected $attributes = [
        'status' => 'received',
    ];

    protected static function booted(): void
    {
        self::saving(static function (self $unit): void {
            if ($unit->listing_id === null) {
                $unit->variant_id = null;
            } elseif ($unit->variant_id === null) {
                $unit->variant_id = Listing::query()->find($unit->listing_id)?->soleVariant()?->id;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UnitStatus::class,
            'attributes_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
