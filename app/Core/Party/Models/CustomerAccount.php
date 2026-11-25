<?php

declare(strict_types=1);

namespace App\Core\Party\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use App\Core\Crypto\Concerns\HasBlindIndexes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * お客さんの台帳（店の DB。ADR 0044 の 1）。メールの完全一致で 1 人にまとめ、注文をここへつなぐ。
 *
 * メールは店の鍵で暗号化し、引くのは email_index（ブラインドインデックス）の完全一致だけ。
 * 名前・住所・電話は持たない（注文ごとの Party に、その時の値として残す）。
 * 作るのは CustomerAccounts（サービス）だけ。注文からの向きだけを持つ（台帳から取引を知らない。核の文脈の依存の向き）。
 *
 * @property string $id
 * @property string $email
 * @property string $email_index
 * @property CarbonImmutable|null $member_since
 * @property CarbonImmutable|null $disabled_at
 * @property string|null $merged_into_id
 * @property CarbonImmutable|null $merged_at
 * @property CarbonImmutable $created_at
 */
final class CustomerAccount extends Model
{
    use HasBlindIndexes;
    use HasUlids;

    protected $table = 'customer_accounts';

    protected $fillable = ['email'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => TenantEncrypted::class,
            'member_since' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'merged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function blindIndexes(): array
    {
        return ['email' => 'email_index'];
    }
}
