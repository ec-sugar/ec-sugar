<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 商品ごとの期限の日数と回数（空なら App の設定）
 *
 * @property string $listing_id
 * @property int|null $days
 * @property int|null $max_count
 */
final class DownloadListing extends Model
{
    public $incrementing = false;

    protected $table = 'downloads_listings';

    protected $primaryKey = 'listing_id';

    protected $keyType = 'string';

    protected $fillable = ['listing_id', 'days', 'max_count'];

    protected function casts(): array
    {
        return ['days' => 'integer', 'max_count' => 'integer'];
    }
}
