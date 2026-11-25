<?php

declare(strict_types=1);

namespace App\Core\Payment\Stripe;

/**
 * Stripe Connect のドライバ（Accounts v2。ADR 0022）。v2 の呼び出しはここだけに閉じ込める（API が変わっても直すのは 1 か所）。
 * 店の連結アカウントは「店が売り手、Stripe の手数料は店、損は Stripe 側、店は自分の Stripe のダッシュボードを持つ」形で作る。
 * 店主の個人情報（メールなど）はこちらから送らない（Stripe の登録の画面で店主が入れる）
 */
final class StripeConnect
{
    private const string FAILED = 'Stripe と接続できませんでした。時間をおいて、もう一度お試しください。';

    public function __construct(private readonly StripeApi $api) {}

    /**
     * 店の連結アカウントを作る。冪等キーは店ごとなので、二度押しや送り直しでもアカウントは 1 つ
     *
     * @return array{id: string, livemode: bool}
     */
    public function createAccount(string $platformKey, string $tenantId, string $displayName): array
    {
        $account = $this->api->call(fn () => $this->api->client($platformKey)->v2->core->accounts->create([
            'display_name' => mb_substr($displayName, 0, 100),
            'dashboard' => 'full',
            'identity' => ['country' => 'jp'],
            'configuration' => ['merchant' => ['capabilities' => ['card_payments' => ['requested' => true]]]],
            'defaults' => [
                'currency' => 'jpy',
                'locales' => ['ja-JP'],
                'responsibilities' => ['fees_collector' => 'stripe', 'losses_collector' => 'stripe'],
            ],
            'metadata' => ['tenant' => $tenantId],
        ], ['idempotency_key' => "connect-account-{$tenantId}"]), 'Stripe に店の連結アカウントを作れなかった', self::FAILED);

        return ['id' => (string) $account->id, 'livemode' => (bool) $account->livemode];
    }

    /**
     * Stripe の登録の画面への 1 回だけ使えるリンク（数分で切れる）
     */
    public function onboardingLink(string $platformKey, string $accountId, string $returnUrl, string $refreshUrl): string
    {
        $link = $this->api->call(fn () => $this->api->client($platformKey)->v2->core->accountLinks->create([
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => [
                    'collection_options' => ['fields' => 'eventually_due'],
                    'return_url' => $returnUrl,
                    'refresh_url' => $refreshUrl,
                ],
            ],
        ]), 'Stripe の登録の画面のリンクを作れなかった', self::FAILED);

        return (string) $link->url;
    }

    /**
     * 登録の状態（onboarding: 登録の途中 / active: 支払いを受けられる / restricted: 期限を過ぎた要件があり止められている）
     */
    public function status(string $platformKey, string $accountId): string
    {
        $account = $this->api->call(fn () => $this->api->client($platformKey)->v2->core->accounts->retrieve($accountId, ['include' => ['configuration.merchant', 'requirements']]), 'Stripe に店の連結アカウントを問い合わせられなかった', self::FAILED)->toArray();
        $cards = $account['configuration']['merchant']['capabilities']['card_payments']['status'] ?? null;
        $deadline = $account['requirements']['summary']['minimum_deadline']['status'] ?? null;

        return match (true) {
            $cards === 'active' => 'active',
            $deadline === 'past_due' => 'restricted',
            default => 'onboarding',
        };
    }
}
