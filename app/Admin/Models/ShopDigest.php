<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * 店ごとの様子（中央 DB。1 店 1 行。ADR 0042）: 公開の状態・困りごとの件数・記録の検証の結果・集めた時刻。
 * 件数だけで、どの注文か・お客さんは持たない。書くのは ShopDigests だけ
 *
 * @property string $tenant_id
 * @property string|null $status
 * @property int $stray_payments
 * @property int $refund_issues
 * @property int $stuck_mails
 * @property int $event_waiting 届け直しを待つ記録の数（ADR 0089）
 * @property int $event_stopped 何度も落ちて止めた記録の数
 * @property int $refund_peak_count この 1 時間ほどの返金の山の件数（ADR 0103）
 * @property int $refund_peak_amount その合計（円の整数）
 * @property int $media_bytes 店の写真の合計（バイト）
 * @property bool|null $chain_intact
 * @property int|null $chain_head_sequence 控えた連鎖の先頭の番号（イベントが無い店は 0）
 * @property string|null $chain_head_hash その hash
 * @property bool|null $chain_rolled_back 前に控えた先頭が今の連鎖に無い（末尾を切り落とされた・書き換えられた）
 * @property CarbonImmutable|null $chain_checked_at
 * @property CarbonImmutable|null $collected_at
 * @property CarbonImmutable|null $failed_at
 */
final class ShopDigest extends Model
{
    use CentralConnection;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'shop_digests';

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stray_payments' => 'integer',
            'refund_issues' => 'integer',
            'stuck_mails' => 'integer',
            'event_waiting' => 'integer',
            'event_stopped' => 'integer',
            'refund_peak_count' => 'integer',
            'refund_peak_amount' => 'integer',
            'media_bytes' => 'integer',
            'chain_intact' => 'boolean',
            'chain_head_sequence' => 'integer',
            'chain_rolled_back' => 'boolean',
            'chain_checked_at' => 'immutable_datetime',
            'collected_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
