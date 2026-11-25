<?php

declare(strict_types=1);

namespace App\Core\Payment\Stripe;

use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\CheckoutSession;
use App\Core\Payment\CheckoutSnapshot;
use App\Core\Payment\Contracts\PaymentCredentials;
use App\Core\Payment\Contracts\PaymentDriver;
use App\Core\Payment\Contracts\StoredPaymentDriver;
use App\Core\Payment\RefundResult;
use App\Core\Payment\SavedCard;
use App\Core\Payment\StoredCharge;
use App\Core\Payment\StripeCredentials;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Stripe の決済ドライバ（ADR 0026、最初のドライバ）。核の口（PaymentDriver）を、今の StripeCheckout につなぐ。
 * 鍵は Stripe のものだけを受け付け、代行ごとの答えを核の値（CheckoutSnapshot）に正規化する。預けたカードの口（ADR 0045）も持つ
 */
final class StripeDriver implements PaymentDriver, StoredPaymentDriver
{
    public function __construct(
        private readonly StripeCheckout $checkout,
        private readonly StripeStored $stored,
    ) {}

    public function openCheckout(PaymentCredentials $credentials, Transaction $transaction, string $successUrl, string $cancelUrl, string $idempotencyKey, CarbonImmutable $expiresAt, ?CheckoutMethods $methods = null): CheckoutSession
    {
        return $this->checkout->create($this->stripe($credentials), $transaction, $successUrl, $cancelUrl, $idempotencyKey, $expiresAt, methods: $methods ?? CheckoutMethods::cardOnly());
    }

    public function retrieveCheckout(PaymentCredentials $credentials, string $ref): ?CheckoutSnapshot
    {
        $session = $this->checkout->retrieve($this->stripe($credentials), $ref);

        return $session === null ? null : new CheckoutSnapshot(
            status: $session['status'],
            paid: $session['payment_status'] === 'paid',
            amount: $session['amount_total'],
            currency: $session['currency'],
            transactionRef: $session['client_reference_id'],
            paymentRef: $session['payment_intent'],
            method: $session['method'],
            // 画面は終わったが払われていない、支払いはまだ払える（コンビニ払いの払込票を出した・お店で払っている途中）
            awaiting: $session['status'] === 'complete' && $session['payment_status'] === 'unpaid' && in_array($session['intent_status'], ['requires_action', 'processing'], true),
            payBy: $session['pay_by'] === null ? null : CarbonImmutable::createFromTimestamp($session['pay_by']),
            instructionsUrl: $session['instructions_url'],
        );
    }

    public function cancelPayment(PaymentCredentials $credentials, string $paymentRef): string
    {
        return $this->stored->cancel($this->stripe($credentials), $paymentRef);
    }

    public function refund(PaymentCredentials $credentials, string $paymentRef, int $amount, string $idempotencyKey, ?string $reference = null): RefundResult
    {
        $refund = $this->checkout->refund($this->stripe($credentials), $paymentRef, $amount, $idempotencyKey, $reference);

        return new RefundResult($refund['id'], $refund['status'], $refund['failure_reason'], $refund['amount'], refundId: $refund['refund_id']);
    }

    public function retrieveRefund(PaymentCredentials $credentials, string $ref): ?RefundResult
    {
        $refund = $this->checkout->retrieveRefund($this->stripe($credentials), $ref);

        return $refund === null ? null : new RefundResult($refund['id'], $refund['status'], $refund['failure_reason'], $refund['amount'], refundId: $refund['refund_id']);
    }

    public function expireCheckout(PaymentCredentials $credentials, string $ref): void
    {
        $this->checkout->expire($this->stripe($credentials), $ref);
    }

    public function createCustomer(PaymentCredentials $credentials, string $customerAccountId, string $idempotencyKey): string
    {
        return $this->stored->createCustomer($this->stripe($credentials), $customerAccountId, $idempotencyKey);
    }

    public function openCheckoutSavingCard(PaymentCredentials $credentials, Transaction $transaction, string $successUrl, string $cancelUrl, string $idempotencyKey, CarbonImmutable $expiresAt, string $customerRef): CheckoutSession
    {
        return $this->checkout->create($this->stripe($credentials), $transaction, $successUrl, $cancelUrl, $idempotencyKey, $expiresAt, $customerRef);
    }

    public function openCardSetup(PaymentCredentials $credentials, string $customerRef, string $successUrl, string $cancelUrl, string $idempotencyKey, CarbonImmutable $expiresAt, string $setupRef): CheckoutSession
    {
        return $this->stored->openSetup($this->stripe($credentials), $customerRef, $successUrl, $cancelUrl, $idempotencyKey, $expiresAt, $setupRef);
    }

    public function savedCardOfSetup(PaymentCredentials $credentials, string $setupRef): ?SavedCard
    {
        return $this->stored->savedCardOfSetup($this->stripe($credentials), $setupRef);
    }

    public function savedCardOf(PaymentCredentials $credentials, string $paymentRef): ?SavedCard
    {
        return $this->stored->savedCardOf($this->stripe($credentials), $paymentRef);
    }

    public function chargeStored(PaymentCredentials $credentials, string $customerRef, string $methodRef, int $amount, string $currency, string $idempotencyKey, string $transactionRef): StoredCharge
    {
        return $this->stored->charge($this->stripe($credentials), $customerRef, $methodRef, $amount, $currency, $idempotencyKey, $transactionRef);
    }

    public function cancelStoredCharge(PaymentCredentials $credentials, string $chargeRef): string
    {
        return $this->stored->cancel($this->stripe($credentials), $chargeRef);
    }

    public function detachStored(PaymentCredentials $credentials, string $methodRef): void
    {
        $this->stored->detach($this->stripe($credentials), $methodRef);
    }

    private function stripe(PaymentCredentials $credentials): StripeCredentials
    {
        return $credentials instanceof StripeCredentials
            ? $credentials
            : throw new InvalidArgumentException('Stripe のドライバに、Stripe 以外の鍵を渡しました。');
    }
}
