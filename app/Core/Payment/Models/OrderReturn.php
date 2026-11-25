<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use App\Core\Payment\Enums\ReturnReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * 返金の 1 回（返品・一部返金・済んだ注文の返金。ADR 0048）。追記だけ（DB も直す・消すを断る）。
 * 何を返したかは返金の行（RefundLine）、お金の動きは返金の記録（Refund の return_id。断られたら同じ 1 回に頼み直しの記録が足される）
 *
 * @property string $id
 * @property string $transaction_id
 * @property ReturnReason $reason
 * @property int $amount 返す額の合計（税込み）
 * @property list<array{rate_bp: int, gross: int, tax: int}> $taxes 税率ごとの返す額と消費税
 * @property string|null $actor
 * @property CarbonImmutable $created_at
 */
final class OrderReturn extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'order_returns';

    protected $fillable = ['transaction_id', 'reason', 'amount', 'taxes', 'actor'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('返金の記録は書き換えられません'));
        self::deleting(static fn (): never => throw new LogicException('返金の記録は消せません'));
    }

    protected function casts(): array
    {
        return [
            'reason' => ReturnReason::class,
            'amount' => 'integer',
            'taxes' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<RefundLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RefundLine::class, 'return_id');
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'return_id');
    }
}
