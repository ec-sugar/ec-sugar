<?php

declare(strict_types=1);

namespace EcSugar\PluginReferral\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 紹介された人 1 人の紹介（ADR 0075）。書くのは Referrals だけ。名前・メール・住所・電話・接続元の平文は持たない
 *
 * @property string $id
 * @property string $referrer_account_id
 * @property string $friend_account_id
 * @property string $status
 * @property ?string $reason
 * @property ?string $transaction_id
 * @property ?string $source_index
 * @property int $referrer_points
 * @property int $friend_points
 * @property CarbonImmutable $linked_at
 * @property ?CarbonImmutable $ordered_at
 * @property ?CarbonImmutable $decided_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Referral extends Model
{
    use HasUlids;

    public const string LINKED = 'linked';

    public const string ORDERED = 'ordered';

    public const string REVIEW = 'review';

    public const string REWARDED = 'rewarded';

    public const string DECLINED = 'declined';

    public const string REFUSED = 'refused';

    /** 付けなかった理由・確かめ待ちの理由と、管理画面に出す言葉 */
    public const array REASONS = [
        'self' => '本人による紹介（メール・電話・お届け先が同じ）',
        'limit' => '紹介した人の今月の上限を超えた',
        'disabled' => 'App を止めている間に発送された',
        'points_off' => 'ポイントを使わない設定だった',
        'not_member' => 'どちらかが会員でなくなった',
        'not_new' => '会員になる前に払われた注文があった',
        'cancelled' => '最初の注文が取り消された・返金された',
        'same_source' => '同じ接続元（IP アドレス）から何人も会員登録があった',
    ];

    protected $table = 'referrals';

    protected $fillable = ['referrer_account_id', 'friend_account_id', 'status', 'source_index', 'linked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'referrer_points' => 'integer',
            'friend_points' => 'integer',
            'linked_at' => 'immutable_datetime',
            'ordered_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
