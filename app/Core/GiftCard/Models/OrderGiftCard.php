<?php

declare(strict_types=1);

namespace App\Core\GiftCard\Models;

use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 注文に使ったギフトカードと額（ADR 0076）。注文を作る時に 1 回だけ書き、後から変えない（DB も断る）。
 * お支払いの額（決済代行で払ってもらう額）は、注文の合計からこの額を引いた額（dueOf）。支払いの画面・知らせの照らし合わせ・手段の決め方はこの額で
 *
 * @property string $transaction_id
 * @property string $gift_card_id
 * @property int $amount
 * @property CarbonImmutable $created_at
 */
final class OrderGiftCard extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'order_gift_cards';

    protected $primaryKey = 'transaction_id';

    protected $keyType = 'string';

    protected $fillable = ['transaction_id', 'gift_card_id', 'amount'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * 注文に使ったギフトカードの額（使っていなければ 0）
     */
    public static function appliedTo(string $transactionId): int
    {
        return (int) self::query()->whereKey($transactionId)->value('amount');
    }

    /**
     * 決済代行で払ってもらう額（注文の合計 − ギフトカードで払う額。0 なら払いは要らない）
     */
    public static function dueOf(Transaction $transaction): int
    {
        return (int) $transaction->total_amount - self::appliedTo($transaction->id);
    }
}
