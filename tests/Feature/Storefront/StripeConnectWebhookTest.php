<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\Models\WebhookEvent;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Models\StripeConnectedAccount;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * つないだ店の支払い（ADR 0022）。支払いの画面は店の連結アカウントで作り、通知は運営の 1 か所（中央のホスト）で受けて、
 * 通知の account から店を引く。別の店のアカウントの通知で別の店の注文に触れない、知らないアカウントは受け取るだけ。
 */
final class StripeConnectWebhookTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const string CONNECT_SECRET = 'FakeForTestsOnly000Connect';

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
        $this->stripe = FakeStripe::install();
        config([
            'payment.stripe_platform_secret' => implode('_', ['sk', 'test', 'FakeForTestsOnly000Platform']),
            'payment.stripe_connect_webhook_secret' => $this->connectSecret(),
        ]);
        $this->shop('cwa', 'acct_aaaaaaaaaaaaaaaa');
        $this->shop('cwb', 'acct_bbbbbbbbbbbbbbbb');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_connected_shop_takes_payments_on_its_own_account(): void
    {
        [$sale, $ref] = $this->checkout('cwa');

        // 支払いの画面は店の連結アカウントで（運営の鍵 ＋ Stripe-Account）
        $this->assertSame('Stripe-Account: acct_aaaaaaaaaaaaaaaa', collect($this->stripe->requests[0]['headers'])->first(fn (string $h): bool => str_starts_with($h, 'Stripe-Account')));
        $this->assertStringContainsString(config('payment.stripe_platform_secret'), (string) collect($this->stripe->requests[0]['headers'])->first(fn (string $h): bool => str_starts_with($h, 'Authorization')));

        $this->deliver($this->completed('evt_1', 'acct_aaaaaaaaaaaaaaaa', $ref, $sale))->assertOk()->assertExactJson(['received' => true]);
        $this->inside('cwa', function () use ($sale): void {
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($sale)->phase);
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Payment)->count());
        });
        // 二度目は何もしない
        $this->deliver($this->completed('evt_1', 'acct_aaaaaaaaaaaaaaaa', $ref, $sale))->assertOk();
        $this->inside('cwa', fn () => $this->assertSame(1, WebhookEvent::query()->count()));
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_notices_cannot_reach_another_shop_or_be_forged(): void
    {
        [$saleA, $refA] = $this->checkout('cwa');

        // B のアカウントの通知に A の支払いの画面を入れても、B の店の中を探すだけ（A の注文は払われない）
        $this->deliver($this->completed('evt_x', 'acct_bbbbbbbbbbbbbbbb', $refA, $saleA))->assertOk();
        $this->assertNotPaid('cwa', $saleA);
        $this->inside('cwb', fn () => $this->assertSame('unknown_session', WebhookEvent::query()->sole()->outcome));

        // 知らないアカウント（運営自身・つないでいない店）は受け取るだけ
        $this->deliver($this->completed('evt_y', 'acct_cccccccccccccccc', $refA, $saleA))->assertOk();
        $this->deliver(['account' => null] + $this->completed('evt_z', 'acct_aaaaaaaaaaaaaaaa', $refA, $saleA))->assertOk();
        $this->assertNotPaid('cwa', $saleA);

        // 署名: 無い・店ごとの鍵で署名・古い。test と live の取り違え。店の側でつながりを外した店
        $payload = (string) json_encode($this->completed('evt_1', 'acct_aaaaaaaaaaaaaaaa', $refA, $saleA));
        $this->raw($payload, null)->assertStatus(400);
        $this->raw($payload, $this->signature($payload, implode('_', ['whsec', 'FakeForTestsOnly000cwa'])))->assertStatus(400);
        $this->raw($payload, $this->signature($payload, $this->connectSecret(), time() - 301))->assertStatus(400);
        $this->deliver(['livemode' => true] + $this->completed('evt_2', 'acct_aaaaaaaaaaaaaaaa', $refA, $saleA))->assertStatus(400);
        $this->inside('cwa', fn () => PaymentGateway::query()->where('provider', 'stripe_connect')->update(['connect_status' => 'restricted']));
        $this->deliver($this->completed('evt_3', 'acct_aaaaaaaaaaaaaaaa', $refA, $saleA))->assertOk();
        $this->assertNotPaid('cwa', $saleA);

        // 運営の Connect の鍵が無い環境では受けない。店のホストには Connect の受け口は無い
        config(['payment.stripe_connect_webhook_secret' => null]);
        $this->deliver($this->completed('evt_4', 'acct_aaaaaaaaaaaaaaaa', $refA, $saleA))->assertStatus(400);
        $this->call('POST', 'http://cwa.localhost/webhooks/stripe-connect', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        tenancy()->end();
        $this->assertNotPaid('cwa', $saleA);
    }

    /**
     * つないだ店（登録は済み）
     */
    private function shop(string $id, string $account): void
    {
        $this->tenant($id);
        StripeConnectedAccount::create(['tenant_id' => $id, 'account_id' => $account, 'livemode' => false]);
        $this->inside($id, fn () => PaymentGateway::create(['provider' => 'stripe_connect', 'credentials' => ['account_id' => $account], 'mode' => 'test', 'key_hint' => substr($account, -4), 'connect_status' => 'active']));
    }

    /**
     * @return array{0: string, 1: string} 取引の ID と支払いの画面の ID
     */
    private function checkout(string $shop): array
    {
        return $this->inside($shop, function (): array {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子']);
            $sale = app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), $customer, [LineDraft::fromListing($listing, 1, 1000, Location::query()->firstOrFail())], actor: 'test');
            app(PaymentService::class)->startCheckout($sale, 'x', 'y');

            return [$sale->id, PaymentAttempt::query()->where('transaction_id', $sale->id)->sole()->provider_ref];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function completed(string $id, ?string $account, string $ref, string $sale): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'account' => $account,
            'livemode' => false,
            'created' => time(),
            'data' => ['object' => ['id' => $ref, 'object' => 'checkout.session', 'amount_total' => 4400, 'currency' => 'jpy', 'client_reference_id' => $sale, 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1']],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    private function deliver(array $event): TestResponse
    {
        $payload = (string) json_encode($event);

        return $this->raw($payload, $this->signature($payload, $this->connectSecret()));
    }

    /**
     * 運営（中央のホスト）の受け口へ
     *
     * @return TestResponse<Response>
     */
    private function raw(string $payload, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + ($signature === null ? [] : ['HTTP_STRIPE_SIGNATURE' => $signature]);

        return $this->call('POST', 'http://localhost/webhooks/stripe-connect', [], [], [], $server, $payload);
    }

    private function signature(string $payload, string $secret, ?int $time = null): string
    {
        $time ??= time();

        return "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", $secret);
    }

    private function connectSecret(): string
    {
        return implode('_', ['whsec', self::CONNECT_SECRET]);
    }

    private function assertNotPaid(string $shop, string $sale): void
    {
        $this->inside($shop, fn () => $this->assertSame(Phase::Agreement, Transaction::query()->findOrFail($sale)->phase));
    }
}
