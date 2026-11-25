<?php

declare(strict_types=1);

namespace App\Core\Points\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * ポイントの帳簿の 1 行（ADR 0068）。追記だけで、書き換え・消すのは DB が断る。書くのは PointService だけ。
 * balance_after（この行の後の残高）は DB のトリガーが前の行から計算して入れ、負になる行は断る。
 * note は店の人の手の足し引きの理由（自由な文なので暗号化。ADR 0010）
 *
 * @property string $id
 * @property int $seq
 * @property int $account_seq 会員ごとの連番（トリガーが入れる）
 * @property string $customer_account_id
 * @property string $kind earn・spend・release・revoke・expire・adjust
 * @property int $points
 * @property int $balance_after
 * @property int $shortfall
 * @property string|null $transaction_id
 * @property string|null $cause
 * @property string|null $note
 * @property string|null $actor
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $occurred_at
 */
final class PointEntry extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'point_entries';

    protected $fillable = ['customer_account_id', 'kind', 'points', 'shortfall', 'transaction_id', 'cause', 'note', 'actor', 'expires_at', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'account_seq' => 'integer',
            'points' => 'integer',
            'balance_after' => 'integer',
            'shortfall' => 'integer',
            'note' => TenantEncrypted::class,
            'expires_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
