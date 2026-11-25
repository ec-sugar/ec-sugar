<?php

declare(strict_types=1);

namespace App\Storefront\Cart;

use App\Core\Crypto\Casts\TenantEncryptedJson;
use App\Core\Listing\Models\Listing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * カートの 1 行（商品の種類と数。ADR 0036）。入れた時の価格は「変わったら知らせる」ためだけに持つ（計算には使わない）。
 *
 * @property string $id
 * @property string $cart_id
 * @property string $listing_id
 * @property string $variant_id
 * @property array<mixed>|null $addons 選んだオプション（店の鍵で暗号化。名入れは人の名前になりうる。ADR 0036）
 * @property string $addons_key 同じ選び方かを見る鍵つきの印（選んでいなければ空）
 * @property int $quantity
 * @property int $added_unit_amount
 */
final class CartLine extends Model
{
    use HasUlids;

    protected $fillable = ['cart_id', 'listing_id', 'variant_id', 'quantity', 'added_unit_amount', 'addons', 'addons_key'];

    protected $hidden = ['addons', 'addons_key'];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'addons' => TenantEncryptedJson::class,
            'added_unit_amount' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
