<?php

declare(strict_types=1);

namespace App\Core\Payment\Services;

use App\Core\Event\EventStore;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Stripe\StripeConnect;
use App\Core\Sample\Demo\DemoShop;
use App\Models\StripeConnectedAccount;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * 店を「Stripe とつなぐ」（ADR 0022）。運営のプラットフォームの鍵（サーバーの秘密）で店の連結アカウントを作り、
 * Stripe の登録の画面へ送る。店の側には連結アカウントの ID と登録の状態だけを置き、店と ID の対応は中央 DB に置く（通知から店を引く）。
 * Stripe への問い合わせは DB のロックの外。プラットフォームの鍵が無ければ（パッケージ版）使えない
 */
final class StripeConnectService
{
    public function __construct(
        private readonly EventStore $events,
        private readonly StripeConnect $stripe,
    ) {}

    public function available(): bool
    {
        // デモの店は Connect を使わない（運営の鍵で動き、本物のお金が動きうる。ADR 0086）
        return self::platformKey() !== null && self::hasNotificationHost() && ! DemoShop::is($this->currentTenant());
    }

    private function currentTenant(): ?Tenant
    {
        $tenant = tenancy()->initialized ? tenant() : null;

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * 連結アカウントの通知（ADR 0022）を受ける中央のホストがあるか。通知の経路は管理画面のホストのほかの中央のホストにだけある
     * （bootstrap/app.php の central-webhooks）。1 店舗の形（CENTRAL_DOMAINS が空）には無いので、つないでも通知が届かない
     */
    public static function hasNotificationHost(): bool
    {
        /** @var list<string> $central */
        $central = config('tenancy.central_domains');

        return array_diff($central, [config('admin.domain')]) !== [];
    }

    /**
     * 店の側の状態（管理画面に出す）
     *
     * @return array{available: bool, connected: bool, status: string|null, account_hint: string|null, mode: string|null}
     */
    public function status(): array
    {
        $gateway = self::gateway();

        return [
            'available' => $this->available(),
            'connected' => $gateway !== null,
            'status' => $gateway?->connect_status,
            'account_hint' => $gateway?->key_hint,
            'mode' => $gateway?->mode,
        ];
    }

    /**
     * つなぐ（まだなら連結アカウントを作る）。返すのは Stripe の登録の画面の 1 回だけのリンク
     */
    public function connect(Tenant $tenant, string $returnUrl, string $refreshUrl, ?string $actor = null): string
    {
        if (DemoShop::is($tenant)) {
            throw new PaymentUnavailableException('デモの店は Stripe と接続できません。テスト用の鍵（sk_test_）を入力してください。');
        }

        $key = self::platformKey() ?? throw new PaymentUnavailableException('この環境では Stripe と接続できません（運営の Stripe の鍵がありません）。');

        if (! self::hasNotificationHost()) {
            throw new PaymentUnavailableException('この環境では Stripe と接続できません（連結アカウントの通知を受ける中央のホストがありません）。');
        }
        $accountId = self::gateway()?->credentials['account_id'] ?? null;

        if (! is_string($accountId)) {
            $account = $this->stripe->createAccount($key, (string) $tenant->getTenantKey(), (string) $tenant->getAttribute('name'));
            $accountId = $account['id'];
            $mode = $account['livemode'] ? 'live' : 'test';

            // 中央の対応を先に（1 つの連結アカウントは 1 つの店だけ。一意で取り違えを止める）
            StripeConnectedAccount::query()->firstOrCreate(['tenant_id' => $tenant->getTenantKey()], ['account_id' => $accountId, 'livemode' => $account['livemode']]);

            DB::transaction(function () use ($accountId, $mode, $actor): void {
                $this->events->lock();

                if (self::gateway(lock: true) !== null) {
                    return;
                }

                $gateway = PaymentGateway::create([
                    'provider' => 'stripe_connect',
                    'credentials' => ['account_id' => $accountId],
                    'mode' => $mode,
                    'key_hint' => substr($accountId, -4),
                    'connect_status' => 'onboarding',
                ]);
                $this->events->append('payment.connect_started', 'payment_gateway', $gateway->id, ['provider' => 'stripe_connect', 'mode' => $mode], $actor);
            });
        }

        return $this->stripe->onboardingLink($key, $accountId, $returnUrl, $refreshUrl);
    }

    /**
     * Stripe に登録の状態を問い合わせ、変わっていれば記録する（登録の画面から戻ったとき・管理画面を開いたとき）
     */
    public function refresh(?string $actor = null): ?string
    {
        $key = self::platformKey();
        $gateway = self::gateway();
        $accountId = $gateway?->credentials['account_id'] ?? null;

        if ($key === null || ! is_string($accountId)) {
            return null;
        }

        $status = $this->stripe->status($key, $accountId);

        DB::transaction(function () use ($status, $actor): void {
            $this->events->lock();
            $gateway = self::gateway(lock: true);

            if ($gateway === null || $gateway->connect_status === $status) {
                return;
            }

            $from = $gateway->connect_status;
            $gateway->forceFill(['connect_status' => $status])->save();
            $this->events->append('payment.connect_status_changed', 'payment_gateway', $gateway->id, ['from' => $from, 'to' => $status], $actor);
        });

        return $status;
    }

    /**
     * つなぐのをやめる（Stripe の連結アカウントは店のものとして残る。店頭はお支払いに進めなくなる）
     */
    public function disconnect(Tenant $tenant, ?string $actor = null): void
    {
        DB::transaction(function () use ($actor): void {
            $this->events->lock();
            $gateway = self::gateway(lock: true);

            if ($gateway === null) {
                return;
            }

            $gateway->delete();
            $this->events->append('payment.connect_removed', 'payment_gateway', $gateway->id, ['provider' => 'stripe_connect'], $actor);
        });

        StripeConnectedAccount::query()->where('tenant_id', $tenant->getTenantKey())->delete();
    }

    public static function platformKey(): ?string
    {
        $key = config('payment.stripe_platform_secret');

        return is_string($key) && preg_match('/\A(sk|rk)_(test|live)_[A-Za-z0-9]{16,247}\z/', $key) === 1 ? $key : null;
    }

    private static function gateway(bool $lock = false): ?PaymentGateway
    {
        $query = PaymentGateway::query()->where('provider', 'stripe_connect');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
}
