<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 商品ごとのフィードへの出し方（外すか・ブランド・Google の商品カテゴリの番号・状態・品番を MPN として出すか）。
 * 無い商品は既定（出す・App の設定の既定・新品・出さない）。書くのは FeedListingPanel だけ（連鎖のロックとイベント）
 *
 * @property string $id
 * @property string $listing_id
 * @property bool $excluded
 * @property ?string $brand
 * @property ?string $google_product_category
 * @property string $condition
 * @property bool $mpn_from_sku
 */
final class FeedListing extends Model
{
    use HasUlids;

    public const array CONDITIONS = ['new' => '新品', 'used' => '中古', 'refurbished' => '再生品（整備済み）'];

    protected $table = 'product_feed_listings';

    protected $fillable = ['listing_id', 'excluded', 'brand', 'google_product_category', 'condition', 'mpn_from_sku'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['excluded' => 'boolean', 'mpn_from_sku' => 'boolean'];
    }
}
