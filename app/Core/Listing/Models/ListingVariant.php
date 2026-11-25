<?php

declare(strict_types=1);

namespace App\Core\Listing\Models;

use App\Core\Inventory\Models\InventoryStock;
use App\Core\Listing\Enums\VariantStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 商品の種類（SKU。ADR 0036）。どの商品も 1 つ以上持ち、在庫・カートの行・取引の行は種類を指す。
 *
 * - 軸の無い商品は、値の無い種類を 1 つだけ持つ（商品を作ると Listing が作る）
 * - 価格が空なら商品の価格で売る（price() を使う）
 * - 種類が商品に属すること、値がその商品のその番の軸のものであることは DB が守る
 *
 * @property string $id
 * @property string $listing_id
 * @property int $position
 * @property string|null $sku
 * @property int|null $unit_amount
 * @property string|null $value_1
 * @property string|null $value_2
 * @property string|null $value_3
 * @property string|null $image_id
 * @property VariantStatus $status
 * @property int $version
 */
final class ListingVariant extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'listing_variants';

    protected $fillable = ['listing_id', 'position', 'sku', 'unit_amount', 'value_1', 'value_2', 'value_3', 'image_id', 'status'];

    protected $attributes = [
        'position' => 1,
        'status' => 'active',
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'unit_amount' => 'integer',
            'status' => VariantStatus::class,
            'version' => 'integer',
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
     * @return HasMany<InventoryStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'variant_id');
    }

    /**
     * 売る価格。種類に無ければ商品の価格（査定・見積の商品は null）。
     */
    public function price(Listing $listing): ?int
    {
        return $this->unit_amount ?? $listing->unit_amount;
    }

    /**
     * 選ぶ値の ID（軸の順）。
     *
     * @return list<string>
     */
    public function valueIds(): array
    {
        return array_values(array_filter([$this->value_1, $this->value_2, $this->value_3], static fn (?string $id): bool => $id !== null));
    }

    /**
     * 種類の名前（「赤 / M」）。軸の無い種類は空の文字。
     */
    public function label(): string
    {
        $ids = $this->valueIds();

        if ($ids === []) {
            return '';
        }

        $labels = ListingOptionValue::query()->whereIn('id', $ids)->pluck('label', 'id');

        return implode(' / ', array_map(static fn (string $id): string => (string) ($labels[$id] ?? ''), $ids));
    }
}
