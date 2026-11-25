<?php

declare(strict_types=1);

namespace App\Core\Transaction\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use App\Core\Inventory\Models\InventoryUnit;
use App\Core\Inventory\Models\Location;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\LineDraft;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * 取引の明細（商品の行と調整行）。合意した内容の写しなので、書くのは TransactionService だけ。
 * 書き換えはしない。提示（Quote）を採るときは明細ごと差し替える。
 *
 * @property string $id
 * @property string $transaction_id
 * @property int $position
 * @property LineType $line_type
 * @property string|null $kind
 * @property string|null $listing_id
 * @property string|null $variant_id 商品の行は種類も（ADR 0036）
 * @property string|null $sku 合意のときの品番の写し
 * @property int|null $parent_position オプションの行が従う商品の行（ADR 0036）
 * @property string|null $addon_text オプションの入れた文字（店の鍵で暗号化。記録や配列に出さない）
 * @property string|null $inventory_unit_id
 * @property string|null $location_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount
 * @property int $amount
 * @property int $tax_rate_bp
 */
final class TransactionLine extends Model
{
    use HasUlids;

    protected $table = 'transaction_lines';

    // 入れた文字は配列・JSON に出さない（見せる所は明示して読む）
    protected $hidden = ['addon_text'];

    protected $fillable = [
        'transaction_id', 'position', 'line_type', 'kind', 'listing_id', 'variant_id', 'sku', 'inventory_unit_id', 'location_id', 'parent_position', 'addon_text',
        'description', 'quantity', 'unit_amount', 'amount', 'tax_rate_bp',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('取引の明細は書き換えられません（提示を採り直す）'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'line_type' => LineType::class,
            'quantity' => 'integer',
            'unit_amount' => 'integer',
            'amount' => 'integer',
            'tax_rate_bp' => 'integer',
            'parent_position' => 'integer',
            'addon_text' => TenantEncrypted::class,
        ];
    }

    public function toDraft(): LineDraft
    {
        return new LineDraft(
            lineType: $this->line_type,
            description: $this->description,
            quantity: $this->quantity,
            unitAmount: $this->unit_amount,
            taxRateBp: $this->tax_rate_bp,
            kind: $this->kind,
            listingId: $this->listing_id,
            inventoryUnitId: $this->inventory_unit_id,
            locationId: $this->location_id,
            variantId: $this->variant_id,
            sku: $this->sku,
            parentPosition: $this->parent_position,
            addonText: $this->addon_text,
        );
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ListingVariant, $this> 消した種類も（取引の記録は残る）
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ListingVariant::class, 'variant_id')->withTrashed();
    }

    /**
     * @return BelongsTo<InventoryUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
