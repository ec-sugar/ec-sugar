<?php

declare(strict_types=1);

namespace App\Core\Payment\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use App\Core\Crypto\Casts\TenantEncryptedJson;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 支払いの試み（決済代行の支払いの画面 1 つ）。変えるのは PaymentService だけ。
 * 店が受け取る支払い（銀行振込・代金引換。ADR 0074）は provider・kind が direct の記録で、1 つの注文に 1 つ（変えるのは DirectPayments）
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $provider
 * @property string $provider_ref
 * @property string $checkout_url
 * @property int $amount
 * @property string $currency
 * @property string $status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property string|null $stripe_account 作ったアカウント（つないだ店の連結アカウント。店の鍵なら null）
 * @property bool $test_mode テストのモードで作った（返金・問い合わせ・通知の突き合わせは、このモードの鍵で。ADR 0096）
 * @property string $kind checkout（支払いの画面）・stored（預けたカードの請求）
 * @property string $methods 支払いの画面に出した手段（CheckoutMethods の記録の形。ADR 0058）
 * @property string|null $method 払われた・払う途中の手段（分かったときだけ。カードだけの画面では null）
 * @property CarbonImmutable|null $pay_by 入金待ち（awaiting）の払いの期限
 * @property string|null $instructions_url 入金待ちの払い方の案内の画面（決済代行の画面。店の鍵で暗号化）
 * @property array{bank: string, branch: string, type: string, number: string, holder: string}|null $bank_account 銀行振込の振込先（注文の時の写し。店の鍵で暗号化）
 * @property string|null $leftover_checked_at 払われた注文に残った画面の見回りが最後に確かめた時刻（見回りの順番のためだけの印）
 */
final class PaymentAttempt extends Model
{
    use HasUlids;

    protected $table = 'payment_attempts';

    protected $fillable = ['transaction_id', 'provider', 'provider_ref', 'checkout_url', 'amount', 'currency', 'status', 'expires_at', 'paid_at', 'stripe_account', 'saves_card_for', 'kind', 'charge_key', 'methods', 'method', 'pay_by', 'instructions_url', 'bank_account', 'test_mode'];

    /** 払込票の画面の URL は、持つ人が払い方を見られるので、平文で置かない */
    protected $hidden = ['instructions_url', 'bank_account'];

    /**
     * この取引の支払いが済んでいるか（払われた支払いの画面がある）
     */
    public static function paid(string $transactionId): bool
    {
        return self::query()->where('transaction_id', $transactionId)->where('status', 'paid')->exists();
    }

    /**
     * 後から払う手段で、お客さんが払うのを待っている支払い（ADR 0058）
     */
    public static function awaiting(string $transactionId): ?self
    {
        return self::query()->where('transaction_id', $transactionId)->where('status', 'awaiting')->latest('created_at')->first();
    }

    /**
     * 店が受け取る支払いの記録（銀行振込・代金引換）。無ければ null
     */
    public static function direct(string $transactionId): ?self
    {
        return self::query()->where('transaction_id', $transactionId)->where('kind', 'direct')->first();
    }

    /**
     * 払われた・払う途中の手段（カードだけを出した画面はカード）。分からなければ null
     */
    public function paymentMethod(): ?string
    {
        return $this->method ?? ($this->kind === 'stored' || $this->methods === 'card' ? 'card' : null);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'test_mode' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'pay_by' => 'immutable_datetime',
            'instructions_url' => TenantEncrypted::class,
            'bank_account' => TenantEncryptedJson::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
