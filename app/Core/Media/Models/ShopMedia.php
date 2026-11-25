<?php

declare(strict_types=1);

namespace App\Core\Media\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * ショップの写真（テナント DB。ADR 0028 区切り 1）。商品と関係ない写真（ヒーロー・店の紹介・キャンペーン）の置き場。
 * ファイルは保存場所 'media' の path() に、大きさ（ImageProcessor::SHOP_VARIANTS）ごとに置く。変えるのは ShopMediaService だけ。
 *
 * @property string $id
 * @property string|null $alt
 * @property int $width
 * @property int $height
 * @property int $bytes 作り直して置いた写真（大と中）の合計の大きさ
 * @property string $checksum
 * @property CarbonImmutable $created_at
 */
final class ShopMedia extends Model
{
    use HasUlids;

    protected $table = 'shop_media';

    protected $fillable = ['alt', 'width', 'height', 'checksum', 'bytes'];

    /**
     * 保存場所の中の道筋。テナント → ショップの写真 → 写真 → 大きさ（テナントの ID は文字の形を検査済みのものだけ。名前はこちらが決める）
     */
    public static function path(string $tenantId, string $mediaId, string $variant): string
    {
        return "tenants/{$tenantId}/shop/media/{$mediaId}-{$variant}.webp";
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
