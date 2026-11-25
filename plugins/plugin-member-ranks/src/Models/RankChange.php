<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * ランクが変わった記録（ADR 0079。追記だけ。上がった知らせの印 notice・noticed_at だけを後から書く）
 *
 * @property string $id
 * @property string $customer_account_id
 * @property int $from_rank
 * @property int $to_rank
 * @property string $cause auto・manual・left
 * @property string $notice none・queued・sent・failed・skipped
 * @property CarbonImmutable|null $noticed_at
 * @property CarbonImmutable $created_at
 */
final class RankChange extends Model
{
    use HasUlids;

    public const string AUTO = 'auto';

    public const string MANUAL = 'manual';

    public const string LEFT = 'left';

    public const null UPDATED_AT = null;

    protected $table = 'member_ranks_changes';

    protected $fillable = ['customer_account_id', 'from_rank', 'to_rank', 'cause', 'notice', 'noticed_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'from_rank' => 'integer',
            'to_rank' => 'integer',
            'noticed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
