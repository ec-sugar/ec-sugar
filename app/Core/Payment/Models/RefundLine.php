<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * 返金の行（ADR 0048）。どの明細の何個を・いくら・どの税率で返したか、在庫に戻したか。追記だけ。
 * 商品の行は返した数の合計が明細の数を、どの行も額の合計が明細の額を越えない（DB のトリガーも断る）
 *
 * @property string $id
 * @property string $return_id
 * @property string $transaction_id
 * @property string $kind item（商品）| addon（オプション）| shipping（送料）| amount（金額だけ）
 * @property int|null $line_position 明細の位置（金額だけの行は null）
 * @property int $quantity 商品は返した数、オプションは従う商品の数、送料と金額だけは 0
 * @property int $amount 返す額（税込み。値引き・使ったポイントの割り振りを引いた実の額）
 * @property int $tax_rate_bp
 * @property bool $restock 在庫に戻した
 * @property CarbonImmutable $created_at
 */
final class RefundLine extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'refund_lines';

    protected $fillable = ['return_id', 'transaction_id', 'kind', 'line_position', 'quantity', 'amount', 'tax_rate_bp', 'restock'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('返金の行は書き換えられません'));
        self::deleting(static fn (): never => throw new LogicException('返金の行は消せません'));
    }

    protected function casts(): array
    {
        return [
            'line_position' => 'integer',
            'quantity' => 'integer',
            'amount' => 'integer',
            'tax_rate_bp' => 'integer',
            'restock' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
