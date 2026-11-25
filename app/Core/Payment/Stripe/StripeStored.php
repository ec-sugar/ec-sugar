<?php

declare(strict_types=1);

namespace App\Core\Payment\Stripe;

use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\CheckoutSession;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\SavedCard;
use App\Core\Payment\StoredCharge;
use App\Core\Payment\StripeCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\UnexpectedValueException;

/**
 * Stripe の預けたカード（ADR 0045）。Customer を作る・払われた支払いのカードを読む・お客さんのいない請求・カードを外す。
 * 渡すのは台帳の ID と金額だけ（メールなどの個人情報は渡さない）。つないだ店は店の連結アカウントで（ADR 0022）
 */
final class StripeStored
{
    public function __construct(private readonly StripeApi $api) {}

    public function createCustomer(StripeCredentials $credentials, string $customerAccountId, string $idempotencyKey): string
    {
        $customer = $this->api->call(
            fn () => $this->api->client($credentials->secretKey)->customers->create(
                ['metadata' => ['customer_account_id' => $customerAccountId, 'tenant' => (string) tenant()?->getTenantKey()]],
                $this->options($credentials, ['idempotency_key' => $idempotencyKey]),
            ),
            'Stripe にカードを預ける先を作れなかった',
        );

        return (string) $customer->id;
    }

    /**
     * カードを替える画面（Checkout の setup のモード）。預ける先に付け、お客さんのいない請求に使える形で預ける。metadata は核の記録の ID と店だけ
     */
    public function openSetup(StripeCredentials $credentials, string $customer, string $successUrl, string $cancelUrl, string $idempotencyKey, CarbonImmutable $expiresAt, string $setupRef): CheckoutSession
    {
        $metadata = ['card_setup' => $setupRef, 'tenant' => (string) tenant()?->getTenantKey()];
        $session = $this->api->call(fn () => $this->api->client($credentials->secretKey)->checkout->sessions->create([
            'mode' => 'setup',
            'currency' => 'jpy',
            // 預けるのはカードだけ（ほかの手段を外す。2026-09-30 の API から payment_method_types は渡せない）
            'excluded_payment_method_types' => CheckoutMethods::cardOnly()->excluded(),
            'customer' => $customer,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $setupRef,
            'metadata' => $metadata,
            'setup_intent_data' => ['metadata' => $metadata],
            'expires_at' => $expiresAt->getTimestamp(),
            'locale' => 'ja',
        ], $this->options($credentials, ['idempotency_key' => $idempotencyKey])), 'Stripe にカードの登録の画面を作れなかった');

        if (! is_string($session->url) || ! str_starts_with($session->url, 'https://checkout.stripe.com/')) {
            throw new PaymentUnavailableException('カードの登録の画面を用意できませんでした。時間をおいて、もう一度お試しください。');
        }

        return new CheckoutSession($session->id, $session->url, 0, CarbonImmutable::createFromTimestamp((int) $session->expires_at));
    }

    /**
     * カードを替える画面で預けたカード（SetupIntent。済んでいて、お客さんのいない請求に使える形のものだけ）。
     * 問い合わせられなかった時は PaymentUnavailableException のまま外へ出す（知らせを失敗で返し、Stripe に送り直させる）
     */
    public function savedCardOfSetup(StripeCredentials $credentials, string $setupIntent): ?SavedCard
    {
        $intent = $this->api->call(
            fn () => $this->api->client($credentials->secretKey)->setupIntents->retrieve($setupIntent, ['expand' => ['payment_method']], $this->options($credentials)),
            'Stripe にカードの登録を問い合わせられなかった',
        )->toArray();

        if (($intent['status'] ?? null) !== 'succeeded' || ($intent['usage'] ?? null) !== 'off_session') {
            return null;
        }

        return self::card($intent['payment_method'] ?? null, $intent['customer'] ?? null);
    }

    /**
     * 払われた支払いで預けたカード。届かない・預けていなければ null
     */
    public function savedCardOf(StripeCredentials $credentials, string $paymentIntent): ?SavedCard
    {
        try {
            $intent = $this->api->call(
                fn () => $this->api->client($credentials->secretKey)->paymentIntents->retrieve($paymentIntent, ['expand' => ['payment_method']], $this->options($credentials)),
                'Stripe に支払いを問い合わせられなかった',
            )->toArray();
        } catch (PaymentUnavailableException) {
            return null;
        }

        if (($intent['setup_future_usage'] ?? null) !== 'off_session') {
            return null;
        }

        return self::card($intent['payment_method'] ?? null, $intent['customer'] ?? null);
    }

    /**
     * 支払いの方法（展開したもの）と預ける先から、預けたカードの形にする（カードの番号は読まない）
     */
    private static function card(mixed $method, mixed $customer): ?SavedCard
    {
        $method = is_array($method) ? $method : null;
        $card = is_array($method['card'] ?? null) ? $method['card'] : [];

        if ($method === null || ! is_string($method['id'] ?? null) || ! is_string($customer)) {
            return null;
        }

        $last4 = is_string($card['last4'] ?? null) && preg_match('/\A[0-9]{4}\z/', $card['last4']) === 1 ? $card['last4'] : null;

        return new SavedCard(
            customerRef: $customer,
            methodRef: $method['id'],
            brand: is_string($card['brand'] ?? null) ? mb_substr($card['brand'], 0, 32) : null,
            last4: $last4,
            expMonth: is_int($card['exp_month'] ?? null) ? $card['exp_month'] : null,
            expYear: is_int($card['exp_year'] ?? null) ? $card['exp_year'] : null,
            fingerprint: is_string($card['fingerprint'] ?? null) && preg_match('/\A[A-Za-z0-9]{1,64}\z/', $card['fingerprint']) === 1 ? $card['fingerprint'] : null,
        );
    }

    /**
     * お客さんのいない請求（off_session で確定まで）。答えは 6 つの状態に分ける（StoredCharge）。例外は外へ出さない。
     * 本人確認は、Stripe は 402・authentication_required・支払いの状態 requires_payment_method で返す（requires_action ではない）
     */
    public function charge(StripeCredentials $credentials, string $customer, string $method, int $amount, string $currency, string $idempotencyKey, string $transactionRef): StoredCharge
    {
        // Stripe の部品の知らせ（E_USER_WARNING）を例外にしない（StripeApi::call と同じ）
        set_error_handler(static function (int $level, string $notice): bool {
            Log::warning('Stripe からの通知', ['notice' => $notice]);

            return true;
        }, E_USER_WARNING);

        try {
            $intent = $this->api->client($credentials->secretKey)->paymentIntents->create([
                'amount' => $amount,
                'currency' => strtolower($currency),
                'customer' => $customer,
                'payment_method' => $method,
                // 預けたカードで、お客さんのいない請求（画面の移動の要る手段は使わない。2026-09-30 の API から payment_method_types は渡せない）
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
                'off_session' => true,
                'confirm' => true,
                'metadata' => ['transaction_id' => $transactionRef, 'tenant' => (string) tenant()?->getTenantKey()],
            ], $this->options($credentials, ['idempotency_key' => $idempotencyKey]))->toArray();
        } catch (CardException $e) {
            $intent = $e->getError()?->payment_intent?->toArray() ?? [];
            $ref = is_string($intent['id'] ?? null) ? $intent['id'] : null;
            $code = mb_substr((string) ($e->getDeclineCode() ?? $e->getStripeCode() ?? 'card_declined'), 0, 64);
            $authentication = in_array('authentication_required', [$e->getStripeCode(), $e->getDeclineCode()], true) || ($intent['status'] ?? null) === 'requires_action';

            return new StoredCharge($ref, $authentication ? 'requires_action' : 'failed', $code);
        } catch (IdempotencyException $e) {
            self::log('同じ冪等キーで違う中身の請求を依頼した（作りの誤り）', $e);

            return new StoredCharge(null, 'rejected', 'idempotency');
        } catch (AuthenticationException|PermissionException $e) {
            self::log('店の Stripe の鍵か連結で請求できない', $e);

            return new StoredCharge(null, 'rejected', 'shop_settings');
        } catch (InvalidRequestException $e) {
            self::log('Stripe が請求を断った', $e);

            return new StoredCharge(null, 'failed', $e->getStripeCode() === 'resource_missing' ? 'resource_missing' : mb_substr((string) ($e->getStripeCode() ?? 'invalid_request'), 0, 64));
        } catch (ApiErrorException|UnexpectedValueException $e) {
            // 通信の失敗・429・5xx・読めない答え: 答えが分からない（24 時間のうちに同じ冪等キーで頼み直す）
            Log::warning('Stripe の請求の応答が分からない', ['error' => $e::class, 'request' => $e instanceof ApiErrorException ? $e->getRequestId() : null]);

            return new StoredCharge(null, 'unknown');
        } finally {
            restore_error_handler();
        }

        $status = match ($intent['status'] ?? null) {
            'succeeded' => 'succeeded',
            'processing' => 'processing',
            'requires_action', 'requires_confirmation' => 'requires_action',
            'requires_payment_method', 'canceled' => 'failed',
            // 知らない状態は、分からないの方へ倒す（失敗と決めつけて次へ進まない）
            default => 'unknown',
        };

        return new StoredCharge(is_string($intent['id'] ?? null) ? $intent['id'] : null, $status, $status === 'failed' ? 'requires_payment_method' : null);
    }

    /**
     * 開いたままの請求を取り消す（先に今の状態を問い合わせ、払われていれば取り消さない）。無い請求は取り消し済みとして扱う
     *
     * @return 'cancelled'|'succeeded'|'processing'
     */
    public function cancel(StripeCredentials $credentials, string $intent): string
    {
        $client = fn () => $this->api->client($credentials->secretKey);

        try {
            $current = $this->api->call(fn () => $client()->paymentIntents->retrieve($intent, [], $this->options($credentials)), 'Stripe に請求の状態を問い合わせられなかった');
            $status = $current->status;

            if (! in_array($status, ['succeeded', 'processing', 'canceled'], true)) {
                $status = $this->api->call(fn () => $client()->paymentIntents->cancel($intent, [], $this->options($credentials)), 'Stripe で請求を取り消せなかった')->status;
            }
        } catch (PaymentUnavailableException $e) {
            $previous = $e->getPrevious();

            if ($previous instanceof InvalidRequestException && $previous->getStripeCode() === 'resource_missing') {
                return 'cancelled';
            }

            // 問い合わせの後に状態が変わった（取り消せない状態になった）: 後でもう一度
            if ($previous instanceof InvalidRequestException && $previous->getStripeCode() === 'payment_intent_unexpected_state') {
                return 'processing';
            }

            throw $e;
        }

        return match ($status) {
            'succeeded' => 'succeeded',
            'canceled' => 'cancelled',
            default => 'processing',
        };
    }

    /**
     * 預けたカードを外す。もう外れている・無いは済みとして扱う
     */
    public function detach(StripeCredentials $credentials, string $method): void
    {
        try {
            $this->api->call(
                fn () => $this->api->client($credentials->secretKey)->paymentMethods->detach($method, [], $this->options($credentials)),
                'Stripe で預けたカードを外せなかった',
            );
        } catch (PaymentUnavailableException $e) {
            $previous = $e->getPrevious();

            if ($previous instanceof InvalidRequestException && ($previous->getStripeCode() === 'resource_missing' || str_contains(strtolower($previous->getMessage()), 'not attached'))) {
                return;
            }

            throw $e;
        }
    }

    private static function log(string $message, ApiErrorException $e): void
    {
        Log::warning($message, ['error' => $e::class, 'code' => $e->getStripeCode(), 'status' => $e->getHttpStatus(), 'request' => $e->getRequestId()]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function options(StripeCredentials $credentials, array $options = []): array
    {
        return $credentials->accountId === null ? $options : $options + ['stripe_account' => $credentials->accountId];
    }
}
