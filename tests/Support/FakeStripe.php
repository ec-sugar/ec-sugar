<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Carbon;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * テスト用の Stripe の偽物（Stripe の部品の通信だけを差し替える。部品の組み立て・答えの読み取りは本物が動く）。
 * 支払いの画面の作成には、送られた明細から合計を計算して答える。$respond で答えを差し替えられる。
 * 使い終わったら reset() で本物の通信に戻す（部品の設定は全体で 1 つ）。
 */
final class FakeStripe implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}> */
    public array $requests = [];

    /** @var (Closure(array<string, mixed>): array{0: string, 1: int, 2: array<string, string>})|null */
    public ?Closure $respond = null;

    /** 次の 1 回の要求の前に割り込ませる操作（method, url, params を受ける）。同時の操作の順番を決めて起こすため。1 回で消える */
    public ?Closure $before = null;

    /** @var array<string, string> 同じ冪等キーには同じ画面を返す（Stripe と同じ） */
    private array $byIdempotencyKey = [];

    /** @var array<string, array<string, mixed>> 作った支払いの画面（問い合わせに答える。テストが status・payment_status を変えられる） */
    public array $sessions = [];

    /** @var list<array<string, mixed>> した返金 */
    public array $refunds = [];

    /** 次の支払いの画面の作成で、Stripe では作るが答えを返さない（通信の途中で切れた。送り直しを試す） */
    public bool $dropNextCheckoutAnswer = false;

    /** @var array<string, string> 冪等キーごとの、最初に送られた中身の指紋（同じキーで違う中身なら、本物と同じく断る） */
    private array $paramsByIdempotencyKey = [];

    /** 返金の答えの状態（succeeded・pending・failed。ADR 0027 の保留と失敗を試す） */
    public string $refundStatus = 'succeeded';

    /** 問い合わせ（GET）に答えない（Stripe に届かない） */
    public bool $unreachable = false;

    /** @var array<string, array<string, mixed>> 作った連結アカウント（Accounts v2。テストがカード払いの状態や期限を変えられる） */
    public array $accounts = [];

    /** @var array<string, array<string, mixed>> 作ったカードを預ける先（Customer。ADR 0045） */
    public array $customers = [];

    /** @var array<string, array<string, mixed>> 支払い（PaymentIntent）。テストが預けたカードを置き、問い合わせに答える */
    public array $intents = [];

    /** @var array<string, array<string, mixed>> カードの登録（SetupIntent） */
    public array $setupIntents = [];

    /** 次のお客さんのいない請求の答え（succeeded・requires_action・declined・missing・unauthorized・idempotency・server） */
    public string $chargeOutcome = 'succeeded';

    /** @var array<string, array<string, mixed>> 通知（Event）。テスト用の鍵の通知の問い合わせ直し（ADR 0096）に答える。テストが置く */
    public array $events = [];

    /** @var list<string> 外したカード */
    public array $detached = [];

    /** @var list<string> 取り消した請求 */
    public array $cancelledIntents = [];

    /** @var array<string, array{0: string, 1: int, 2: array<string, string>}> 請求の冪等キーごとの最初の答え */
    private array $chargeReplies = [];

    public static function install(): self
    {
        $fake = new self;
        ApiRequestor::setHttpClient($fake);

        return $fake;
    }

    public static function reset(): void
    {
        // 部品の既定の通信（cURL）に戻す
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: int, 2: array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        if ($this->before !== null) {
            $before = $this->before;
            $this->before = null;
            $before($method, $absUrl, $params);
        }

        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

        if ($this->respond !== null) {
            return ($this->respond)($params);
        }

        // カードを預ける先（Customer。ADR 0045）。同じ冪等キーには同じ Customer
        if ($method === 'post' && str_ends_with($absUrl, '/v1/customers')) {
            $key = $this->header($headers, 'Idempotency-Key');

            if ($key !== null && isset($this->byIdempotencyKey[$key])) {
                return [$this->byIdempotencyKey[$key], 200, []];
            }

            $id = 'cus_test_'.bin2hex(random_bytes(8));
            $this->customers[$id] = ['id' => $id, 'object' => 'customer', 'metadata' => $params['metadata'] ?? []];
            $body = (string) json_encode($this->customers[$id]);

            if ($key !== null) {
                $this->byIdempotencyKey[$key] = $body;
            }

            return [$body, 200, []];
        }

        // 通知の問い合わせ（テスト用の鍵の通知を確かめ直す。ADR 0096）。テストが $events に置いたものを返す
        if ($method === 'get' && preg_match('#/v1/events/(evt_[A-Za-z0-9_]+)$#', $absUrl, $m) === 1) {
            $event = $this->unreachable ? null : ($this->events[$m[1]] ?? null);

            return $event === null
                ? ['{"error":{"type":"invalid_request_error","code":"resource_missing","message":"No such event"}}', $this->unreachable ? 503 : 404, []]
                : [(string) json_encode($event), 200, []];
        }

        // カードの登録の問い合わせ（カードを替える画面。ADR 0045）。テストが $setupIntents に置いたものを返す
        if ($method === 'get' && preg_match('#/v1/setup_intents/(seti_[A-Za-z0-9_]+)$#', $absUrl, $m) === 1) {
            $intent = $this->setupIntents[$m[1]] ?? null;

            return $intent === null
                ? ['{"error":{"type":"invalid_request_error","code":"resource_missing","message":"No such setup_intent"}}', 404, []]
                : [(string) json_encode($intent), 200, []];
        }

        // 支払いの問い合わせ（預けたカードを読む）。テストが $intents に置いたものを返す
        if ($method === 'get' && preg_match('#/v1/payment_intents/(pi_[A-Za-z0-9_]+)$#', $absUrl, $m) === 1) {
            if ($this->unreachable) {
                return ['{"error":{"type":"api_error","message":"unavailable"}}', 503, []];
            }

            $intent = $this->intents[$m[1]] ?? null;

            return $intent === null
                ? ['{"error":{"type":"invalid_request_error","code":"resource_missing","message":"No such payment_intent"}}', 404, []]
                : [(string) json_encode($intent), 200, []];
        }

        // お客さんのいない請求（off_session）。同じ冪等キーには、最初と同じ答え（状態も）
        if ($method === 'post' && str_ends_with($absUrl, '/v1/payment_intents')) {
            $key = $this->header($headers, 'Idempotency-Key');

            if ($key !== null && isset($this->chargeReplies[$key])) {
                return $this->chargeReplies[$key];
            }

            $id = 'pi_test_'.bin2hex(random_bytes(8));
            $intent = ['id' => $id, 'object' => 'payment_intent', 'amount' => (int) ($params['amount'] ?? 0), 'currency' => $params['currency'] ?? 'jpy', 'customer' => $params['customer'] ?? null, 'payment_method' => $params['payment_method'] ?? null, 'metadata' => $params['metadata'] ?? []];
            $error = static fn (string $type, string $code, ?string $decline = null, ?array $intent = null): string => (string) json_encode(['error' => array_filter(['type' => $type, 'code' => $code, 'decline_code' => $decline, 'message' => $code, 'payment_intent' => $intent])]);

            // 本物の Stripe と同じ形: 本人確認が要るときは 402・authentication_required・状態は requires_payment_method
            $reply = match ($this->chargeOutcome) {
                'requires_action' => [$error('card_error', 'authentication_required', 'authentication_required', $intent + ['status' => 'requires_payment_method']), 402, []],
                'declined' => [$error('card_error', 'card_declined', 'insufficient_funds', $intent + ['status' => 'requires_payment_method']), 402, []],
                'missing' => [$error('invalid_request_error', 'resource_missing'), 400, []],
                'unauthorized' => [$error('invalid_request_error', 'api_key_expired'), 401, []],
                'idempotency' => [$error('idempotency_error', 'idempotency_key_in_use'), 400, []],
                'server' => ['{"error":{"type":"api_error","message":"server"}}', 500, []],
                default => [(string) json_encode($intent + ['status' => 'succeeded']), 200, []],
            };

            if (in_array($this->chargeOutcome, ['succeeded', 'requires_action', 'declined'], true)) {
                $this->intents[$id] = $intent + ['status' => $this->chargeOutcome === 'succeeded' ? 'succeeded' : 'requires_payment_method'];
            }

            // 本物と同じく、受け付けなかった請求（5xx・鍵の誤り）の答えは冪等キーに覚えない
            if ($key !== null && ! in_array($this->chargeOutcome, ['server', 'unauthorized'], true)) {
                $this->chargeReplies[$key] = $reply;
            }

            return $reply;
        }

        // 請求の取り消し: 払われた・処理中の請求は断る（本物と同じ）。取り消したものを覚える
        if ($method === 'post' && preg_match('#/v1/payment_intents/(pi_[A-Za-z0-9_]+)/cancel$#', $absUrl, $m) === 1) {
            if ($this->unreachable) {
                return ['{"error":{"type":"api_error","message":"unavailable"}}', 503, []];
            }

            $intent = $this->intents[$m[1]] ?? null;

            if ($intent === null) {
                return ['{"error":{"type":"invalid_request_error","code":"resource_missing","message":"No such payment_intent"}}', 404, []];
            }

            if (in_array($intent['status'] ?? null, ['succeeded', 'processing'], true)) {
                return ['{"error":{"type":"invalid_request_error","code":"payment_intent_unexpected_state","message":"unexpected state"}}', 400, []];
            }

            $this->intents[$m[1]]['status'] = 'canceled';
            $this->cancelledIntents[] = $m[1];

            return [(string) json_encode($this->intents[$m[1]]), 200, []];
        }

        if ($method === 'post' && preg_match('#/v1/payment_methods/(pm_[A-Za-z0-9_]+)/detach$#', $absUrl, $m) === 1) {
            $this->detached[] = $m[1];

            return [(string) json_encode(['id' => $m[1], 'object' => 'payment_method', 'customer' => null]), 200, []];
        }

        // 返金（同じ冪等キーには同じ返金）と、支払いの画面を閉じる
        if ($method === 'post' && str_ends_with($absUrl, '/v1/refunds')) {
            $key = $this->header($headers, 'Idempotency-Key');

            if ($key !== null && isset($this->byIdempotencyKey[$key])) {
                return [$this->byIdempotencyKey[$key], 200, []];
            }

            $refund = ['id' => 're_test_'.bin2hex(random_bytes(8)), 'object' => 'refund', 'amount' => (int) ($params['amount'] ?? 0), 'payment_intent' => $params['payment_intent'] ?? null, 'status' => $this->refundStatus, 'metadata' => $params['metadata'] ?? []] + ($this->refundStatus === 'failed' ? ['failure_reason' => 'insufficient_funds'] : []);
            $this->refunds[] = $refund;
            $body = (string) json_encode($refund);

            if ($key !== null) {
                $this->byIdempotencyKey[$key] = $body;
            }

            return [$body, 200, []];
        }

        if ($method === 'post' && preg_match('#/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)/expire$#', $absUrl, $m) === 1 && isset($this->sessions[$m[1]])) {
            $this->sessions[$m[1]]['status'] = 'expired';

            return [(string) json_encode($this->sessions[$m[1]]), 200, []];
        }

        // Connect（Accounts v2）: 連結アカウントを作る・登録の画面のリンク・問い合わせ
        if ($method === 'post' && str_ends_with($absUrl, '/v2/core/accounts')) {
            $key = $this->header($headers, 'Idempotency-Key');
            $existing = $key === null ? null : ($this->byIdempotencyKey[$key] ?? null);

            if ($existing !== null) {
                return [$existing, 200, []];
            }

            $id = 'acct_'.bin2hex(random_bytes(8));
            $this->accounts[$id] = ['id' => $id, 'object' => 'v2.core.account', 'livemode' => false, 'display_name' => $params['display_name'] ?? null, 'card_payments' => 'pending', 'deadline' => null];
            $body = (string) json_encode(['id' => $id, 'object' => 'v2.core.account', 'livemode' => false]);

            if ($key !== null) {
                $this->byIdempotencyKey[$key] = $body;
            }

            return [$body, 200, []];
        }

        if ($method === 'post' && str_ends_with($absUrl, '/v2/core/account_links')) {
            $account = (string) ($params['account'] ?? '');

            return isset($this->accounts[$account])
                ? [(string) json_encode(['object' => 'v2.core.account_link', 'account' => $account, 'url' => "https://connect.stripe.com/setup/{$account}/".bin2hex(random_bytes(6)), 'livemode' => false]), 200, []]
                : ['{"error":{"type":"invalid_request_error","code":"not_found","message":"No such account"}}', 404, []];
        }

        if ($method === 'get' && preg_match('#/v2/core/accounts/(acct_[a-f0-9]+)#', $absUrl, $m) === 1) {
            $account = $this->accounts[$m[1]] ?? null;

            return $account === null
                ? ['{"error":{"type":"invalid_request_error","code":"not_found","message":"No such account"}}', 404, []]
                : [(string) json_encode([
                    'id' => $account['id'],
                    'object' => 'v2.core.account',
                    'livemode' => false,
                    'configuration' => ['merchant' => ['capabilities' => ['card_payments' => ['status' => $account['card_payments']]]]],
                    'requirements' => ['summary' => ['minimum_deadline' => $account['deadline'] === null ? null : ['status' => $account['deadline']]]],
                ]), 200, []];
        }

        // 返金の問い合わせ（定期の問い合わせ。ADR 0027）。テストが $refunds の status を変えて、保留の確定を作れる
        if ($method === 'get' && preg_match('#/v1/refunds/(re_[A-Za-z0-9_]+)$#', $absUrl, $m) === 1) {
            if ($this->unreachable) {
                return ['{"error":{"type":"api_error","message":"unavailable"}}', 503, []];
            }

            foreach ($this->refunds as $refund) {
                if ($refund['id'] === $m[1]) {
                    return [(string) json_encode($refund), 200, []];
                }
            }

            return ['{"error":{"type":"invalid_request_error","code":"resource_missing","message":"no such refund"}}', 404, []];
        }

        if ($method === 'get' && preg_match('#/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)$#', $absUrl, $m) === 1) {
            if ($this->unreachable) {
                return ['{"error":{"type":"api_error","message":"unavailable"}}', 503, []];
            }

            $session = $this->sessions[$m[1]] ?? null;

            // 期限が過ぎた画面は、本物と同じく expired（テストの時計に合わせる。keep_open で食い違いを作れる）
            if ($session !== null && $session['status'] === 'open' && $session['expires_at'] < Carbon::now()->getTimestamp() && ! ($session['keep_open'] ?? false)) {
                $session['status'] = 'expired';
            }

            // 支払いを一緒に読む問い合わせ（expand。ADR 0058）: $intents に置いた支払いを中に入れる（本物と同じ形）
            $expand = is_array($params['expand'] ?? null) ? $params['expand'] : [];

            if ($session !== null && is_string($session['payment_intent'] ?? null) && isset($this->intents[$session['payment_intent']])
                && array_filter($expand, static fn (mixed $path): bool => is_string($path) && str_starts_with($path, 'payment_intent')) !== []) {
                $session['payment_intent'] = $this->intents[$session['payment_intent']];
            }

            return $session !== null
                ? [(string) json_encode($session), 200, []]
                : ['{"error":{"type":"invalid_request_error","message":"No such checkout.session"}}', 404, []];
        }

        $key = $this->header($headers, 'Idempotency-Key');

        // 本物の Stripe と同じく、同じ冪等キーで違う中身は断る（送り直しは同じ中身で）
        if ($key !== null && isset($this->paramsByIdempotencyKey[$key]) && $this->paramsByIdempotencyKey[$key] !== md5((string) json_encode($params))) {
            return ['{"error":{"type":"idempotency_error","message":"Keys for idempotent requests can only be used with the same parameters they were first used with."}}', 400, []];
        }

        if ($key !== null && isset($this->byIdempotencyKey[$key])) {
            return [$this->byIdempotencyKey[$key], 200, []];
        }

        $id = 'cs_test_'.bin2hex(random_bytes(12));
        $total = 0;
        foreach ($params['line_items'] ?? [] as $item) {
            $total += (int) $item['price_data']['unit_amount'] * (int) $item['quantity'];
        }
        $this->sessions[$id] = [
            'id' => $id,
            'object' => 'checkout.session',
            'url' => "https://checkout.stripe.com/c/pay/{$id}",
            'amount_total' => $total,
            'currency' => $params['line_items'][0]['price_data']['currency'] ?? 'jpy',
            'expires_at' => (int) ($params['expires_at'] ?? time() + 3600),
            'status' => 'open',
            'payment_status' => 'unpaid',
            'client_reference_id' => $params['client_reference_id'] ?? null,
            'payment_intent' => null,
        ];
        $body = (string) json_encode($this->sessions[$id]);

        if ($key !== null) {
            $this->byIdempotencyKey[$key] = $body;
            $this->paramsByIdempotencyKey[$key] = md5((string) json_encode($params));
        }

        // Stripe では作れたが、答えが途中で切れた
        if ($this->dropNextCheckoutAnswer) {
            $this->dropNextCheckoutAnswer = false;

            return ['{"error":{"type":"api_error","message":"connection reset"}}', 500, []];
        }

        return [$body, 200, []];
    }

    /**
     * お客さんが支払いの画面でコンビニ払いを選び、払込票が出た（画面は終わり・まだ払われていない。ADR 0058）。返すのは支払いの ID
     */
    public function konbiniVoucher(string $session, int $expiresAt, string $voucherUrl = 'https://payment.stripe.com/konbini/voucher/test_voucher'): string
    {
        $intent = 'pi_test_konbini_'.bin2hex(random_bytes(6));
        $this->sessions[$session] = ['status' => 'complete', 'payment_status' => 'unpaid', 'payment_intent' => $intent] + $this->sessions[$session];
        $this->intents[$intent] = ['id' => $intent, 'object' => 'payment_intent', 'status' => 'requires_action', 'amount' => $this->sessions[$session]['amount_total'], 'currency' => 'jpy',
            'payment_method' => ['id' => 'pm_test_'.bin2hex(random_bytes(6)), 'object' => 'payment_method', 'type' => 'konbini'],
            'next_action' => ['type' => 'konbini_display_details', 'konbini_display_details' => ['expires_at' => $expiresAt, 'hosted_voucher_url' => $voucherUrl]]];

        return $intent;
    }

    /**
     * 支払いの画面で払われた（手段を選べる。カードの中の Apple Pay などは $wallet。ADR 0058）。返すのは支払いの ID
     */
    public function paidWith(string $session, string $type, ?string $wallet = null): string
    {
        $intent = $this->sessions[$session]['payment_intent'] ?? null;
        $intent = is_string($intent) ? $intent : 'pi_test_paid_'.bin2hex(random_bytes(6));
        $this->sessions[$session] = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => $intent] + $this->sessions[$session];
        $method = ['id' => 'pm_test_'.bin2hex(random_bytes(6)), 'object' => 'payment_method', 'type' => $type] + ($wallet === null ? [] : ['card' => ['wallet' => ['type' => $wallet]]]);
        $this->intents[$intent] = ['status' => 'succeeded', 'payment_method' => $method, 'next_action' => null] + ($this->intents[$intent] ?? ['id' => $intent, 'object' => 'payment_intent', 'amount' => $this->sessions[$session]['amount_total'], 'currency' => 'jpy']);

        return $intent;
    }

    /**
     * カードを替える画面で登録が済んだ SetupIntent（お客さんのいない請求に使える形）。返すのは支払いの方法の ID
     */
    public function setupWithSavedCard(string $setupIntent, string $customer, string $last4 = '5555', string $brand = 'mastercard', ?string $fingerprint = null): string
    {
        $method = 'pm_test_'.bin2hex(random_bytes(8));
        $card = ['brand' => $brand, 'last4' => $last4, 'exp_month' => 3, 'exp_year' => 2030] + ($fingerprint === null ? [] : ['fingerprint' => $fingerprint]);
        $this->setupIntents[$setupIntent] = ['id' => $setupIntent, 'object' => 'setup_intent', 'status' => 'succeeded', 'usage' => 'off_session', 'customer' => $customer,
            'payment_method' => ['id' => $method, 'object' => 'payment_method', 'card' => $card]];

        return $method;
    }

    /**
     * 支払いの画面でカードを預けて払われた支払いを置く（ADR 0045 の 3）
     */
    public function intentWithSavedCard(string $intent, string $customer, string $last4 = '4242', string $brand = 'visa'): string
    {
        $method = 'pm_test_'.bin2hex(random_bytes(8));
        $this->intents[$intent] = ['id' => $intent, 'object' => 'payment_intent', 'status' => 'succeeded', 'customer' => $customer, 'setup_future_usage' => 'off_session',
            'payment_method' => ['id' => $method, 'object' => 'payment_method', 'card' => ['brand' => $brand, 'last4' => $last4, 'exp_month' => 12, 'exp_year' => 2028]]];

        return $method;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastParams(): array
    {
        return $this->requests[array_key_last($this->requests)]['params'] ?? [];
    }

    public function lastHeader(string $name): ?string
    {
        return $this->header($this->requests[array_key_last($this->requests)]['headers'] ?? [], $name);
    }

    /**
     * @param  list<string>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), strtolower($name).': ')) {
                return substr($header, strlen($name) + 2);
            }
        }

        return null;
    }
}
