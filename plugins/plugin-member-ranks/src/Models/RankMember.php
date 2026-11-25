<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 会員の今のランク（ADR 0079）。0 はランクなし。変えるのは Ranks だけ（連鎖のロック → 行のロック → イベント）
 *
 * @property string $customer_account_id
 * @property int $rank
 * @property CarbonImmutable $ranked_at
 * @property CarbonImmutable|null $pinned_until
 */
final class RankMember extends Model
{
    protected $table = 'member_ranks_members';

    protected $primaryKey = 'customer_account_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['customer_account_id', 'rank', 'ranked_at', 'pinned_until'];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'ranked_at' => 'immutable_datetime',
            'pinned_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * 店の人が手で決めたランクを、今は毎日の決め直しで変えないか
     */
    public function pinned(CarbonImmutable $now): bool
    {
        return $this->pinned_until !== null && $this->pinned_until->gt($now);
    }
}
