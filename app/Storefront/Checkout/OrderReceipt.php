<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Money\Money;
use App\Core\Payment\PaymentState;
use App\Core\Plugin\Gift\GiftShare;

/**
 * 注文を受け付けた画面に渡す形（テーマ API の一部）。個人情報は入れない。
 * paid は Webhook で確かめた支払い。returned は Stripe の画面から戻ってきた印で、支払いの証拠にはしない（案内の文を変えるだけ）。
 * gift は贈り物の注文の様子（ADR 0055。受け取りのリンクそのものは入れない）。
 * payment は支払いの様子（銀行振込の振込先と期限・代引き。ADR 0074。核の部品 <x-storefront::direct-payment> に渡す）。
 * cancelled は取り消した注文（払われないまま期限が過ぎた、など）。free は払ってもらう額が 0 円で支払いが要らずに確定した注文（合計 0 円。全額をギフトカードで払った注文も。ADR 0076）。cancelledForExpiry は払われないまま期限が過ぎて取り消した（それ以外の取消は運営の取消）
 */
final readonly class OrderReceipt
{
    public function __construct(
        public string $orderId,
        public int $total,
        public bool $paid = false,
        public bool $returned = false,
        public bool $cancelled = false,
        public bool $free = false,
        public bool $cancelledForExpiry = false,
        public ?GiftShare $gift = null,
        public ?PaymentState $payment = null,
    ) {}

    /**
     * 店が受け取る支払い（銀行振込・代引き）の注文で、まだ払われていない（「お支払いへ進む」を出さない。ADR 0074）
     */
    public function direct(): bool
    {
        return ! $this->cancelled && ! $this->paid && $this->payment !== null && ($this->payment->awaitingTransfer() || $this->payment->onDelivery);
    }

    /**
     * お客さんに伝える注文番号（取引の ID を大文字で）
     */
    public function number(): string
    {
        return strtoupper($this->orderId);
    }

    public function totalLabel(): string
    {
        return Money::format($this->total);
    }
}
