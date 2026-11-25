<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\RefundDeclined;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use App\Core\Transaction\Services\TransactionService;
use App\Models\StripeConnectedAccount;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 注文の取消。発送の前だけ。払われていれば Stripe で全額を返金（冪等キーで 1 回だけ）してから台帳に返金を記録し、
 * 在庫を戻す。払われていなければ支払いの画面を閉じる。お金が動くので再確認つき。Stripe が断ったら何も変えない。
 */
final class CancelOrderTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BODY = 'FakeForTestsOnly000';

    private Operator $operator;

    private SoftwareAuthenticator $authenticator;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->stripe = FakeStripe::install();
        $this->tenant('coa');
        $this->inside('coa', fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => implode('_', ['whsec', self::BODY.'1234'])]));
        [$this->operator, $this->authenticator] = $this->enrolledOperator();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_paid_order_is_refunded_once_and_its_stock_returns(): void
    {
        [$sale, $listing] = $this->order('coa', paid: true);
        $url = "/api/tenants/coa/transactions/{$sale}";

        $this->send('GET', $url)->assertJsonPath('transaction.actions.cancel', true)->assertJsonPath('transaction.refundable_amount', 4400);
        // お金が動くので再確認なしでは取り消せない
        $this->send('POST', "{$url}/cancel")->assertStatus(423);
        $this->assertSame([], $this->stripe->refunds);

        $this->reconfirm($this->operator, $this->authenticator);
        $this->send('POST', "{$url}/cancel")->assertOk()
            ->assertJsonPath('transaction.order_status', 'cancelled')
            ->assertJsonPath('transaction.actions.cancel', false);

        // Stripe で全額を、支払いの ID で、冪等キーつきで返金
        $this->assertCount(1, $this->stripe->refunds);
        $this->assertSame(['amount' => 4400, 'payment_intent' => 'pi_test_1'], array_intersect_key($this->stripe->refunds[0], ['amount' => 0, 'payment_intent' => 0]));
        $this->assertSame("refund-cancel-{$sale}", $this->stripe->lastHeader('Idempotency-Key'));

        $this->inside('coa', function () use ($sale, $listing): void {
            $refund = LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->sole();
            $this->assertSame($this->stripe->refunds[0]['id'], $refund->reference);
            // 台帳は差し引き 0（請求の取り消し・受領・返金）
            $this->assertSame([], array_filter(app(LedgerService::class)->balances(Transaction::query()->findOrFail($sale))));
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $listing)->sum('reserved'));
            $this->assertSame(FulfillmentStatus::Cancelled, Fulfillment::query()->where('transaction_id', $sale)->sole()->status);
            $event = DomainEvent::query()->where('type', 'order.cancelled')->sole();
            $this->assertSame(4400, $event->payload['refunded']);
            $this->assertStringStartsWith('operator:', (string) $event->actor);
        });

        // 二度目は断る（返金もしない）
        $this->send('POST', "{$url}/cancel")->assertStatus(409);
        $this->assertCount(1, $this->stripe->refunds);
    }

    public function test_an_unpaid_order_closes_its_payment_page(): void
    {
        [$sale] = $this->order('coa', paid: false);
        $ref = $this->inside('coa', fn (): string => PaymentAttempt::query()->where('transaction_id', $sale)->sole()->provider_ref);
        $this->reconfirm($this->operator, $this->authenticator);

        $this->send('GET', "/api/tenants/coa/transactions/{$sale}")->assertJsonPath('transaction.refundable_amount', 0);
        $this->send('POST', "/api/tenants/coa/transactions/{$sale}/cancel")->assertOk()->assertJsonPath('transaction.order_status', 'cancelled');

        // 後から払われないよう、支払いの画面を閉じた。返金はしない
        $this->assertSame('expired', $this->stripe->sessions[$ref]['status']);
        $this->assertSame([], $this->stripe->refunds);
        $this->inside('coa', fn () => $this->assertSame('expired', PaymentAttempt::query()->where('transaction_id', $sale)->sole()->status));
    }

    public function test_cancel_is_refused_when_it_cannot_be_safe(): void
    {
        $this->reconfirm($this->operator, $this->authenticator);

        // 発送した注文は取り消せない（返品・返金で扱う）
        [$shipped] = $this->order('coa', paid: true);
        $this->inside('coa', function () use ($shipped): void {
            $sale = Transaction::query()->findOrFail($shipped);
            app(SaleOrderService::class)->ship($sale, (string) Fulfillment::query()->where('transaction_id', $sale->id)->value('id'), null, null, 'test');
        });
        $this->send('GET', "/api/tenants/coa/transactions/{$shipped}")->assertJsonPath('transaction.actions.cancel', false);
        $this->send('POST', "/api/tenants/coa/transactions/{$shipped}/cancel")->assertStatus(409)->assertJsonPath('user_message', '発送の前の注文だけ取り消せます（発送した注文は返品・返金で扱います）');
        // （返金は DB より先に行うので、先に確かめずに返金だけしてしまわない）
        $this->assertSame([], $this->stripe->refunds);

        // Stripe が返金を断った: 何も変えない。やり直すと、同じ冪等キーで 1 回だけ返金する
        [$paid, $listing] = $this->order('coa', paid: true);
        $this->stripe->respond = static fn (): array => ['{"error":{"type":"api_error","message":"down"}}', 500, []];
        $this->send('POST', "/api/tenants/coa/transactions/{$paid}/cancel")->assertStatus(503)->assertJsonPath('user_message', 'Stripe で返金できませんでした。時間をおいて、もう一度お試しください。');
        $this->inside('coa', function () use ($paid, $listing): void {
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($paid)->status);
            $this->assertSame(1, (int) InventoryStock::query()->where('listing_id', $listing)->sum('reserved'));
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $paid)->where('type', JournalType::Refund)->count());
        });
        $this->stripe->respond = null;
        $this->send('POST', "/api/tenants/coa/transactions/{$paid}/cancel")->assertOk();
        $this->assertCount(1, $this->stripe->refunds);

        // まだ確かめていない支払いが届いていた（支払いの画面は払われている）: 取り消さない
        [$arriving] = $this->order('coa', paid: false);
        $ref = $this->inside('coa', fn (): string => PaymentAttempt::query()->where('transaction_id', $arriving)->sole()->provider_ref);
        $this->stripe->sessions[$ref] = ['payment_status' => 'paid', 'status' => 'complete'] + $this->stripe->sessions[$ref];
        $this->send('POST', "/api/tenants/coa/transactions/{$arriving}/cancel")->assertStatus(503)->assertJsonPath('user_message', fn (string $m): bool => str_contains($m, '支払いが届いています'));
        $this->inside('coa', fn () => $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($arriving)->status));
    }

    public function test_a_refund_never_dangles_when_shipping_races_in(): void
    {
        [$sale, $listing] = $this->order('coa', paid: true);
        $fulfillmentId = $this->inside('coa', fn (): string => (string) Fulfillment::query()->where('transaction_id', $sale)->value('id'));

        // 返金の通信（DB のロックの外）の最中に、別の運営が発送を完了させる競合を仕込む
        $raced = false;
        $this->stripe->respond = function () use ($sale, $fulfillmentId, &$raced): array {
            if (! $raced) {
                $raced = true;

                try {
                    // 返金のコールバックは cancel の店の文脈の中なので、入れ子にせず直接呼ぶ
                    app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale), $fulfillmentId, null, null, 'race');
                } catch (TransactionException) {
                    // 直したあとは、返金の前に取消が確定していて発送は弾かれる（＝正しい）
                }
            }

            return ['{"id":"re_race","object":"refund","amount":4400,"payment_intent":"pi_test_1","status":"succeeded"}', 200, []];
        };

        $this->inside('coa', fn () => app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:test'));

        // 返金が Stripe で実行されたのなら、取消と台帳の返金記録も必ず残る（返金だけが宙に浮かない）
        $this->assertTrue($raced, '返金が呼ばれていない');
        $this->inside('coa', function () use ($sale, $listing): void {
            $this->assertContains(Transaction::query()->findOrFail($sale)->status, [TransactionStatus::Cancelled, TransactionStatus::Refunded], '返金したのに取消されていない');
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count(), '返金したのに台帳に返金の記録が無い');
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $listing)->sum('reserved'), '取消したのに在庫が戻っていない');
        });
    }

    public function test_two_people_cancelling_at_once_refund_and_book_only_once(): void
    {
        [$sale, $listing] = $this->order('coa', paid: true);

        // 1 人目の返金の通信の最中に、2 人目が取消を押す（同じ返金の記録を続け、同じ冪等キーで頼む）
        $inner = false;
        $this->stripe->respond = function () use ($sale, &$inner): array {
            if (! $inner) {
                $inner = true;
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:second');
            }

            return ['{"id":"re_same","object":"refund","amount":4400,"payment_intent":"pi_test_1","status":"succeeded"}', 200, []];
        };

        $this->inside('coa', function () use ($sale, $listing): void {
            try {
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:first');
                $this->fail('先に取り消し終えた注文を、もう一度取り消した');
            } catch (TransactionException) {
                // 2 人目が先に終えたので、1 人目は「動かせない」で止まる（返金は同じ 1 つ）
            }

            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame(1, Refund::query()->where('transaction_id', $sale)->count());
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count());
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $listing)->sum('reserved'));
            $this->assertSame(1, DomainEvent::query()->where('type', 'order.cancelled')->count());
        });
    }

    public function test_a_pending_refund_cancels_the_order_but_waits_to_book_the_refund(): void
    {
        [$sale, $listing] = $this->order('coa', paid: true);
        $this->stripe->refundStatus = 'pending';

        $this->inside('coa', function () use ($sale, $listing): void {
            $result = app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:test');
            $this->assertSame([0, 4400], [$result['refunded'], $result['refund_pending']]);

            // 注文は取消（在庫も戻る）。返金は Stripe で保留なので、台帳にはまだ書かない（お金を預かったまま、ADR 0027）
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count());
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $listing)->sum('reserved'));
            $refund = Refund::query()->where('transaction_id', $sale)->sole();
            $this->assertSame(['pending', $this->stripe->refunds[0]['id'], null], [$refund->status, $refund->provider_ref, $refund->settled_at]);
            $this->assertEquals(['refunded' => 0, 'refund_pending' => 4400, 'attempts_expired' => 0], DomainEvent::query()->where('type', 'order.cancelled')->sole()->payload);
        });
    }

    public function test_a_declined_refund_leaves_the_order_open_and_a_retry_uses_a_new_key(): void
    {
        [$sale, $listing] = $this->order('coa', paid: true);
        $fulfillmentId = $this->inside('coa', fn (): string => (string) Fulfillment::query()->where('transaction_id', $sale)->value('id'));
        $cancel = fn () => $this->inside('coa', fn () => app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:test'));

        // Stripe がはっきり断った（4xx）: 記録は失敗、注文はそのまま（在庫も押さえたまま）
        $this->stripe->respond = static fn (): array => ['{"error":{"type":"invalid_request_error","code":"charge_already_refunded","message":"x"}}', 400, []];

        try {
            $cancel();
            $this->fail('断られたのに取り消した');
        } catch (RefundDeclined $e) {
            $this->assertSame(['charge_already_refunded', 409], [$e->reason, $e->status()]);
        }

        // Stripe が「失敗」と答えた: 同じく取り消さない
        $this->stripe->respond = null;
        $this->stripe->refundStatus = 'failed';

        try {
            $cancel();
            $this->fail('失敗の答えなのに取り消した');
        } catch (RefundDeclined $e) {
            $this->assertSame('insufficient_funds', $e->reason);
        }

        $this->inside('coa', function () use ($sale, $listing): void {
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame(1, (int) InventoryStock::query()->where('listing_id', $listing)->sum('reserved'));
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count());
            $this->assertSame([['failed', 'charge_already_refunded'], ['failed', 'insufficient_funds']], Refund::query()->where('transaction_id', $sale)->orderBy('created_at')->orderBy('id')->get()->map(fn (Refund $r): array => [$r->status, $r->failure_reason])->all());
            // 途中の返金は無いので、発送は止めない
            $this->assertFalse(app(PaymentRefunds::class)->cancelInProgress($sale));
        });

        // 直ってからやり直す: 前の断りを Stripe が返し続けないよう、新しい冪等キーで 1 回だけ返金して取り消す
        $this->stripe->refundStatus = 'succeeded';
        $this->assertSame(4400, $cancel()['refunded']);
        $this->assertSame("refund-cancel-{$sale}-3", $this->stripe->lastHeader('Idempotency-Key'));
        $this->inside('coa', fn () => $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count()));
    }

    public function test_an_unknown_answer_keeps_the_refund_open_stops_shipping_and_resumes_with_the_same_key(): void
    {
        [$sale] = $this->order('coa', paid: true);
        $fulfillmentId = $this->inside('coa', fn (): string => (string) Fulfillment::query()->where('transaction_id', $sale)->value('id'));

        // Stripe に届いたか分からない（5xx）
        $this->stripe->respond = static fn (): array => ['{"error":{"type":"api_error","message":"down"}}', 500, []];
        $this->inside('coa', function () use ($sale, $fulfillmentId): void {
            try {
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:test');
                $this->fail('応答が分からないのに取り消した');
            } catch (PaymentUnavailableException) {
            }

            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame(['requested', "refund-cancel-{$sale}"], [Refund::query()->where('transaction_id', $sale)->sole()->status, Refund::query()->where('transaction_id', $sale)->sole()->idempotency_key]);

            // 返金の途中は発送できない（返金だけが残るのを防ぐ）
            try {
                app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale), $fulfillmentId, null, null, 'test');
                $this->fail('返金の途中なのに発送できた');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('取消と返金の処理中', $e->getMessage());
            }
        });

        // もう一度押すと、同じ記録・同じ冪等キーで続きから（返金は 1 回）
        $this->stripe->respond = null;
        $this->inside('coa', fn () => app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:test'));
        $this->assertCount(1, $this->stripe->refunds);
        $this->assertSame("refund-cancel-{$sale}", $this->stripe->lastHeader('Idempotency-Key'));
        $this->inside('coa', function () use ($sale): void {
            $this->assertSame('succeeded', Refund::query()->where('transaction_id', $sale)->sole()->status);
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($sale)->status);
        });
    }

    public function test_a_connected_shop_refunds_on_its_own_account(): void
    {
        config(['payment.stripe_platform_secret' => implode('_', ['sk', 'test', self::BODY.'Platform'])]);
        $this->tenant('cob');
        StripeConnectedAccount::create(['tenant_id' => 'cob', 'account_id' => 'acct_bbbbbbbbbbbbbbbb', 'livemode' => false]);
        $this->inside('cob', fn () => PaymentGateway::create(['provider' => 'stripe_connect', 'credentials' => ['account_id' => 'acct_bbbbbbbbbbbbbbbb'], 'mode' => 'test', 'key_hint' => 'bbbb', 'connect_status' => 'active']));
        [$sale] = $this->order('cob', paid: true);
        $this->reconfirm($this->operator, $this->authenticator);

        $this->send('POST', "/api/tenants/cob/transactions/{$sale}/cancel")->assertOk();
        $this->assertSame('acct_bbbbbbbbbbbbbbbb', $this->stripe->lastHeader('Stripe-Account'));
        $this->assertCount(1, $this->stripe->refunds);
    }

    public function test_a_payment_taken_on_another_account_is_not_refunded_on_the_current_one(): void
    {
        // 店の鍵を貼る形で払われた後に、「Stripe とつなぐ」へ移した（運営が手元で試して見つけた）
        [$sale, $listing] = $this->order('coa', paid: true);
        $this->inside('coa', fn () => $this->assertNull(PaymentAttempt::query()->where('transaction_id', $sale)->sole()->stripe_account));
        config(['payment.stripe_platform_secret' => implode('_', ['sk', 'test', self::BODY.'Platform'])]);
        StripeConnectedAccount::create(['tenant_id' => 'coa', 'account_id' => 'acct_aaaaaaaaaaaaaaaa', 'livemode' => false]);
        $this->inside('coa', function (): void {
            app(PaymentGatewayService::class)->removeStripe();
            PaymentGateway::create(['provider' => 'stripe_connect', 'credentials' => ['account_id' => 'acct_aaaaaaaaaaaaaaaa'], 'mode' => 'test', 'key_hint' => 'aaaa', 'connect_status' => 'active']);
        });
        $this->reconfirm($this->operator, $this->authenticator);

        // つないだアカウントに、前のアカウントの支払いの返金を頼まない。分かる文で断り、何も変えない
        $this->send('POST', "/api/tenants/coa/transactions/{$sale}/cancel")->assertStatus(503)->assertJsonPath('user_message', PaymentRefunds::OTHER_ACCOUNT);
        $this->assertSame([], $this->stripe->refunds);
        $this->inside('coa', function () use ($sale, $listing): void {
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame(1, (int) InventoryStock::query()->where('listing_id', $listing)->sum('reserved'));
        });

        // つないだ後の注文は、つないだアカウントで作り、そのアカウントで返金する
        [$after] = $this->order('coa', paid: true);
        $this->inside('coa', fn () => $this->assertSame('acct_aaaaaaaaaaaaaaaa', PaymentAttempt::query()->where('transaction_id', $after)->sole()->stripe_account));
        $this->send('POST', "/api/tenants/coa/transactions/{$after}/cancel")->assertOk();
        $this->assertSame('acct_aaaaaaaaaaaaaaaa', $this->stripe->lastHeader('Stripe-Account'));
    }

    /**
     * Stripe の支払いの画面を作った注文（払われていれば支払いを確定）
     *
     * @return array{0: string, 1: string} 取引と商品
     */
    private function order(string $shop, bool $paid): array
    {
        return $this->inside($shop, function () use ($paid): array {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->orderBy('id')->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子']);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test');
            $line = $sale->lines()->where('line_type', LineType::Item)->sole();
            app(FulfillmentService::class)->create($sale, FulfillmentMethod::Shipping, [$line->id => 1], $location, ['name' => '山田 花子'], 'test');
            $session = app(PaymentService::class)->startCheckout($sale, 'x', 'y');

            if ($paid) {
                $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$sale->id, 'checkout.session.completed', $session->ref, $sale->id, 4400, 'jpy', 'pi_test_1'));
            }

            return [$sale->id, $listing->id];
        });
    }
}
