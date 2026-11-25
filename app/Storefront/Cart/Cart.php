<?php

declare(strict_types=1);

namespace App\Storefront\Cart;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 店頭のカート（ADR 0021）。変えるのは CartService だけ。
 *
 * @property string $id
 * @property string $token_hash
 * @property string|null $coupon_code
 * @property CarbonImmutable $expires_at
 */
final class Cart extends Model
{
    use HasUlids;

    protected $fillable = ['token_hash', 'expires_at'];

    /**
     * @return HasMany<CartLine, $this> 入れた順に
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CartLine::class)->orderBy('created_at')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
