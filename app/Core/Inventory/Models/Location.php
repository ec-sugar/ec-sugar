<?php

declare(strict_types=1);

namespace App\Core\Inventory\Models;

use App\Core\Inventory\Enums\LocationKind;
use App\Core\Listing\Models\Seller;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 在庫の拠点（倉庫・店舗・仮想）。テナント DB。
 *
 * @property string $id
 * @property string $seller_id
 * @property LocationKind $kind
 * @property string $name
 * @property array<string, mixed>|null $address
 */
final class Location extends Model
{
    use HasUlids;

    protected $table = 'locations';

    protected $fillable = ['seller_id', 'kind', 'name', 'address'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LocationKind::class,
            'address' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
