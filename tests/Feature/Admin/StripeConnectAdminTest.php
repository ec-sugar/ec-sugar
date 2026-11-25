<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Core\Event\Models\DomainEvent;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Models\StripeConnectedAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 「Stripe とつなぐ」（ADR 0022）。運営のプラットフォームの鍵で店の連結アカウントを作り、Stripe の登録の画面へ送る。
 * 店の側には連結アカウントの ID と登録の状態だけ、店と ID の対応は中央 DB。運営の鍵が無ければ使えない（パッケージ版）。
 */
final class StripeConnectAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private Operator $operator;

    private SoftwareAuthenticator $authenticator;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->stripe = FakeStripe::install();
        config([
            'payment.stripe_platform_secret' => implode('_', ['sk', 'test', 'FakeForTestsOnly000Platform']),
            'payment.stripe_connect_webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly000Connect']),
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_connecting_creates_one_account_and_sends_the_shop_to_stripe(): void
    {
        $this->tenant('sca');
        $this->signIn();

        $this->send('GET', '/api/tenants/sca/payment-settings')->assertOk()
            ->assertJsonPath('connect', ['available' => true, 'connected' => false, 'status' => null, 'account_hint' => null, 'mode' => null]);

        // 再確認なしではつなげない
        $this->send('POST', '/api/tenants/sca/payment-settings/stripe-connect')->assertStatus(423);
        $this->assertSame([], $this->stripe->requests);

        $this->reconfirm($this->operator, $this->authenticator);
        $url = $this->send('POST', '/api/tenants/sca/payment-settings/stripe-connect')->assertOk()->json('url');
        $this->assertStringStartsWith('https://connect.stripe.com/setup/acct_', (string) $url);

        // 連結アカウントは「店が売り手・手数料は店・損は Stripe 側・店は自分のダッシュボード」。店主の個人情報は送らない
        $create = $this->stripe->requests[0];
        $this->assertSame('https://api.stripe.com/v2/core/accounts', $create['url']);
        $this->assertSame('full', $create['params']['dashboard']);
        $this->assertSame('jp', $create['params']['identity']['country']);
        $this->assertSame(['currency' => 'jpy', 'locales' => ['ja-JP'], 'responsibilities' => ['fees_collector' => 'stripe', 'losses_collector' => 'stripe']], $create['params']['defaults']);
        $this->assertSame(['tenant' => 'sca'], $create['params']['metadata']);
        $this->assertArrayNotHasKey('contact_email', $create['params']);
        $this->assertContains('Idempotency-Key: connect-account-sca', $create['headers']);
        $this->assertContains('Authorization: Bearer '.config('payment.stripe_platform_secret'), $create['headers']);
        // 登録の画面から戻る先は、この店の設定の画面
        $link = $this->stripe->requests[1]['params'];
        $this->assertSame('http://admin.localhost/tenants/sca/settings?stripe=return', $link['use_case']['account_onboarding']['return_url']);
        $this->assertSame('http://admin.localhost/tenants/sca/settings?stripe=refresh', $link['use_case']['account_onboarding']['refresh_url']);
        // このバージョンの API に無い項目は送らない（本物の Stripe は invalid_fields で断る）
        $this->assertSame(['collection_options', 'return_url', 'refresh_url'], array_keys($link['use_case']['account_onboarding']));

        $accountId = array_key_first($this->stripe->accounts);
        $this->assertSame('sca', StripeConnectedAccount::query()->where('account_id', $accountId)->sole()->tenant_id);
        $this->inside('sca', function () use ($accountId): void {
            $gateway = PaymentGateway::query()->where('provider', 'stripe_connect')->sole();
            $this->assertSame([$accountId, 'onboarding', 'test'], [$gateway->credentials['account_id'] ?? null, $gateway->connect_status, $gateway->mode]);
            $this->assertEquals(['provider' => 'stripe_connect', 'mode' => 'test'], DomainEvent::query()->where('type', 'payment.connect_started')->sole()->payload);
            // 登録が済むまでは店頭で払えない
            $this->assertNull(app(PaymentGatewayService::class)->credentials());
        });

        // もう一度押しても（登録を続ける）アカウントは増えず、リンクだけ作り直す
        // （Stripe の冪等キーは 24 時間で切れるので、作る問い合わせそのものを送らない）
        $this->send('POST', '/api/tenants/sca/payment-settings/stripe-connect')->assertOk();
        $this->assertCount(1, $this->stripe->accounts);
        $this->assertCount(1, array_filter($this->stripe->requests, static fn (array $r): bool => str_ends_with($r['url'], '/v2/core/accounts')));
        $this->assertSame(1, StripeConnectedAccount::query()->count());
        $this->send('GET', '/api/tenants/sca/payment-settings')->assertJsonPath('connect.status', 'onboarding')->assertJsonPath('connect.account_hint', substr((string) $accountId, -4));
    }

    public function test_the_status_follows_stripe_and_payments_use_the_connected_account(): void
    {
        $this->tenant('scb');
        $this->signIn();
        $this->reconfirm($this->operator, $this->authenticator);
        $this->send('POST', '/api/tenants/scb/payment-settings/stripe-connect')->assertOk();
        $accountId = (string) array_key_first($this->stripe->accounts);

        // 戻ってきた: まだ途中 → 済んだ → 期限を過ぎた要件で止められた。変わったときだけ記録する
        $this->send('POST', '/api/tenants/scb/payment-settings/stripe-connect/refresh')->assertOk()->assertJsonPath('connect.status', 'onboarding');
        $this->stripe->accounts[$accountId]['card_payments'] = 'active';
        $this->send('POST', '/api/tenants/scb/payment-settings/stripe-connect/refresh')->assertOk()->assertJsonPath('connect.status', 'active');
        $this->inside('scb', function () use ($accountId): void {
            // 支払いは運営の鍵で、店の連結アカウントに
            $credentials = app(PaymentGatewayService::class)->credentials();
            $this->assertNotNull($credentials);
            $this->assertSame([config('payment.stripe_platform_secret'), $accountId, 'test'], [$credentials->secretKey, $credentials->accountId, $credentials->mode]);
        });
        $this->stripe->accounts[$accountId]['card_payments'] = 'restricted';
        $this->stripe->accounts[$accountId]['deadline'] = 'past_due';
        $this->send('POST', '/api/tenants/scb/payment-settings/stripe-connect/refresh')->assertOk()->assertJsonPath('connect.status', 'restricted');
        $this->inside('scb', function (): void {
            $this->assertNull(app(PaymentGatewayService::class)->credentials());
            $this->assertSame(['onboarding→active', 'active→restricted'], DomainEvent::query()->where('type', 'payment.connect_status_changed')->orderBy('sequence')->get()->map(fn ($e): string => $e->payload['from'].'→'.$e->payload['to'])->all());
        });

        // つながりを外す（再確認つき）。Stripe のアカウントは店のものとして残る
        $this->send('DELETE', '/api/tenants/scb/payment-settings/stripe-connect')->assertOk()->assertJsonPath('connect.connected', false);
        $this->assertSame(0, StripeConnectedAccount::query()->count());
        $this->assertArrayHasKey($accountId, $this->stripe->accounts);
    }

    public function test_connect_needs_the_platform_key_and_stays_inside_its_tenant(): void
    {
        $this->tenant('scc');
        $this->tenant('scd');
        $this->signIn();
        $this->reconfirm($this->operator, $this->authenticator);

        // 運営の鍵が無い環境（パッケージ版）では使えず、鍵を貼る形だけ
        config(['payment.stripe_platform_secret' => null]);
        $this->send('GET', '/api/tenants/scc/payment-settings')->assertJsonPath('connect.available', false);
        $this->send('POST', '/api/tenants/scc/payment-settings/stripe-connect')->assertStatus(503)->assertJsonPath('message', 'この環境では Stripe と接続できません（運営の Stripe の鍵がありません）。');
        $this->assertSame([], $this->stripe->requests);

        // Stripe が断る（Connect の登録が済んでいないなど）。理由はログだけ、答えは分かる文
        config(['payment.stripe_platform_secret' => implode('_', ['sk', 'test', 'FakeForTestsOnly000Platform'])]);
        $this->stripe->respond = static fn (): array => ['{"error":{"type":"invalid_request_error","code":"accounts_v2_access_blocked","message":"Accounts v2 is not enabled"}}', 400, []];
        $this->send('POST', '/api/tenants/scc/payment-settings/stripe-connect')->assertStatus(503)->assertJsonPath('user_message', 'Stripe と接続できませんでした。時間をおいて、もう一度お試しください。');
        $this->assertSame(0, StripeConnectedAccount::query()->count());
        $this->stripe->respond = null;

        // 別の店には触れない。1 つの連結アカウントは 1 つの店だけ（DB の一意）
        $this->send('POST', '/api/tenants/scc/payment-settings/stripe-connect')->assertOk();
        $this->send('GET', '/api/tenants/scd/payment-settings')->assertJsonPath('connect.connected', false);
        try {
            StripeConnectedAccount::create(['tenant_id' => 'scd', 'account_id' => StripeConnectedAccount::query()->sole()->account_id, 'livemode' => false]);
            $this->fail('同じ連結アカウントを 2 つの店につなげた');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode());
        }

        // 止めた運営・未ログイン
        $this->operator->update(['disabled_at' => now()]);
        $this->send('DELETE', '/api/tenants/scc/payment-settings/stripe-connect')->assertUnauthorized();
        $this->sessionId = null;
        $this->send('POST', '/api/tenants/scc/payment-settings/stripe-connect/refresh')->assertUnauthorized();
        $this->assertSame(1, StripeConnectedAccount::query()->count());
    }

    public function test_connect_needs_a_central_host_for_its_notifications(): void
    {
        $this->tenant('sce');
        $this->signIn();
        $this->reconfirm($this->operator, $this->authenticator);

        // 1 店舗の形（CENTRAL_DOMAINS が空）は、連結アカウントの通知を受ける中央のホストが無い。運営の鍵があっても使えない
        config(['tenancy.central_domains' => [(string) config('admin.domain')]]);
        $this->send('GET', '/api/tenants/sce/payment-settings')->assertJsonPath('connect.available', false);
        $this->send('POST', '/api/tenants/sce/payment-settings/stripe-connect')->assertStatus(503)
            ->assertJsonPath('message', 'この環境では Stripe と接続できません（連結アカウントの通知を受ける中央のホストがありません）。');
        $this->assertSame([], $this->stripe->requests);
        $this->assertSame(0, StripeConnectedAccount::query()->count());

        // 中央のホストがあれば使える
        config(['tenancy.central_domains' => ['api.shop.example.test', (string) config('admin.domain')]]);
        $this->send('GET', '/api/tenants/sce/payment-settings')->assertJsonPath('connect.available', true);
    }

    private function signIn(): void
    {
        [$this->operator, $this->authenticator] = $this->enrolledOperator();
    }
}
