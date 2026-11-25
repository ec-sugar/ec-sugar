<?php

declare(strict_types=1);

namespace App\Core\Payment\Services;

use App\Core\Event\EventStore;
use App\Core\Payment\Contracts\PaymentCredentialStore;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\StripeCredentials;
use App\Core\Sample\Demo\DemoShop;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * 店の決済代行の鍵を読む・置く・外す（ADR 0019）。鍵はテナント鍵で暗号化して置き、記録には代行の名前と test / live だけ。
 * 本番でない環境では live の鍵を断る（開発やステージングで本物のお金を動かさない）。
 * デモの店（ADR 0086）は本番でも live の鍵を断り、置いてあっても使わない。Connect（運営の鍵で動く）も使わない。
 *
 * 公開の前に試すテスト用の鍵（ADR 0096）: ふだんの鍵（stripe か stripe_connect）と別の行（stripe_test。sk_test_ / rk_test_ だけ）に置き、
 * **ショップが準備中で、ふだんの鍵が本番（か無い）のときだけ**新しい支払いに使う。公開したら新しい支払いには使わない。
 * 返金・問い合わせは、支払いの試みを作ったときのモードの鍵（stripeForAttempt）で行う
 */
final class PaymentGatewayService implements PaymentCredentialStore
{
    /** Stripe の秘密鍵（sk_）と制限付きの鍵（rk_） */
    private const string STRIPE_SECRET = '/\A(sk|rk)_(test|live)_[A-Za-z0-9]{16,247}\z/';

    /** テスト用の鍵の欄に置ける鍵（テストのモードだけ） */
    private const string STRIPE_TEST_SECRET = '/\A(sk|rk)_test_[A-Za-z0-9]{16,247}\z/';

    /** Webhook の署名の鍵 */
    private const string STRIPE_WEBHOOK = '/\Awhsec_[A-Za-z0-9+\/=]{16,200}\z/';

    public function __construct(
        private readonly EventStore $events,
        private readonly StorefrontAccessService $access,
    ) {}

    /**
     * 管理画面に見せる状態（鍵そのものは出さない）
     *
     * @return array{configured: bool, mode: string|null, key_hint: string|null, updated_at: string|null, test_key: array{configured: bool, key_hint: string|null, updated_at: string|null, in_use: bool}}
     */
    public function status(): array
    {
        $gateway = PaymentGateway::query()->where('provider', 'stripe')->first();
        $test = PaymentGateway::query()->where('provider', 'stripe_test')->first();

        return [
            'configured' => $gateway !== null,
            'mode' => $gateway?->mode,
            'key_hint' => $gateway?->key_hint,
            'updated_at' => $gateway?->updated_at->toIso8601String(),
            'test_key' => [
                'configured' => $test !== null,
                'key_hint' => $test?->key_hint,
                'updated_at' => $test?->updated_at->toIso8601String(),
                // 今の新しい支払いにテスト用の鍵を使うか（準備中で、ふだんの鍵が本番か無いとき）
                'in_use' => $test !== null && $this->credentials()?->testKey === true,
            ],
        ];
    }

    /**
     * 新しい支払いに使う鍵。置いていなければ null（店頭はお支払いに進めない）。
     * 準備中のショップで、ふだんの鍵が本番か無く、テスト用の鍵があれば、テスト用の鍵（ADR 0096）
     */
    public function credentials(): ?StripeCredentials
    {
        $primary = $this->primary();

        if ($primary?->mode === 'test') {
            return $primary;
        }

        $test = $this->testKey();

        if ($test !== null && $this->access->status() !== ShopStatus::Open) {
            return $test;
        }

        return $primary;
    }

    /**
     * 支払いの試みを作ったアカウントとモードの鍵（返金・問い合わせ・画面を閉じるとき）。今の設定にその鍵が無ければ null。
     * つないだ店は、登録の状態にかかわらず運営の鍵 ＋ 連結アカウント（止められていても返金はできる）。
     * 店の鍵は、テストで作った試みならテスト用の鍵（無ければテストのモードのふだんの鍵）、本番で作った試みなら本番のふだんの鍵だけ
     */
    public function credentialsForAttempt(PaymentAttempt $attempt): ?StripeCredentials
    {
        // デモの店は Connect も本物の鍵も使わない（ADR 0086）
        $demo = DemoShop::active();
        $account = $attempt->stripe_account;

        if ($account !== null) {
            if ($demo) {
                return null;
            }

            $connect = PaymentGateway::query()->where('provider', 'stripe_connect')->first();
            $platformKey = StripeConnectService::platformKey();

            return $connect !== null && $platformKey !== null && ($connect->credentials['account_id'] ?? null) === $account && ($connect->mode === 'test') === $attempt->test_mode
                ? new StripeCredentials($platformKey, (string) config('payment.stripe_connect_webhook_secret'), $connect->mode, $account)
                : null;
        }

        $own = $this->own();

        if ($attempt->test_mode) {
            return $this->testKey() ?? ($own?->mode === 'test' ? $own : null);
        }

        return $own?->mode === 'live' && ! $demo ? $own : null;
    }

    /**
     * 店の受け口（/webhooks/stripe）に届いた通知を確かめる鍵の候補。ふだんの鍵と、テスト用の鍵（公開した後も、試した注文の返金などの知らせを受ける）
     *
     * @return list<StripeCredentials>
     */
    public function webhookCandidates(): array
    {
        return array_values(array_filter([$this->primary(), $this->testKey()]));
    }

    /**
     * ふだんの鍵（Connect か店の鍵）のモード。無ければ null。開設の手順は、テスト用の鍵だけでは「支払い」を済みにしない（公開すると払えないため）
     */
    public function primaryMode(): ?string
    {
        return $this->primary()?->mode;
    }

    /**
     * ふだんの鍵（Connect か店の鍵）。準備中かどうかは見ない
     */
    private function primary(): ?StripeCredentials
    {
        // つないだ店（Connect、ADR 0022）は、登録が済んで支払いを受けられるときだけ。鍵は運営のプラットフォームの鍵
        $connect = DemoShop::active() ? null : PaymentGateway::query()->where('provider', 'stripe_connect')->where('connect_status', 'active')->first();
        $platformKey = StripeConnectService::platformKey();
        $accountId = $connect?->credentials['account_id'] ?? null;

        if ($connect !== null && $platformKey !== null && is_string($accountId)) {
            return new StripeCredentials($platformKey, (string) config('payment.stripe_connect_webhook_secret'), $connect->mode, $accountId);
        }

        $own = $this->own();

        // デモの店は、本物の鍵が置いてあっても使わない（保存で断るので、ふだんは無い。印を後から付けた店の守り）
        return DemoShop::active() && $own?->mode === 'live' ? null : $own;
    }

    /**
     * 店のふだんの鍵（stripe の行）
     */
    private function own(): ?StripeCredentials
    {
        $credentials = PaymentGateway::query()->where('provider', 'stripe')->first()?->credentials;

        if (! is_string($credentials['secret_key'] ?? null) || ! is_string($credentials['webhook_secret'] ?? null)) {
            return null;
        }

        return new StripeCredentials($credentials['secret_key'], $credentials['webhook_secret'], str_contains($credentials['secret_key'], '_live_') ? 'live' : 'test');
    }

    /**
     * テスト用の鍵（stripe_test の行）。形がテストのモードでなければ使わない（保存で断るので、ふだんは無い）
     */
    private function testKey(): ?StripeCredentials
    {
        $credentials = PaymentGateway::query()->where('provider', 'stripe_test')->first()?->credentials;
        $secret = $credentials['secret_key'] ?? null;

        if (! is_string($secret) || ! is_string($credentials['webhook_secret'] ?? null) || preg_match(self::STRIPE_TEST_SECRET, $secret) !== 1) {
            return null;
        }

        return new StripeCredentials($secret, $credentials['webhook_secret'], 'test', testKey: true);
    }

    /**
     * Stripe の鍵を置く（置き換える）。形が違えば ValidationException（答えに鍵の値は入れない）
     *
     * @param  array<string, mixed>  $input  {secret_key, webhook_secret}
     */
    public function configureStripe(#[SensitiveParameter] array $input, ?string $actor = null): void
    {
        $data = $this->validated($input, self::STRIPE_SECRET, 'Stripe の秘密鍵（sk_test_ か sk_live_ で始まるもの。制限付きの鍵なら rk_）を貼り付けてください。');
        $mode = str_contains($data['secret_key'], '_live_') ? 'live' : 'test';

        if ($mode === 'live' && DemoShop::active()) {
            throw ValidationException::withMessages(['secret_key' => 'デモの店にはテスト用の鍵（sk_test_）だけを入れられます。本物の鍵（live）は使えません。']);
        }

        if ($mode === 'live' && ! (bool) config('payment.live_keys_allowed')) {
            throw ValidationException::withMessages(['secret_key' => 'この環境では本番の鍵（live）は使えません。テスト用の鍵（sk_test_）を使ってください。']);
        }

        $this->put('stripe', $data, $mode, 'payment.gateway_configured', $actor);
    }

    /**
     * 公開の前に試すテスト用の鍵を置く（置き換える。ADR 0096）。テストのモードの鍵（sk_test_ / rk_test_）だけ
     *
     * @param  array<string, mixed>  $input  {secret_key, webhook_secret}
     */
    public function configureStripeTest(#[SensitiveParameter] array $input, ?string $actor = null): void
    {
        $data = $this->validated($input, self::STRIPE_TEST_SECRET, 'テスト用の鍵の欄には、Stripe のテストのモードの秘密鍵（sk_test_ で始まるもの。制限付きの鍵なら rk_test_）を貼り付けてください。');

        $this->put('stripe_test', $data, 'test', 'payment.test_gateway_configured', $actor);
    }

    public function removeStripe(?string $actor = null): void
    {
        $this->remove('stripe', 'payment.gateway_removed', $actor);
    }

    public function removeStripeTest(?string $actor = null): void
    {
        $this->remove('stripe_test', 'payment.test_gateway_removed', $actor);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{secret_key: string, webhook_secret: string}
     */
    private function validated(#[SensitiveParameter] array $input, string $secretPattern, string $secretMessage): array
    {
        /** @var array{secret_key: string, webhook_secret: string} */
        return Validator::make($input, [
            'secret_key' => ['required', 'string', 'regex:'.$secretPattern],
            'webhook_secret' => ['required', 'string', 'regex:'.self::STRIPE_WEBHOOK],
        ], [
            'secret_key.regex' => $secretMessage,
            'webhook_secret.regex' => 'Webhook の署名の鍵（whsec_ で始まるもの）を貼り付けてください。',
        ], ['secret_key' => '秘密鍵', 'webhook_secret' => 'Webhook の署名の鍵'])->validate();
    }

    /**
     * @param  array{secret_key: string, webhook_secret: string}  $data
     */
    private function put(string $provider, #[SensitiveParameter] array $data, string $mode, string $event, ?string $actor): void
    {
        DB::transaction(function () use ($provider, $data, $mode, $event, $actor): void {
            $this->events->lock();
            $gateway = PaymentGateway::query()->where('provider', $provider)->lockForUpdate()->first() ?? new PaymentGateway(['provider' => $provider]);
            $gateway->fill([
                'credentials' => ['secret_key' => $data['secret_key'], 'webhook_secret' => $data['webhook_secret']],
                'mode' => $mode,
                'key_hint' => substr($data['secret_key'], -4),
            ]);
            $gateway->save();
            // 鍵は記録に入れない
            $this->events->append($event, 'payment_gateway', $gateway->id, ['provider' => 'stripe', 'mode' => $mode], $actor);
        });
    }

    private function remove(string $provider, string $event, ?string $actor): void
    {
        DB::transaction(function () use ($provider, $event, $actor): void {
            $this->events->lock();
            $gateway = PaymentGateway::query()->where('provider', $provider)->lockForUpdate()->first();

            if ($gateway === null) {
                return;
            }

            $gateway->delete();
            $this->events->append($event, 'payment_gateway', $gateway->id, ['provider' => 'stripe'], $actor);
        });
    }
}
