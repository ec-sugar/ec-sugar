<?php

declare(strict_types=1);

namespace App\Core\Listing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 商品の写真（テナント DB）。ファイルは保存場所 'media' の path() に、大きさ（ImageProcessor::VARIANTS）ごとに置く。
 * 変更は ListingImageService を通す。
 *
 * @property string $id
 * @property string $listing_id
 * @property int $position
 * @property string|null $alt
 * @property int $width
 * @property int $height
 * @property int $bytes 作り直して置いた写真（大と中）の合計の大きさ
 * @property string $checksum
 * @property string|null $source_digest 作り直す前の元のファイルの SHA-256（同じ写真を二重に作らない）
 * @property string|null $source_url_digest 取りに行った URL の SHA-256（CSV の取り込み。URL そのものは残さない）
 */
final class ListingImage extends Model
{
    use HasUlids;

    protected $table = 'listing_images';

    protected $fillable = ['listing_id', 'position', 'alt', 'width', 'height', 'checksum', 'bytes', 'source_digest', 'source_url_digest'];

    /**
     * 保存場所の中の道筋。テナント → 商品 → 写真 → 大きさ（テナントの ID は文字の形を検査済みのものだけ）
     */
    public static function path(string $tenantId, string $listingId, string $imageId, string $variant): string
    {
        return "tenants/{$tenantId}/listings/{$listingId}/{$imageId}-{$variant}.webp";
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
