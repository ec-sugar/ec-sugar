<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use App\Core\Trace\RecordsTrace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 返金の記録（ADR 0027）。変えるのは PaymentRefunds だけ
 *
 * @property string $id
 * @property string $transaction_id
 * @property string|null $attempt_id 決済代行の支払い（外で返した記録には無いことがある）
 * @property string|null $stray_payment_id
 * @property string $purpose cancel | stray | external（決済代行の画面で直接した返金。ADR 0027 の追記）| shipping（贈り物の送料の差額。ADR 0064）| return（返品・一部返金。ADR 0048）
 * @property string|null $return_id 返金の 1 回（返品・一部返金と、記録した外でした返金。ADR 0048）
 * @property string $channel provider（決済代行に頼む）| outside（店が外で返した記録だけ。返品・一部返金のとき）
 * @property int $amount
 * @property string $currency
 * @property string|null $payment_ref 返金する支払いの ID（決済代行に頼む時だけ）
 * @property string $idempotency_key
 * @property string|null $provider_ref
 * @property string $status requested | pending | held | succeeded | failed | reversed
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $settled_at
 * @property string|null $resolution 止めた返金の片付け（recorded・dismissed）と、断られた返金（送料の差額・取消・返品）を頼み直した印（retried。ADR 0064・0048）
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $checked_at 定期の確かめで最後に見た時刻（見回りの順番のためだけ）
 * @property CarbonImmutable $created_at
 * @property string|null $trace_id 返金を頼んだ処理の ID（冪等キーの横に。ADR 0087）
 */
final class Refund extends Model
{
    use HasUlids;
    use RecordsTrace;

    /** 途中の返金（この間は発送・完了・新しい返金を断る）。held は額が頼んだ額と違ったので止めて人が見ている */
    public const array ACTIVE = ['requested', 'pending', 'held'];

    protected $table = 'refunds';

    protected $fillable = ['transaction_id', 'attempt_id', 'stray_payment_id', 'purpose', 'amount', 'currency', 'payment_ref', 'idempotency_key', 'provider_ref', 'status', 'failure_reason', 'settled_at', 'resolution', 'resolved_at', 'return_id', 'channel'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'settled_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
