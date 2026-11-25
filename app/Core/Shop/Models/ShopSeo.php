<?php

declare(strict_types=1);

namespace App\Core\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 店の検索と共有の設定（テナントごとに 1 行。ADR 0061）。トップの題と説明・共有の既定の写真（ショップの写真）。
 * 変えるのは SeoSettingsService だけ。写真は使っている間は消させない（外部キーと ShopMediaService::usageMap）
 *
 * @property string $id
 * @property string|null $home_title
 * @property string|null $home_description
 * @property string|null $share_image_id
 */
final class ShopSeo extends Model
{
    use HasUlids;

    protected $table = 'shop_seo';

    protected $fillable = ['home_title', 'home_description', 'share_image_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
