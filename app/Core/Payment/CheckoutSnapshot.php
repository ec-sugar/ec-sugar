<?php

declare(strict_types=1);

namespace App\Core\Payment;

use Carbon\CarbonImmutable;

/**
 * 支払いの画面の今の状態（決済代行に鍵で問い合わせた、信じてよい値。ADR 0026）。
 * ドライバが代行ごとの答えをこの形に正規化する。核のサービスはこの値だけを見る
 */
final readonly class CheckoutSnapshot
{
    public function __construct(
        // 画面の状態（open=まだ払える / complete=終わった / expired=期限切れ）
        public ?string $status,
        // 支払いが済んだか
        public bool $paid,
        public ?int $amount,
        public ?string $currency,
        // 取引の参照（client_reference_id。取引の ID を入れている）
        public ?string $transactionRef,
        // 支払いの ID（返金に使う。Stripe なら payment_intent）
        public ?string $paymentRef,
        // 払われた・払う途中の手段（CheckoutMethods::LABELS の鍵。分からなければ null。ADR 0058）
        public ?string $method = null,
        // 画面は終わったが、お客さんが後から払うのを待っている（コンビニ払いの払込票を出した。ADR 0058）
        public bool $awaiting = false,
        // 後から払う手段の払いの期限（代行が決めた時刻）
        public ?CarbonImmutable $payBy = null,
        // 後から払う手段の払い方の案内の画面（代行の画面。Stripe なら払込票の画面）
        public ?string $instructionsUrl = null,
    ) {}

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
