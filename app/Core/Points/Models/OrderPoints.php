<?php

declare(strict_types=1);

namespace App\Core\Points\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 注文ごとのポイントの決まり（ADR 0068）。注文を作る時に 1 回だけ書き、後から変えない（DB も断る）。
 * spent は使ったポイント、earn は発送で付く予定のポイント（注文の時の率 rate_bp で earn_base から切り捨て）。
 * allocations は使ったポイントの明細の位置ごとの割り振り（商品・オプション・送料の行。一部の返金で使う）
 *
 * @property string $transaction_id
 * @property string $customer_account_id
 * @property int $rate_bp
 * @property int $spent
 * @property int $earn
 * @property int $earn_base
 * @property array<int|string, int> $allocations
 * @property CarbonImmutable $created_at
 */
final class OrderPoints extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'order_points';

    protected $primaryKey = 'transaction_id';

    protected $keyType = 'string';

    protected $fillable = ['transaction_id', 'customer_account_id', 'rate_bp', 'spent', 'earn', 'earn_base', 'allocations'];

    protected function casts(): array
    {
        return [
            'rate_bp' => 'integer',
            'spent' => 'integer',
            'earn' => 'integer',
            'earn_base' => 'integer',
            'allocations' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
