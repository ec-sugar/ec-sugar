<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\StoredPaymentMethod;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\StoredPayments;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Storefront\Checkout\CheckoutOrder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 預けたカードでの取引の請求と確定（ADR 0045 の 3）。請求の前に stored の支払いの記録を仮の参照で作り、答えの支払いの ID に置き換える。
 * 確定は payment_intent.succeeded の知らせで、支払いの画面と同じ決まり（台帳・支払いの段へ・二度払い・金額の食い違い）。払われていない注文の片付けは、この注文に触らない
 */
final class StoredChargeTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const string BODY = 'FakeForTestsOnly000';

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
        $this->stripe = FakeStripe::install();
        $this->tenant('sca');
        $this->inside('sca', fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => $this->secret()]));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_stored_charge_is_recorded_first_and_confirmed_by_the_notice(): void
    {
        [$sale, $card] = $this->inside('sca', fn (): array => $this->saleWithCard());

        $charge = $this->inside('sca', fn () => app(StoredPayments::class)->chargeTransaction(Transaction::query()->findOrFail($sale), StoredPaymentMethod::query()->findOrFail($card), $this->account($sale), 1, 'system:subscriptions'));
        $this->assertSame('succeeded', $charge->status);
        $this->assertSame("stored:{$sale}:1", $this->stripe->lastHeader('Idempotency-Key'));

        // 記録は答えの支払いの ID に置き換わり、まだ開いたまま（確定は知らせで）
        $this->inside('sca', function () use ($sale, $charge): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale)->sole();
            $this->assertSame(['stored', $charge->ref, 'open'], [$attempt->kind, $attempt->provider_ref, $attempt->status]);
            $this->assertSame(Phase::Agreement, Transaction::query()->findOrFail($sale)->phase);
        });

        // 同じ回数でもう一度呼んでも、記録も請求も 1 つ
        $this->inside('sca', fn () => app(StoredPayments::class)->chargeTransaction(Transaction::query()->findOrFail($sale), StoredPaymentMethod::query()->findOrFail($card), $this->account($sale), 1, 'system:subscriptions'));
        $this->assertCount(1, $this->stripe->intents);
        $this->inside('sca', fn () => $this->assertSame(1, PaymentAttempt::query()->where('transaction_id', $sale)->count()));

        // 払われた知らせ → 支払いの画面と同じく確定（台帳・支払いの段）。送り直しでも 1 回
        $this->deliver('evt_s1', (string) $charge->ref, $sale, 3300)->assertOk();
        $this->deliver('evt_s1', (string) $charge->ref, $sale, 3300)->assertOk();
        $this->inside('sca', function () use ($sale): void {
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($sale)->phase);
            $this->assertSame('paid', PaymentAttempt::query()->where('transaction_id', $sale)->sole()->status);
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Payment)->count());
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.stored_charge_started')->count());
        });
    }

    public function test_a_notice_that_arrives_before_the_answer_is_still_matched(): void
    {
        [$sale, $card] = $this->inside('sca', fn (): array => $this->saleWithCard());

        // 答えが分からない（通信の失敗）: 記録は仮の参照のまま
        $this->stripe->chargeOutcome = 'server';
        $this->assertSame('unknown', $this->inside('sca', fn () => app(StoredPayments::class)->chargeTransaction(Transaction::query()->findOrFail($sale), StoredPaymentMethod::query()->findOrFail($card), $this->account($sale), 1, 'system:subscriptions'))->status);
        $this->inside('sca', fn () => $this->assertSame("stored:{$sale}:1", PaymentAttempt::query()->where('transaction_id', $sale)->sole()->provider_ref));

        // 実は Stripe では通っていて、知らせが届いた: 取引の ID で記録を見つけ、支払いの ID に置き換えて確定する
        $this->deliver('evt_s2', 'pi_test_arrived_first', $sale, 3300)->assertOk();
        $this->inside('sca', function () use ($sale): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale)->sole();
            $this->assertSame(['pi_test_arrived_first', 'paid'], [$attempt->provider_ref, $attempt->status]);
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($sale)->phase);
        });
    }

    public function test_checkout_payments_mismatched_amounts_and_other_customers_are_not_confirmed_here(): void
    {
        [$sale, $card] = $this->inside('sca', fn (): array => $this->saleWithCard());

        // stored の記録の無い支払い（支払いの画面から生まれたもの）は扱わない
        $this->deliver('evt_s3', 'pi_test_from_checkout', $sale, 3300)->assertOk();
        $this->inside('sca', fn () => $this->assertSame(Phase::Agreement, Transaction::query()->findOrFail($sale)->phase));

        // ほかのお客さんの台帳の注文には、このカードで請求しない
        $this->inside('sca', function () use ($sale, $card): void {
            $other = app(CustomerAccounts::class)->forEmail('taro@example.com', 'storefront');
            try {
                app(StoredPayments::class)->chargeTransaction(Transaction::query()->findOrFail($sale), StoredPaymentMethod::query()->findOrFail($card), $other->id, 1, 'system:subscriptions');
                $this->fail('ほかのお客さんの注文に請求した');
            } catch (PaymentUnavailableException) {
            }
        });

        // 金額が記録と合わない知らせは、支払いにせず残す
        $charge = $this->inside('sca', fn () => app(StoredPayments::class)->chargeTransaction(Transaction::query()->findOrFail($sale), StoredPaymentMethod::query()->findOrFail($card), $this->account($sale), 1, 'system:subscriptions'));
        $this->deliver('evt_s4', (string) $charge->ref, $sale, 999)->assertOk();
        $this->inside('sca', function () use ($sale): void {
            $this->assertSame(Phase::Agreement, Transaction::query()->findOrFail($sale)->phase);
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.mismatch')->count());
        });
    }

    public function test_the_unpaid_order_sweep_leaves_stored_charge_orders_alone(): void
    {
        [$sale, $card] = $this->inside('sca', fn (): array => $this->saleWithCard());
        $this->stripe->chargeOutcome = 'requires_action';
        $this->inside('sca', fn () => app(StoredPayments::class)->chargeTransaction(Transaction::query()->findOrFail($sale), StoredPaymentMethod::query()->findOrFail($card), $this->account($sale), 1, 'system:subscriptions'));

        // 本人確認を待つ注文は、支払いの画面の期限が過ぎても片付けで取り消さない（定期便が 7 日待つ）
        $this->travel(3)->hours();
        $this->artisan('storefront:expire-unpaid-orders')->assertSuccessful();
        $this->inside('sca', fn () => $this->assertSame(Phase::Agreement, Transaction::query()->findOrFail($sale)->phase));

        // 記録の期限（7 日）を過ぎても、片付けはこの注文を見ない（支払いの画面として Stripe に問い合わせることもしない）
        $before = count($this->stripe->requests);
        $this->travel(8)->days();
        $this->artisan('storefront:expire-unpaid-orders')->assertSuccessful();
        $this->assertSame($before, count($this->stripe->requests));
        $this->inside('sca', fn () => $this->assertSame(Phase::Agreement, Transaction::query()->findOrFail($sale)->phase));
    }

    public function test_an_unpaid_stored_charge_order_can_be_cancelled_without_touching_checkout_pages(): void
    {
        [$sale, $card] = $this->inside('sca', fn (): array => $this->saleWithCard());
        $this->stripe->chargeOutcome = 'requires_action';
        $this->inside('sca', fn () => app(StoredPayments::class)->chargeTransaction(Transaction::query()->findOrFail($sale), StoredPaymentMethod::query()->findOrFail($card), $this->account($sale), 1, 'system:subscriptions'));
        $before = count($this->stripe->requests);

        // 本人確認を待つ注文を取り消す（定期便が 7 日待った後など）: 支払いの画面には触れず、本人確認待ちの請求だけを Stripe で取り消して、在庫を戻して閉じる
        $this->inside('sca', function () use ($sale): void {
            $result = app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'system:subscriptions');
            $this->assertSame(0, $result['refunded']);
            $this->assertNotSame(TransactionStatus::Open, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame(0, (int) InventoryStock::query()->sum('reserved'));
        });
        $after = array_slice($this->stripe->requests, $before);
        $this->assertSame([['get', 'payment_intents'], ['post', 'payment_intents']], array_map(static fn (array $r): array => [$r['method'], explode('/', (string) parse_url($r['url'], PHP_URL_PATH))[2] ?? ''], $after));
        $this->assertCount(1, $this->stripe->cancelledIntents);
        $this->inside('sca', fn () => $this->assertSame(['failed'], PaymentAttempt::query()->where('transaction_id', $sale)->pluck('status')->all()));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function saleWithCard(): array
    {
        $seller = Seller::query()->firstOrFail();
        $location = Location::query()->firstOrFail();
        $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'コーヒー豆', 'unit_amount' => 3300, 'status' => 'active']);
        app(StockService::class)->receive($listing, $location, 3);
        $account = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'storefront');
        CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);
        $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com']);
        $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test', customerAccount: $account);
        CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);
        $ref = app(StoredPayments::class)->customerFor(CustomerAccount::query()->findOrFail($account->id));
        $card = StoredPaymentMethod::create(['customer_account_id' => $account->id, 'provider' => 'stripe', 'account' => 'test:own', 'customer_ref' => $ref, 'method_ref' => 'pm_test_card', 'status' => 'usable', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2028]);

        return [$sale->id, $card->id];
    }

    private function account(string $sale): string
    {
        return (string) Transaction::query()->findOrFail($sale)->customer_account_id;
    }

    /**
     * @return TestResponse<Response>
     */
    private function deliver(string $id, string $intent, string $sale, int $amount): TestResponse
    {
        $payload = (string) json_encode(['id' => $id, 'object' => 'event', 'type' => 'payment_intent.succeeded', 'livemode' => false, 'created' => time(), 'data' => ['object' => [
            'id' => $intent, 'object' => 'payment_intent', 'amount' => $amount, 'amount_received' => $amount, 'currency' => 'jpy', 'status' => 'succeeded', 'metadata' => ['transaction_id' => $sale, 'tenant' => 'sca'],
        ]]]);
        $time = time();
        $response = $this->call('POST', 'http://sca.localhost/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", $this->secret())], $payload);
        tenancy()->end();

        return $response;
    }

    private function secret(): string
    {
        return implode('_', ['whsec', self::BODY.'sca']);
    }
}
