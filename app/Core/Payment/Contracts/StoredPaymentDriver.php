<?php

declare(strict_types=1);

namespace App\Core\Payment\Contracts;

use App\Core\Payment\CheckoutSession;
use App\Core\Payment\SavedCard;
use App\Core\Payment\StoredCharge;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * 預けたカードで請求する口（ADR 0045 の 1）。PaymentDriver とは別の任意の口で、持たない決済代行の店では定期便を使えない。
 * カードの番号には触れない（預けるのは決済代行の支払いの画面の中）
 */
interface StoredPaymentDriver
{
    /**
     * カードを預ける先を作る（Stripe の Customer）。渡すのはお客さんの台帳の ID だけ（メールなどの個人情報は渡さない）。冪等キーで 1 つ
     */
    public function createCustomer(PaymentCredentials $credentials, string $customerAccountId, string $idempotencyKey): string;

    /**
     * 支払いの画面を「次回からこのカードで」の形で開く（ADR 0045 の 3）。中身は openCheckout と同じ
     */
    public function openCheckoutSavingCard(PaymentCredentials $credentials, Transaction $transaction, string $successUrl, string $cancelUrl, string $idempotencyKey, CarbonImmutable $expiresAt, string $customerRef): CheckoutSession;

    /**
     * カードを替える画面（支払いはせず、カードだけを預ける。Stripe なら Checkout の setup のモード）。$setupRef は核の記録の ID（知らせで引き当てる）
     */
    public function openCardSetup(PaymentCredentials $credentials, string $customerRef, string $successUrl, string $cancelUrl, string $idempotencyKey, CarbonImmutable $expiresAt, string $setupRef): CheckoutSession;

    /**
     * カードを替える画面で預けたカード。済んでいない・お客さんのいない請求に使えない形なら null。問い合わせられなければ PaymentUnavailableException（送り直しを待つ）
     */
    public function savedCardOfSetup(PaymentCredentials $credentials, string $setupRef): ?SavedCard;

    /**
     * 払われた支払いで預けたカード（ブランド・末尾 4 桁・期限）。届かない・預けていなければ null
     */
    public function savedCardOf(PaymentCredentials $credentials, string $paymentRef): ?SavedCard;

    /**
     * お客さんのいない請求。冪等キー（回の ID）で同じ回は 1 回だけ。確定は Webhook
     */
    public function chargeStored(PaymentCredentials $credentials, string $customerRef, string $methodRef, int $amount, string $currency, string $idempotencyKey, string $transactionRef): StoredCharge;

    /**
     * 開いたままの請求（本人確認待ち・処理中など）を取り消す。答えは 'cancelled'（取り消した・もう取り消されていた・無い）、
     * 'succeeded'（もう払われていた）、'processing'（今は取り消せない。後で）。問い合わせられなければ PaymentUnavailableException
     */
    public function cancelStoredCharge(PaymentCredentials $credentials, string $chargeRef): string;

    /**
     * 預けたカードを外す（解約・退会・カードを替えた時）
     */
    public function detachStored(PaymentCredentials $credentials, string $methodRef): void;
}
