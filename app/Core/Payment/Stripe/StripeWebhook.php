<?php

declare(strict_types=1);

namespace App\Core\Payment\Stripe;

use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\Contracts\PaymentDriver;
use App\Core\Payment\Contracts\PaymentWebhookReceiver;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\RefundResult;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Payment\Services\StoredPayments;
use App\Core\Payment\StripeCredentials;
use App\Core\Payment\WebhookRejected;
use App\Models\StripeConnectedAccount;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * Stripe の通知（Webhook）を確かめて振り分ける（ADR 0019）。署名は店の Webhook の署名の鍵で、5 分より古い通知は断る（送り直しの悪用を防ぐ）。
 * 支払いの確定はここからだけ（お客さんが戻ってきた画面は証拠にしない）。金額などの突き合わせは PaymentService
 */
final class StripeWebhook implements PaymentWebhookReceiver
{
    /** 署名の時刻の許容（秒）。Stripe の部品の既定と同じ */
    private const int TOLERANCE = 300;

    public function __construct(
        private readonly PaymentGatewayService $gateways,
        private readonly PaymentNotices $payments,
        private readonly PaymentDriver $driver,
        private readonly StripeApi $api,
    ) {}

    public function signatureHeader(): string
    {
        return 'Stripe-Signature';
    }

    public function receive(string $payload, ?string $signature): string
    {
        $candidates = $this->gateways->webhookCandidates();

        if ($candidates === []) {
            throw $this->reject('鍵が無い店への通知');
        }

        // ふだんの鍵と、公開の前に試すテスト用の鍵（ADR 0096）。どちらの署名の鍵で確かめられたかで、その鍵のモードの通知として扱う
        $event = null;
        $credentials = null;

        foreach ($candidates as $candidate) {
            try {
                $event = Webhook::constructEvent($payload, (string) $signature, $candidate->webhookSecret, self::TOLERANCE);
                $credentials = $candidate;

                break;
            } catch (SignatureVerificationException) {
                continue;
            } catch (UnexpectedValueException) {
                throw $this->reject('形が違う');
            }
        }

        if ($event === null) {
            throw $this->reject('署名が無い・違う・古い');
        }

        // テスト用の鍵の店に本番の通知（またはその逆）は受け取らない
        if ((bool) $event->livemode !== ($credentials->mode === 'live')) {
            throw $this->reject('test と live の取り違え');
        }

        // テスト用の鍵の署名の鍵は、ふだんの鍵より広く扱われがち。署名だけを信じず、同じ通知を Stripe に問い合わせ直した中身で処理する
        // （テストのアカウントにある品物の ID は本番と重ならないので、問い合わせ直した通知は本番の支払いに届かない）
        if ($credentials->testKey) {
            $event = $this->refetched($credentials, $event);
        }

        $this->assertSameMode($event);

        return $this->dispatch($event);
    }

    /**
     * 通知を Stripe に問い合わせ直す（テスト用の鍵で）。届かない・見つからない・中身の種類が違えば断る（Stripe が送り直す）
     */
    private function refetched(StripeCredentials $credentials, Event $event): Event
    {
        try {
            $fetched = $this->api->call(fn () => $this->api->client($credentials->secretKey)->events->retrieve((string) $event->id), 'Stripe に通知を問い合わせられなかった');
        } catch (PaymentUnavailableException) {
            throw $this->reject('テスト用の鍵の通知を Stripe で確認できない');
        }

        if ($fetched->id !== $event->id || (bool) $fetched->livemode || $fetched->type !== $event->type) {
            throw $this->reject('テスト用の鍵の通知が Stripe の記録と合わない');
        }

        return $fetched;
    }

    /**
     * 通知が指す支払いの試み（支払いの画面・預けたカードの請求）が、通知と同じモードで作ったものであること（ADR 0096）。
     * 試みが見つからない通知は、ここでは止めず振り分けの先に任せる（知らない画面の通知は記録だけ）
     */
    private function assertSameMode(Event $event): void
    {
        $object = $event->data->object->toArray();
        $id = is_string($object['id'] ?? null) ? $object['id'] : null;

        if ($id === null || ! (str_starts_with($event->type, 'checkout.session.') || str_starts_with($event->type, 'payment_intent.'))) {
            return;
        }

        $attempt = PaymentAttempt::query()->where('provider', 'stripe')->where('provider_ref', $id)->first();

        if ($attempt !== null && $attempt->test_mode === (bool) $event->livemode) {
            throw $this->reject('支払いの試みと通知のモードが違う');
        }
    }

    /**
     * つないだ店（Connect、ADR 0022）の通知。運営の 1 か所（中央のホスト）で受け、運営の署名の鍵で確かめ、
     * 通知の account（店の連結アカウント）から店を引いて、その店のテナントの文脈で処理する。知らないアカウントは受け取るだけ
     */
    public function receiveForPlatform(string $payload, ?string $signature): string
    {
        $secret = config('payment.stripe_connect_webhook_secret');

        if (! is_string($secret) || ! str_starts_with($secret, 'whsec_')) {
            throw $this->reject('Connect の署名の鍵が無い');
        }

        try {
            $event = Webhook::constructEvent($payload, (string) $signature, $secret, self::TOLERANCE);
        } catch (SignatureVerificationException) {
            throw $this->reject('署名が無い・違う・古い');
        } catch (UnexpectedValueException) {
            throw $this->reject('形が違う');
        }

        $accountId = is_string($event->account ?? null) ? $event->account : null;
        $link = $accountId === null ? null : StripeConnectedAccount::query()->where('account_id', $accountId)->first();

        if ($link === null) {
            // 運営のアカウント自身の通知・つないでいない（外した）アカウント。Stripe に送り直させないよう受け取るだけ
            Log::info('店の無い連結アカウントの通知', ['account' => $accountId, 'type' => $event->type]);

            return 'unknown_account';
        }

        if ((bool) $event->livemode !== $link->livemode) {
            throw $this->reject('test と live の取り違え');
        }

        $tenant = Tenant::query()->find($link->tenant_id) ?? throw $this->reject('店が無い');
        tenancy()->initialize($tenant);

        try {
            // 店の側でも同じ連結アカウントにつないでいること（中央の対応だけに頼らない）
            if ($this->gateways->credentials()?->accountId !== $accountId) {
                Log::warning('つないでいない店への Connect の通知', ['account' => $accountId, 'tenant' => $link->tenant_id]);

                return 'not_connected';
            }

            $this->assertSameMode($event);

            return $this->dispatch($event);
        } finally {
            tenancy()->end();
        }
    }

    /**
     * 確かめた通知を種類で振り分ける（テナントの文脈の中で）
     */
    private function dispatch(Event $event): string
    {
        return match ($event->type) {
            // 返金の状態の知らせ（保留の確定・失敗・成功の後の戻し。ADR 0027）。refund.created は Stripe の画面で直接した返金を記録するため
            'refund.created', 'refund.updated', 'refund.failed', 'charge.refund.updated' => $this->refundNotice($event),
            // 預けたカードの請求が払われた（ADR 0045 の 3）。支払いの画面から生まれた支払いの知らせは、stored の記録が無いので扱わない
            'payment_intent.succeeded' => $this->storedPaid($event),
            // 預けたカードの請求が後で断られた（処理中だった請求・本人確認の後の断り）
            'payment_intent.payment_failed' => $this->storedFailed($event),
            default => $this->checkoutNotice($event),
        };
    }

    private function storedPaid(Event $event): string
    {
        $intent = $event->data->object->toArray();
        $id = is_string($intent['id'] ?? null) ? $intent['id'] : throw $this->reject('支払いの ID が無い');
        $metadata = is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [];
        $transaction = is_string($metadata['transaction_id'] ?? null) ? $metadata['transaction_id'] : null;

        // 店の印が今の店と違う知らせは扱わない（署名の鍵と連結アカウントで店に入った後の、重ねた守り）
        if (is_string($metadata['tenant'] ?? null) && $metadata['tenant'] !== (string) tenant('id')) {
            return $this->payments->ignore('stripe', $event->id, $event->type);
        }

        $amount = is_int($intent['amount_received'] ?? null) ? $intent['amount_received'] : (is_int($intent['amount'] ?? null) ? $intent['amount'] : -1);

        return $this->payments->confirmStored('stripe', $event->id, $event->type, $id, $transaction, $amount, is_string($intent['currency'] ?? null) ? $intent['currency'] : '');
    }

    private function storedFailed(Event $event): string
    {
        $intent = $event->data->object->toArray();
        $id = is_string($intent['id'] ?? null) ? $intent['id'] : throw $this->reject('支払いの ID が無い');
        $metadata = is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [];

        if (is_string($metadata['tenant'] ?? null) && $metadata['tenant'] !== (string) tenant('id')) {
            return $this->payments->ignore('stripe', $event->id, $event->type);
        }

        $error = is_array($intent['last_payment_error'] ?? null) ? $intent['last_payment_error'] : [];
        $code = is_string($error['decline_code'] ?? null) ? $error['decline_code'] : (is_string($error['code'] ?? null) ? $error['code'] : 'declined');

        return $this->payments->failStored('stripe', $event->id, $event->type, $id, is_string($metadata['transaction_id'] ?? null) ? $metadata['transaction_id'] : null, mb_substr($code, 0, 64));
    }

    private function refundNotice(Event $event): string
    {
        $refund = StripeCheckout::normalizeRefund($event->data->object->toArray());

        if (! str_starts_with($refund['id'], 're_')) {
            throw $this->reject('返金の ID が無い');
        }

        return $this->payments->refundUpdated('stripe', $event->id, $event->type, new RefundResult($refund['id'], $refund['status'], $refund['failure_reason'], $refund['amount'], $refund['payment_ref'], $refund['refund_id']));
    }

    private function checkoutNotice(Event $event): string
    {
        // 中身は型を確かめて読む（文字でない・数でない値は空として扱い、突き合わせで落とす）
        $session = $event->data->object->toArray();
        $text = static fn (string $key): ?string => is_string($session[$key] ?? null) ? $session[$key] : null;
        $sessionId = $text('id') ?? throw $this->reject('支払いの画面の ID が無い');

        // カードを替える画面（setup のモード。ADR 0045 の 1）。支払いではないので、取引の支払いにはしない
        if ($text('mode') === 'setup') {
            return $event->type === 'checkout.session.completed' && $text('setup_intent') !== null
                ? $this->cardSetup($event, $sessionId, (string) $text('setup_intent'))
                : $this->payments->ignore('stripe', $event->id, $event->type);
        }

        $amount = is_int($session['amount_total'] ?? null) ? $session['amount_total'] : -1;

        return match ($event->type) {
            // カード・PayPay はここで払われている。コンビニ払いは払込票を出した所で終わり（unpaid）、払われた知らせ（async_payment_succeeded）を待つ（ADR 0058）
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => match (true) {
                $text('payment_status') === 'paid' => $this->paid($event, $sessionId, $text('client_reference_id'), $amount, $text('currency') ?? '', $text('payment_intent')),
                $event->type === 'checkout.session.completed' && $text('payment_status') === 'unpaid' => $this->awaiting($event, $sessionId, $text('client_reference_id'), $amount, $text('currency') ?? '', $text('payment_intent')),
                default => $this->payments->ignore('stripe', $event->id, $event->type),
            },
            // コンビニ払いの期限が過ぎた・断られた: 注文を取り消して在庫とクーポンの数を戻す
            'checkout.session.async_payment_failed' => $this->payments->asyncFailed('stripe', $event->id, $event->type, $sessionId),
            'checkout.session.expired' => $this->payments->markExpired('stripe', $event->id, $event->type, $sessionId),
            default => $this->payments->ignore('stripe', $event->id, $event->type),
        };
    }

    /**
     * 後から払う手段で支払いの画面が終わった（コンビニ払いの払込票を出した。ADR 0058）: 入金待ちにする。
     * 払いの期限と払込票の画面は、Stripe に問い合わせて決める（DB のロックの外で。届かなければ期限は Stripe と同じ数え方、画面は無し）。
     * 取り消した注文の払込票だったときは、Stripe でも取り消す（払われたら、閉じた注文への支払いとして返金の道に乗る）
     */
    private function awaiting(Event $event, string $sessionId, ?string $transactionRef, int $amount, string $currency, ?string $paymentRef): string
    {
        $attempt = PaymentAttempt::query()->where('provider', 'stripe')->where('provider_ref', $sessionId)->first();
        $credentials = $attempt === null ? null : $this->gateways->credentialsForAttempt($attempt);
        $snapshot = $attempt !== null && $credentials !== null && CheckoutMethods::fromString($attempt->methods)->includesAsync()
            ? $this->driver->retrieveCheckout($credentials, $sessionId)
            : null;

        $result = $this->payments->awaitPayment('stripe', $event->id, $event->type, $sessionId, $transactionRef, $amount, $currency, $snapshot?->method, $snapshot?->payBy, $snapshot?->instructionsUrl);
        $paymentRef ??= $snapshot?->paymentRef;

        if ($result === 'closed' && $credentials !== null && $paymentRef !== null) {
            try {
                $this->driver->cancelPayment($credentials, $paymentRef);
            } catch (\Throwable $e) {
                Log::warning('取り消した注文の払込票を Stripe で取り消せなかった（払われたら返金の道へ）', ['session' => $sessionId, 'error' => $e::class]);
            }
        }

        return $result;
    }

    /**
     * 払われた手段（いくつかの手段を出した画面だけ、Stripe に問い合わせる。カードだけの画面はカード）。分からなければ null
     */
    private function paidMethod(string $sessionId): ?string
    {
        $attempt = PaymentAttempt::query()->where('provider', 'stripe')->where('provider_ref', $sessionId)->first();

        if ($attempt === null || $attempt->methods === 'card') {
            return null;
        }

        $credentials = $this->gateways->credentialsForAttempt($attempt);

        return $credentials === null ? null : $this->driver->retrieveCheckout($credentials, $sessionId)?->method;
    }

    /**
     * カードの登録が済んだ: カードを記録してから、知らせを受けたことを残す。Stripe に問い合わせられない・DB の失敗は例外のまま外へ出し
     * （受け口が 500 で返す）、Stripe の送り直しでもう一度（記録は同じカードなら 1 つ）
     */
    private function cardSetup(Event $event, string $sessionId, string $setupIntent): string
    {
        $metadata = $event->data->object->toArray()['metadata'] ?? [];

        if (is_array($metadata) && is_string($metadata['tenant'] ?? null) && $metadata['tenant'] !== (string) tenant('id')) {
            return $this->payments->ignore('stripe', $event->id, $event->type);
        }

        $method = app(StoredPayments::class)->rememberSetup($sessionId, $setupIntent);

        return $this->payments->note('stripe', $event->id, $event->type, $method === null ? 'card_not_stored' : 'card_stored');
    }

    /**
     * 払われた: 確定し、カードを預ける支払いなら預けたカードを記録する（ADR 0045 の 3。記録に失敗しても確定は変えない）
     */
    private function paid(Event $event, string $sessionId, ?string $transactionRef, int $amount, string $currency, ?string $paymentRef): string
    {
        $result = $this->payments->confirmPaid('stripe', $event->id, $event->type, $sessionId, $transactionRef, $amount, $currency, $paymentRef, $this->paidMethod($sessionId));

        if (in_array($result, ['paid', 'already_paid'], true)) {
            try {
                app(StoredPayments::class)->rememberFrom($sessionId, $paymentRef);
            } catch (\Throwable $e) {
                Log::error('預けたカードを記録できなかった', ['session' => $sessionId, 'error' => $e::class]);
            }
        }

        // 同じ注文に残った、ほかの支払いの画面と入金待ちの払込票を閉じる（二重に払われないように。届かなければ見回りでもう一度）
        if ($result === 'paid') {
            $transactionId = PaymentAttempt::query()->where('provider', 'stripe')->where('provider_ref', $sessionId)->value('transaction_id');

            try {
                if (is_string($transactionId)) {
                    app(PaymentService::class)->closeLeftoversOfPaid($transactionId);
                }
            } catch (\Throwable $e) {
                Log::warning('払われた注文に残った支払いの画面を閉じられなかった（見回りでもう一度）', ['session' => $sessionId, 'error' => $e::class]);
            }
        }

        return $result;
    }

    private function reject(string $reason): WebhookRejected
    {
        Log::warning('Stripe の通知を断った', ['reason' => $reason, 'tenant' => tenant()?->getTenantKey()]);

        return new WebhookRejected($reason);
    }
}
