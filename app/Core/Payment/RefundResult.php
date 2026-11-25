<?php

declare(strict_types=1);

namespace App\Core\Payment;

/**
 * 決済代行が返金に答えた結果（ADR 0027）。状態は succeeded・pending（保留。後で決まる）・failed の 3 つに正規化する。
 * 額は代行が言う返金の額（分からなければ null）。頼んだ額と違えば、記録は止めて人が見る
 */
final readonly class RefundResult
{
    public function __construct(
        // 代行の返金の ID（Stripe なら re_）
        public string $ref,
        public string $status,
        // 失敗の理由（代行の符号。個人情報は入れない）
        public ?string $failureReason = null,
        // 代行が言う返金の額（最小通貨単位）
        public ?int $amount = null,
        // 返金した支払いの代行の ID（Stripe なら pi_。外でした返金を取引に引き当てるため。分からなければ null）
        public ?string $paymentRef = null,
        // こちらの返金の記録の ID（頼んだ時に代行の返金に載せたもの。ADR 0048。無ければ null）
        public ?string $refundId = null,
    ) {}
}
