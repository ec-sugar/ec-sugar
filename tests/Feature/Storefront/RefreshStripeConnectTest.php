<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Event\Models\DomainEvent;
use App\Core\Payment\Models\PaymentGateway;
use App\Models\StripeConnectedAccount;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * つないだ店の登録の状態の定期の確かめ直し。済んでいない店は 10 分ごと、止められたことに気づくため全店を 1 時間ごと。
 * Stripe に届かない店は飛ばして、ほかの店は続ける。運営の鍵が無ければ何もしない。
 */
final class RefreshStripeConnectTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = FakeStripe::install();
        config(['payment.stripe_platform_secret' => implode('_', ['sk', 'test', 'FakeForTestsOnly000Platform'])]);
        $this->shop('rsa', 'acct_aaaaaaaaaaaaaaaa', 'onboarding', 'active');
        $this->shop('rsb', 'acct_bbbbbbbbbbbbbbbb', 'active', 'restricted', 'past_due');
        // つないでいない店は問い合わせない
        $this->tenants[] = Tenant::create(['id' => 'rsc', 'name' => '店 rsc']);
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_pending_shops_are_checked_often_and_every_shop_hourly(): void
    {
        // 済んでいない店だけ: rsa が「済んだ」になる。rsb（済み）には問い合わせない
        $this->artisan('storefront:refresh-stripe-connect --pending')->expectsOutputToContain('問い合わせ 1 店')->assertSuccessful();
        $this->assertSame(['active', 'active'], [$this->connectStatus('rsa'), $this->connectStatus('rsb')]);
        $this->assertCount(1, $this->stripe->requests);

        // 全店: rsb が止められたことに気づく。状態が変わった店だけ記録する
        $this->artisan('storefront:refresh-stripe-connect')->expectsOutputToContain('問い合わせ 2 店')->assertSuccessful();
        $this->assertSame(['active', 'restricted'], [$this->connectStatus('rsa'), $this->connectStatus('rsb')]);
        $this->inside('rsb', fn () => $this->assertEquals(['from' => 'active', 'to' => 'restricted'], DomainEvent::query()->where('type', 'payment.connect_status_changed')->sole()->payload));
        $this->inside('rsa', fn () => $this->assertSame(1, DomainEvent::query()->where('type', 'payment.connect_status_changed')->count()));
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_unreachable_stripe_and_a_missing_platform_key(): void
    {
        // Stripe に届かない: 状態は変えず、ほかの店も続けて、次の回へ
        $this->stripe->respond = static fn (): array => ['{"error":{"type":"api_error","message":"down"}}', 500, []];
        $this->artisan('storefront:refresh-stripe-connect')->expectsOutputToContain('問い合わせ 0 店、届かず次の回へ 2 店')->assertSuccessful();
        $this->assertSame(['onboarding', 'active'], [$this->connectStatus('rsa'), $this->connectStatus('rsb')]);

        // 運営の鍵が無い（パッケージ版）
        $this->stripe->respond = null;
        $this->stripe->requests = [];
        config(['payment.stripe_platform_secret' => null]);
        $this->artisan('storefront:refresh-stripe-connect')->expectsOutputToContain('何もしません')->assertSuccessful();
        $this->assertSame([], $this->stripe->requests);
    }

    private function shop(string $id, string $account, string $status, string $onStripe, ?string $deadline = null): void
    {
        $tenant = Tenant::create(['id' => $id, 'name' => "店 {$id}"]);
        $this->tenants[] = $tenant;
        StripeConnectedAccount::create(['tenant_id' => $id, 'account_id' => $account, 'livemode' => false]);
        $this->inside($id, fn () => PaymentGateway::create(['provider' => 'stripe_connect', 'credentials' => ['account_id' => $account], 'mode' => 'test', 'key_hint' => substr($account, -4), 'connect_status' => $status]));
        $this->stripe->accounts[$account] = ['id' => $account, 'object' => 'v2.core.account', 'livemode' => false, 'display_name' => null, 'card_payments' => $onStripe, 'deadline' => $deadline];
    }

    private function connectStatus(string $id): ?string
    {
        return $this->inside($id, fn (): ?string => PaymentGateway::query()->where('provider', 'stripe_connect')->value('connect_status'));
    }
}
