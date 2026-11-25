<?php

declare(strict_types=1);

namespace App\Core\Listing\Models;

use App\Core\Inventory\Models\Location;
use App\Core\Listing\Enums\SellerStatus;
use App\Core\Party\Models\Party;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 出品者（テナント DB）。
 * 単店モードではテナントに 1 行（テナント作成時に自動生成）。マーケットモードでは複数。
 * 出品者が人や会社としての顔を持つときは Party に紐づく（任意）。
 *
 * @property string $id
 * @property string|null $party_id
 * @property string $name
 * @property string $slug
 * @property SellerStatus $status
 * @property array<string, mixed>|null $settings
 */
final class Seller extends Model
{
    use HasUlids;

    protected $table = 'sellers';

    protected $fillable = ['party_id', 'name', 'slug', 'status', 'settings'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SellerStatus::class,
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
