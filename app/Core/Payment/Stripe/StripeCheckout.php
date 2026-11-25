<?php

declare(strict_types=1);

namespace App\Core\Payment\Stripe;

use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\CheckoutSession;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\RefundDeclined;
use App\Core\Payment\StripeCredentials;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Stripe\BaseStripeClient;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe Checkout のドライバ（ADR 0019）。支払いの画面を作るだけで、カードの情報には触れない。
 *
 * - 金額はこちらの取引から作る。明細の合計が取引の合計と合わなければ（値引き・税抜きの取引など）、合計 1 行で渡す
 * - ギフトカードで一部を払う注文（ADR 0076）は、払ってもらう額（合計 − ギフトカードの額）の 1 行で渡す
 * - Stripe が答えた合計が払ってもらう額と違えば、その画面へは送らない
 * - 冪等キー（取引と何回目か）で、同時に押されたり送り直したりしても画面は 1 つ
 * - お客さんのメールなどの個人情報は渡さない（Stripe の画面で入力してもらう）
 */
final class StripeCheckout
{
    /** 返金に載せる、こちらの返金の記録の ID の名前（ADR 0048） */
    public const string REFUND_KEY = 'ec_sugar_refund';

    public function __construct(private readonly StripeApi $api) {}

    /**
     * 冪等キーと有効期限は、呼ぶ側が頼む前に記録したもの。送り直しても中身が変わらない
     */
    public function create(StripeCredentials $credentials, Transaction $transaction, string $successUrl, string $cancelUrl, string $idempotencyKey, CarbonImmutable $expiresAt, ?string $saveForCustomer = null, ?CheckoutMethods $methods = null): CheckoutSession
    {
        // カードを預ける支払いはカードだけ（コンビニ払いと PayPay は預けられない。ADR 0058）
        $methods = $saveForCustomer !== null ? CheckoutMethods::cardOnly() : ($methods ?? CheckoutMethods::cardOnly());
        $params = [
            'mode' => 'payment',
            // 出す手段はダッシュボードで有効にしたもので、この注文で出さない手段と核が扱えない手段を外す（ADR 0058。2026-09-30 の API から
            // payment_method_types は渡せない）。Apple Pay・Google Pay はカードの中で、ダッシュボードで有効なら Stripe の画面が出す
            'excluded_payment_method_types' => $methods->excluded(),
            'line_items' => $this->lineItems($transaction),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $transaction->id,
            'metadata' => ['transaction_id' => $transaction->id, 'tenant' => (string) tenant()?->getTenantKey()],
            'payment_intent_data' => ['metadata' => ['transaction_id' => $transaction->id, 'tenant' => (string) tenant()?->getTenantKey()]],
            'expires_at' => $expiresAt->getTimestamp(),
            'locale' => 'ja',
        ];

        // コンビニ払いの払いの期限（何日後の 23:59:59 まで。日本時間）
        if ($methods->has('konbini')) {
            $params['payment_method_options'] = ['konbini' => ['expires_after_days' => $methods->konbiniDays]];
        }

        // 次回からこのカードで（定期便の 1 回目。ADR 0045 の 3）: 預ける先（Customer）に付け、お客さんのいない請求に使えるようにする
        if ($saveForCustomer !== null) {
            $params['customer'] = $saveForCustomer;
            $params['payment_intent_data']['setup_future_usage'] = 'off_session';
        }

        $session = $this->api->call(fn () => $this->api->client($credentials->secretKey)->checkout->sessions->create($params, $this->options($credentials, ['idempotency_key' => $idempotencyKey])), 'Stripe に支払いの画面を作れなかった');

        if (! is_string($session->url) || ! self::isCheckoutUrl($session->url) || (int) $session->amount_total !== OrderGiftCard::dueOf($transaction)) {
            Log::error('Stripe の支払いの画面の応答が取引と合わない', ['transaction' => $transaction->id, 'session' => $session->id]);

            throw new PaymentUnavailableException('お支払いの画面を用意できませんでした。時間をおいて、もう一度お試しください。');
        }

        return new CheckoutSession($session->id, $session->url, (int) $session->amount_total, CarbonImmutable::createFromTimestamp((int) $session->expires_at));
    }

    /**
     * 支払いの画面の今の状態を Stripe に問い合わせる（鍵で問い合わせるので、お客さんの画面と違い信じてよい）。届かなければ null
     *
     * @return array{status: string|null, payment_status: string|null, amount_total: int|null, currency: string|null, client_reference_id: string|null, payment_intent: string|null, intent_status: string|null, method: string|null, pay_by: int|null, instructions_url: string|null}|null
     */
    public function retrieve(StripeCredentials $credentials, string $ref): ?array
    {
        try {
            // 支払い（PaymentIntent）と手段も一緒に読む（払われた手段・コンビニ払いの払いの期限と払込票の画面。ADR 0058）
            $session = $this->api->call(fn () => $this->api->client($credentials->secretKey)->checkout->sessions->retrieve($ref, ['expand' => ['payment_intent.payment_method']], $this->options($credentials)), 'Stripe に支払いの画面を問い合わせられなかった')->toArray();
        } catch (PaymentUnavailableException) {
            return null;
        }

        $text = static fn (string $key): ?string => is_string($session[$key] ?? null) ? $session[$key] : null;
        $intent = is_array($session['payment_intent'] ?? null) ? $session['payment_intent'] : [];
        $konbini = $intent['next_action']['konbini_display_details'] ?? null;
        $konbini = is_array($konbini) ? $konbini : [];

        return [
            'status' => $text('status'),
            'payment_status' => $text('payment_status'),
            'amount_total' => is_int($session['amount_total'] ?? null) ? $session['amount_total'] : null,
            'currency' => $text('currency'),
            'client_reference_id' => $text('client_reference_id'),
            'payment_intent' => self::paymentIntentId($session['payment_intent'] ?? null),
            'intent_status' => is_string($intent['status'] ?? null) ? $intent['status'] : null,
            'method' => self::methodOf($intent['payment_method'] ?? null),
            'pay_by' => is_int($konbini['expires_at'] ?? null) ? $konbini['expires_at'] : null,
            'instructions_url' => self::instructionsUrl($konbini['hosted_voucher_url'] ?? null),
        ];
    }

    /**
     * 払われた手段の名前（CheckoutMethods::LABELS の鍵）。カードの中の Apple Pay・Google Pay は分ける。読めなければ null
     */
    private static function methodOf(mixed $method): ?string
    {
        if (! is_array($method) || ! is_string($method['type'] ?? null)) {
            return null;
        }

        if ($method['type'] === 'card') {
            $wallet = $method['card']['wallet']['type'] ?? null;

            return in_array($wallet, ['apple_pay', 'google_pay'], true) ? $wallet : 'card';
        }

        return in_array($method['type'], ['konbini', 'paypay'], true) ? $method['type'] : 'other';
    }

    /**
     * 払込票の画面の URL。Stripe の https の画面だけ（ほかの宛先にお客さんを送らない）
     */
    private static function instructionsUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2000 || ! str_starts_with($url, 'https://')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && ($host === 'stripe.com' || str_ends_with($host, '.stripe.com')) ? $url : null;
    }

    /**
     * 支払いを返金する（冪等キーで同じ返金は 1 回だけ。接続した店は店の連結アカウントで）。返すのは Stripe の返金の ID と状態（ADR 0027）。
     * 状態は succeeded・pending（保留。残高不足などで後から決まる。requires_action も含む）・failed（canceled も含む）に正規化する。
     * Stripe がはっきり断ったら（4xx。409 と 429 を除く）RefundDeclined。答えが分からなければ（通信の失敗・5xx・409・429）PaymentUnavailableException で、
     * 同じ冪等キーでやり直せば Stripe は同じ返金を返す
     *
     * @return array{id: string, status: string, failure_reason: string|null, amount: int|null, payment_ref: string|null, refund_id: string|null}
     */
    public function refund(StripeCredentials $credentials, string $paymentIntent, int $amount, string $idempotencyKey, ?string $reference = null): array
    {
        // こちらの返金の記録の ID を返金に載せる（知らせをそれで当てる。同じ額の返金の取り違えを防ぐ。ADR 0048）
        $params = ['payment_intent' => $paymentIntent, 'amount' => $amount] + ($reference === null ? [] : ['metadata' => [self::REFUND_KEY => $reference]]);

        try {
            $refund = $this->api->call(
                fn () => $this->api->client($credentials->secretKey)->refunds->create($params, $this->options($credentials, ['idempotency_key' => $idempotencyKey])),
                'Stripe で返金できなかった',
                'Stripe で返金できませんでした。時間をおいて、もう一度お試しください。',
            );
        } catch (PaymentUnavailableException $e) {
            $previous = $e->getPrevious();

            if ($previous instanceof ApiErrorException && self::declined($previous->getHttpStatus())) {
                throw new RefundDeclined(mb_substr((string) ($previous->getStripeCode() ?? 'declined'), 0, 64));
            }

            throw $e;
        }

        // 頼んだ額と違う返金（起きないはず）は、額をそのまま返し、核が記録を止めて人が見る（知らせ・問い合わせも同じ所で見る）
        return self::normalizeRefund($refund->toArray());
    }

    /**
     * 返金の今の状態を Stripe に問い合わせる（定期の問い合わせ。ADR 0027）。届かなければ・見つからなければ null
     *
     * @return array{id: string, status: string, failure_reason: string|null, amount: int|null, payment_ref: string|null, refund_id: string|null}|null
     */
    public function retrieveRefund(StripeCredentials $credentials, string $ref): ?array
    {
        try {
            $refund = $this->api->call(fn () => $this->api->client($credentials->secretKey)->refunds->retrieve($ref, [], $this->options($credentials)), 'Stripe に返金を問い合わせられなかった');
        } catch (PaymentUnavailableException) {
            return null;
        }

        return self::normalizeRefund($refund->toArray());
    }

    /**
     * Stripe の返金（答え・問い合わせ・知らせの中身）を、核の 3 つの状態に正規化する。
     * succeeded・pending（requires_action も含む）・failed（canceled も含む）
     *
     * @param  array<string, mixed>  $data
     * @return array{id: string, status: string, failure_reason: string|null, amount: int|null, payment_ref: string|null, refund_id: string|null}
     */
    public static function normalizeRefund(array $data): array
    {
        $status = match ($data['status'] ?? null) {
            'succeeded' => 'succeeded',
            'pending', 'requires_action' => 'pending',
            default => 'failed',
        };
        $reason = is_string($data['failure_reason'] ?? null) ? mb_substr($data['failure_reason'], 0, 64) : null;

        return [
            'id' => is_string($data['id'] ?? null) ? $data['id'] : '',
            'status' => $status,
            'failure_reason' => $status === 'failed' ? ($reason ?? mb_substr((string) ($data['status'] ?? 'unknown'), 0, 64)) : null,
            'amount' => is_int($data['amount'] ?? null) ? $data['amount'] : null,
            // 返金した支払い（pi_）。展開されていれば中の id（外でした返金を取引に引き当てる）
            'payment_ref' => self::paymentIntentId($data['payment_intent'] ?? null),
            // こちらの返金の記録の ID（頼んだ時に載せた。ULID の形だけを受ける）
            'refund_id' => is_array($data['metadata'] ?? null) && is_string($data['metadata'][self::REFUND_KEY] ?? null) && preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $data['metadata'][self::REFUND_KEY]) === 1
                ? $data['metadata'][self::REFUND_KEY] : null,
        ];
    }

    private static function paymentIntentId(mixed $value): ?string
    {
        $id = is_array($value) ? ($value['id'] ?? null) : $value;

        return is_string($id) && preg_match('/\Api_[A-Za-z0-9_]{1,250}\z/', $id) === 1 ? $id : null;
    }

    /**
     * はっきり断られた（やり直しても同じ）か。409（同じ冪等キーの処理中）と 429（回数制限）はやり直せるので含めない
     */
    private static function declined(?int $status): bool
    {
        return $status !== null && $status >= 400 && $status < 500 && ! in_array($status, [409, 429], true);
    }

    /**
     * まだ払える支払いの画面を閉じる（取り消した注文が後から払われないように）
     */
    public function expire(StripeCredentials $credentials, string $ref): void
    {
        $this->api->call(
            fn () => $this->api->client($credentials->secretKey)->checkout->sessions->expire($ref, [], $this->options($credentials)),
            'Stripe の支払いの画面を閉じられなかった',
            'Stripe の支払いの画面を閉じられませんでした。時間をおいて、もう一度お試しください。',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lineItems(Transaction $transaction): array
    {
        $currency = strtolower($transaction->currency);
        // 払ってもらう額（ギフトカードで払う分を引いた額。ADR 0076）。明細の合計と合わなければ 1 行で渡す
        $due = OrderGiftCard::dueOf($transaction);
        $lines = $transaction->lines()->orderBy('position')->get();
        $items = [];

        foreach ($lines as $line) {
            // 0 円の調整の行は、決済の明細に出さない（名入れの文字だけを持つオプションの行。ADR 0036。のし「無料」など App の料金の行。ADR 0078）
            if ($line->amount === 0 && $line->line_type !== LineType::Item) {
                continue;
            }

            if ($line->amount <= 0) {
                $items = null;
                break;
            }

            // 数で割り切れる行は「単価 × 数」で、そうでない行は 1 行の金額で
            [$unit, $quantity] = $line->quantity > 0 && $line->amount === $line->unit_amount * $line->quantity
                ? [$line->unit_amount, $line->quantity]
                : [$line->amount, 1];
            $items[] = ['price_data' => ['currency' => $currency, 'unit_amount' => $unit, 'product_data' => ['name' => mb_substr($line->description, 0, 200)]], 'quantity' => $quantity];
        }

        $sum = array_sum(array_map(static fn (array $item): int => $item['price_data']['unit_amount'] * $item['quantity'], $items ?? []));

        if ($items === null || $items === [] || $sum !== $due) {
            return [['price_data' => ['currency' => $currency, 'unit_amount' => $due, 'product_data' => ['name' => 'ご注文 '.strtoupper($transaction->id)]], 'quantity' => 1]];
        }

        return $items;
    }

    /**
     * お客さんをリダイレクトしてよいのは Stripe の支払いの画面だけ（テストの偽物を使うときは、その偽物の画面だけ）
     */
    private static function isCheckoutUrl(string $url): bool
    {
        if (str_starts_with($url, 'https://checkout.stripe.com/')) {
            return true;
        }

        $base = StripeApi::apiBase();

        return $base !== BaseStripeClient::DEFAULT_API_BASE && str_starts_with($url, rtrim($base, '/').'/');
    }

    /**
     * つないだ店（Connect）なら、店の連結アカウントで作る（ダイレクト支払い。ADR 0022）
     *
     * @param  array{idempotency_key?: string}  $options
     * @return array{idempotency_key?: string, stripe_account?: string}
     */
    private function options(StripeCredentials $credentials, array $options = []): array
    {
        return $credentials->accountId === null ? $options : $options + ['stripe_account' => $credentials->accountId];
    }
}
