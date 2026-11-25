<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use App\Core\Trace\RecordsTrace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 支払いの画面を頼む中身の記録。変えるのは PaymentService だけ
 *
 * @property string $id
 * @property string $transaction_id
 * @property int $attempt 何回目の画面か
 * @property string $idempotency_key
 * @property CarbonImmutable $expires_at
 * @property string|null $attempt_id 記録できた支払いの画面（まだなら null）
 * @property string $methods 出す支払いの手段（CheckoutMethods の記録の形。ADR 0058）
 * @property string|null $trace_id 支払いの画面を頼んだ処理の ID（冪等キーの横に。ADR 0087）
 */
final class CheckoutPlan extends Model
{
    use HasUlids;
    use RecordsTrace;

    protected $table = 'checkout_plans';

    /** 記録の前の予約を「作っている途中」とみなす時間（分）。Stripe に頼んでいる間だけ。答えの届かなかった予約の画面は誰にも渡っていない */
    public const int IN_FLIGHT_MINUTES = 3;

    protected $fillable = ['transaction_id', 'attempt', 'idempotency_key', 'expires_at', 'attempt_id', 'saves_card_for', 'methods'];

    /**
     * 今まさに決済代行に支払いの画面を頼んでいる途中か（記録の前の予約が、ここ数分に作られた・使い回された）。
     * 答えの届かなかった古い予約は数えない（その画面は誰にも渡っていない）
     */
    public static function inFlight(string $transactionId): bool
    {
        return self::query()->where('transaction_id', $transactionId)->whereNull('attempt_id')
            ->where('expires_at', '>', CarbonImmutable::now())
            ->where('updated_at', '>', CarbonImmutable::now()->subMinutes(self::IN_FLIGHT_MINUTES))->exists();
    }

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
