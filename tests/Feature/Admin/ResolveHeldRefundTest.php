<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\RefundResult;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 止めた返金の片付け（ADR 0048 の 4）。額が違って止めた返金と Stripe の画面でした返金を、店の人が Stripe で確かめてから
 * 「この額で記録する」か「返金は無かった」で閉じる。お金の段階と操作の直前の確かめが要る。記録すると台帳に返金を書き、取消の返金なら取消を終える
 */
final class ResolveHeldRefundTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BODY = 'FakeForTestsOnly000';

    private FakeStripe $stripe;

    private Operator $owner;

    private SoftwareAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->stripe = FakeStripe::install();
        $this->tenant('rha');
        $this->tenant('rhb');
        foreach (['rha', 'rhb'] as $shop) {
            $this->inside($shop, fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => implode('_', ['whsec', self::BODY.'1234'])]));
        }
        [$this->owner, $this->authenticator] = $this->enrolledOperator();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_held_cancel_refund_recorded_with_the_confirmed_amount_finishes_the_cancel(): void
    {
        [$sale, $refund] = $this->heldCancel();
        $url = "/api/tenants/rha/transactions/{$sale}/refunds/{$refund}/resolve";

        // 直前の確かめが無ければ断る
        $this->send('POST', $url, ['outcome' => 'recorded', 'amount' => 4000])->assertStatus(423);

        $this->reconfirm($this->owner, $this->authenticator);
        $this->send('POST', $url, ['outcome' => 'recorded', 'amount' => 4000])->assertOk()
            ->assertJsonPath('transaction.refunds.0.status', 'succeeded')
            ->assertJsonPath('transaction.refunds.0.resolution', 'recorded')
            ->assertJsonPath('transaction.refunds.0.resolvable', false);

        $this->inside('rha', function () use ($sale, $refund): void {
            $row = Refund::query()->findOrFail($refund);
            $this->assertSame([4000, 'succeeded', null], [$row->amount, $row->status, $row->failure_reason]);
            $this->assertNotNull($row->resolved_at);
            // 台帳に確かめた額の返金を 1 回、注文は取り消した
            $this->assertSame([4000], LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->get()->map(static fn (LedgerJournal $j): int => (int) $j->postings()->where('amount', '>', 0)->sum('amount'))->all());
            $transaction = Transaction::query()->findOrFail($sale);
            $this->assertSame(Phase::Cancel, $transaction->phase);
            $this->assertEquals(['refund' => $refund, 'purpose' => 'cancel', 'resolution' => 'recorded', 'amount' => 4000, 'requested_amount' => 4400], DomainEvent::query()->where('type', 'payment.refund_resolved')->sole()->payload);
            $this->assertSame(1, DomainEvent::query()->where('type', 'order.cancelled')->count());
        });

        // 二度目は断る（もう止めていない）
        $this->send('POST', $url, ['outcome' => 'dismissed'])->assertStatus(409);
    }

    public function test_a_held_cancel_refund_dismissed_leaves_the_order_open_and_cancel_asks_again(): void
    {
        [$sale, $refund] = $this->heldCancel();
        $this->reconfirm($this->owner, $this->authenticator);
        $this->send('POST', "/api/tenants/rha/transactions/{$sale}/refunds/{$refund}/resolve", ['outcome' => 'dismissed'])->assertOk()
            ->assertJsonPath('transaction.refunds.0.status', 'failed')
            ->assertJsonPath('transaction.refunds.0.resolution', 'dismissed')
            ->assertJsonPath('transaction.actions.cancel', true);

        $this->inside('rha', function () use ($sale): void {
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count());

            // 取消をもう一度押すと、新しい冪等キーで頼み直して取り消せる
            $result = app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:test');
            $this->assertSame(4400, $result['refunded']);
            $this->assertSame(2, Refund::query()->where('transaction_id', $sale)->count());
        });
        $this->assertSame("refund-cancel-{$sale}-2", $this->stripe->lastHeader('Idempotency-Key'));
    }

    public function test_a_dashboard_refund_recorded_goes_to_the_ledger_and_cannot_exceed_what_was_paid(): void
    {
        $sale = $this->paidOrder('rha', 'pi_dash');
        $refund = $this->inside('rha', function (): string {
            app(PaymentNotices::class)->refundUpdated('stripe', 'evt_dash', 'refund.created', new RefundResult('re_dash', 'succeeded', null, 1500, 'pi_dash'));

            return Refund::query()->sole()->id;
        });
        $url = "/api/tenants/rha/transactions/{$sale}/refunds/{$refund}/resolve";
        $this->reconfirm($this->owner, $this->authenticator);

        // 受け取った額を超える額は断り、何も変えない
        $this->send('POST', $url, ['outcome' => 'recorded', 'amount' => 4401])->assertStatus(409)->assertJsonPath('user_message', PaymentRefunds::RESOLVE_OVER_PAID);
        $this->send('POST', $url, ['outcome' => 'recorded'])->assertStatus(422);
        $this->inside('rha', fn () => $this->assertSame(['held', 1500], [Refund::query()->sole()->status, Refund::query()->sole()->amount]));

        $this->send('POST', $url, ['outcome' => 'recorded', 'amount' => 1500])->assertOk();
        $this->inside('rha', function () use ($sale): void {
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count());
            // 注文はそのまま（外でした返金は取消ではない）
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame(['succeeded', 'recorded'], [Refund::query()->sole()->status, Refund::query()->sole()->resolution]);
        });
    }

    public function test_a_dashboard_refund_of_a_stray_payment_marks_it_refunded_outside_the_ledger(): void
    {
        $this->inside('rha', function (): void {
            [$sale, $first] = $this->checkout();
            PaymentAttempt::query()->where('provider_ref', $first)->update(['expires_at' => now()->addMinute()]);
            $second = app(PaymentService::class)->startCheckout($sale, 'x', 'y')->ref;
            $payments = app(PaymentNotices::class);
            $payments->confirmPaid('stripe', 'evt_s1', 'checkout.session.completed', $first, $sale->id, 4400, 'jpy', 'pi_s1');
            $payments->confirmPaid('stripe', 'evt_s2', 'checkout.session.completed', $second, $sale->id, 4400, 'jpy', 'pi_s2');
            $payments->refundUpdated('stripe', 'evt_s3', 'refund.created', new RefundResult('re_s2', 'succeeded', null, 4400, 'pi_s2'));

            app(PaymentRefunds::class)->resolve(Refund::query()->sole(), 'recorded', 4400, 'operator:test');

            $this->assertSame('refunded', StrayPayment::query()->sole()->status);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Refund)->count());
        });
    }

    public function test_only_money_staff_with_a_recent_confirmation_may_resolve_and_other_shops_are_not_reached(): void
    {
        [$sale, $refund] = $this->heldCancel();
        $this->send('POST', '/auth/logout')->assertNoContent();

        [$staff, $authenticator] = $this->enrolledOperator('operate@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'rha', 'operate', 'test');
        $this->reconfirm($staff, $authenticator);
        $this->send('POST', "/api/tenants/rha/transactions/{$sale}/refunds/{$refund}/resolve", ['outcome' => 'dismissed'])->assertForbidden();

        // ほかの店の返金の ID は見つからない（店の境界）
        $this->send('POST', '/auth/logout')->assertNoContent();
        $this->resetRateLimit();
        $assertion = $this->authenticator->assert($this->ceremonyOptions('/auth/login/options'), $this->owner->getPasskeyUserHandle());
        $this->send('POST', '/auth/login', ['credential' => $assertion])->assertOk();
        $this->reconfirm($this->owner, $this->authenticator);
        $this->send('POST', "/api/tenants/rhb/transactions/{$sale}/refunds/{$refund}/resolve", ['outcome' => 'dismissed'])->assertNotFound();
        $this->inside('rha', fn () => $this->assertSame('held', Refund::query()->findOrFail($refund)->status));
    }

    public function test_a_refund_that_is_not_held_cannot_be_resolved_and_the_database_keeps_the_outcome_consistent(): void
    {
        $sale = $this->paidOrder('rha', 'pi_plain');

        $this->inside('rha', function () use ($sale): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale)->where('status', 'paid')->sole();
            $refund = Refund::create(['transaction_id' => $sale, 'attempt_id' => $attempt->id, 'purpose' => 'cancel', 'amount' => 4400, 'currency' => 'jpy', 'payment_ref' => 'pi_plain', 'idempotency_key' => 'refund-cancel-plain', 'status' => 'requested']);

            try {
                app(SaleOrderService::class)->resolveCancelRefund(Transaction::query()->findOrFail($sale), $refund, 'recorded', 4400, 'operator:test');
                $this->fail('止めていない返金を片付けた');
            } catch (PaymentUnavailableException $e) {
                $this->assertSame(PaymentRefunds::NOT_HELD, $e->getMessage());
            }

            // 片付けの結果と状態が食い違う行は DB が断る
            $this->expectException(QueryException::class);
            Refund::query()->whereKey($refund->id)->update(['resolution' => 'recorded', 'resolved_at' => now(), 'status' => 'failed', 'failure_reason' => 'x', 'settled_at' => now()]);
        });
    }

    /**
     * 額が違って止めた取消の返金（Stripe が 4,000 円の返金を返した）
     *
     * @return array{0: string, 1: string}
     */
    private function heldCancel(): array
    {
        $sale = $this->paidOrder('rha', 'pi_held');
        $this->stripe->respond = static fn (array $params): array => [(string) json_encode(['id' => 're_short', 'object' => 'refund', 'amount' => 4000, 'status' => 'succeeded']), 200, []];
        $refund = $this->inside('rha', function () use ($sale): string {
            try {
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:test');
            } catch (PaymentUnavailableException) {
                // 止めた（HELD）
            }

            return Refund::query()->where('transaction_id', $sale)->where('status', 'held')->sole()->id;
        });
        $this->stripe->respond = null;

        return [$sale, $refund];
    }

    private function paidOrder(string $shop, string $paymentRef): string
    {
        return $this->inside($shop, function () use ($paymentRef): string {
            [$sale, $session] = $this->checkout();
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', "evt_{$session}", 'checkout.session.completed', $session, $sale->id, 4400, 'jpy', $paymentRef));

            return $sale->id;
        });
    }

    /**
     * @return array{0: Transaction, 1: string}
     */
    private function checkout(): array
    {
        $seller = Seller::query()->firstOrFail();
        $location = Location::query()->firstOrFail();
        $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
        app(StockService::class)->receive($listing, $location, 3);
        $sale = app(TransactionService::class)->open('sale', $seller, Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子']), [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test');

        return [$sale, app(PaymentService::class)->startCheckout($sale, 'x', 'y')->ref];
    }
}
