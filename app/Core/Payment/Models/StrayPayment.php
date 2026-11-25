<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 返金が要る支払い（二重に払われた・取り消した注文に届いた）。変えるのは PaymentService だけ
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $attempt_id
 * @property string $reason
 * @property int $amount
 * @property string|null $payment_ref
 * @property string $status
 * @property string|null $refund_ref
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable $created_at
 */
final class StrayPayment extends Model
{
    use HasUlids;

    protected $table = 'stray_payments';

    protected $fillable = ['transaction_id', 'attempt_id', 'reason', 'amount', 'payment_ref', 'status', 'refund_ref', 'refunded_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'refunded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
