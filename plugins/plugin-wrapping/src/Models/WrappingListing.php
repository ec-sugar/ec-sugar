<?php

declare(strict_types=1);

namespace EcSugar\PluginWrapping\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 商品ごとの「ラッピングできる」「のしを付けられる」（ADR 0078）。行が無い商品はどちらもできない。書くのは WrappingListingPanel だけ
 *
 * @property string $listing_id
 * @property bool $wrappable
 * @property bool $noshi
 */
final class WrappingListing extends Model
{
    protected $table = 'wrapping_listings';

    protected $primaryKey = 'listing_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['listing_id', 'wrappable', 'noshi'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['wrappable' => 'boolean', 'noshi' => 'boolean'];
    }
}
