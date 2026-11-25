<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 会員だけ（min_rank 0）・ランクだけ（1〜3 以上）の商品（ADR 0079）。変えるのは RankListings だけ
 *
 * @property string $listing_id
 * @property int $min_rank
 */
final class RankListing extends Model
{
    protected $table = 'member_ranks_listings';

    protected $primaryKey = 'listing_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['listing_id', 'min_rank'];

    protected function casts(): array
    {
        return ['min_rank' => 'integer'];
    }
}
