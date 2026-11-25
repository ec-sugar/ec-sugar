<?php

declare(strict_types=1);

namespace EcSugar\PluginPreorder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 商品ごとの予約販売の決まり（店の DB。ADR 0073）。変えるのは PreorderListings だけ（連鎖のロック → 行のロック → イベント）
 *
 * @property string $id
 * @property string $listing_id
 * @property bool $active 予約販売にしている（止めても行は残す。前の注文の予約をたどるため）
 * @property int $round 予約の回（受付を始め直すたびに進む）
 * @property string $release_date 発売日（Y-m-d。発送を始める日）
 * @property string|null $ends_on 予約の受付の終わり（Y-m-d。その日まで受ける）
 * @property int|null $capacity 予約の数の上限（空なら上限なし）
 */
final class PreorderListing extends Model
{
    use HasUlids;

    protected $table = 'preorder_listings';

    protected $fillable = ['listing_id', 'active', 'round', 'release_date', 'ends_on', 'capacity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'round' => 'integer', 'capacity' => 'integer'];
    }

    public function releaseDate(): string
    {
        return substr((string) $this->release_date, 0, 10);
    }

    public function endsOn(): ?string
    {
        return $this->ends_on === null ? null : substr((string) $this->ends_on, 0, 10);
    }
}
