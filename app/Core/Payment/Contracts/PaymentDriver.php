<?php

declare(strict_types=1);

namespace App\Core\Payment\Contracts;

use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\CheckoutSession;
use App\Core\Payment\CheckoutSnapshot;
use App\Core\Payment\RefundResult;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * 決済代行の口（ADR 0026）。核のサービスはこれだけを見て、Stripe などの具象を型で参照しない。
 * 鍵（PaymentCredentials）はドライバにだけ渡す。DB のロックの外で呼ぶ（外の遅さでロックを握らない）
 */
interface PaymentDriver
{
    /**
     * 支払いの画面を 1 つ作る。冪等キーと有効期限は呼ぶ側が先に記録したものを渡す。
     * 同じキーには同じ中身で頼むので、同時でも送り直しでも画面は 1 つ。出す手段（ADR 0058）も頼む前に記録したもの
     */
    public function openCheckout(PaymentCredentials $credentials, Transaction $transaction, string $successUrl, string $cancelUrl, string $idempotencyKey, CarbonImmutable $expiresAt, ?CheckoutMethods $methods = null): CheckoutSession;

    /**
     * 支払いの画面から生まれた、まだ払われていない支払いを取り消す（入金待ちのコンビニ払いの払込票を使えなくする。ADR 0058）。
     * 返すのは cancelled（取り消した・もう無い）・succeeded（もう払われていた）・processing（払う途中で、今は取り消せない）。
     * 答えが分からなければ PaymentUnavailableException
     */
    public function cancelPayment(PaymentCredentials $credentials, string $paymentRef): string;

    /**
     * 支払いの画面の今の状態を問い合わせる。届かなければ null
     */
    public function retrieveCheckout(PaymentCredentials $credentials, string $ref): ?CheckoutSnapshot;

    /**
     * 支払いを返金する（冪等キーで同じ返金は 1 回だけ）。返すのは代行の返金の ID と状態（succeeded・pending・failed。ADR 0027）。
     * 代行がはっきり断ったら RefundDeclined、答えが分からなければ（通信の失敗など）PaymentUnavailableException。
     * $reference はこちらの返金の記録の ID（代行の返金に載せ、知らせをそれで当てる。ADR 0048。個人情報は載せない）
     */
    public function refund(PaymentCredentials $credentials, string $paymentRef, int $amount, string $idempotencyKey, ?string $reference = null): RefundResult;

    /**
     * 返金の今の状態を問い合わせる（定期の問い合わせ。ADR 0027）。届かなければ・見つからなければ null
     */
    public function retrieveRefund(PaymentCredentials $credentials, string $ref): ?RefundResult;

    /**
     * まだ払える支払いの画面を閉じる（取り消した注文が後から払われないように）
     */
    public function expireCheckout(PaymentCredentials $credentials, string $ref): void;
}
