<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Services\ShopHome;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Payment\RefundResult;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Payment\Stripe\StripeCheckout;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * Stripe の画面で直接した返金（外でした返金。ADR 0027 の追記、ADR の見直し 2026-10-31）。
 * 知らせ（refund.created など）を取引に引き当てて止めた返金（held・external_refund）として残し、確かめが要る返金に出す。台帳には書かない。
 * こちらが頼んで答えの届く前の返金は、外の返金と取り違えない
 */
final class ExternalRefundTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BODY = 'FakeForTestsOnly000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        FakeStripe::install();
        $this->tenant('era');
        $this->tenant('erb');
        foreach (['era', 'erb'] as $shop) {
            $this->inside($shop, fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => implode('_', ['whsec', self::BODY.'1234'])]));
        }
        // 管理画面の API はログインした運営で読む
        $this->enrolledOperator();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_dashboard_refund_is_held_for_review_without_touching_the_ledger(): void
    {
        $sale = $this->paid('era', 'pi_paid');
        $shipBefore = $this->send('GET', "/api/tenants/era/transactions/{$sale}")->json('transaction.actions.ship');

        $this->inside('era', function () use ($sale): void {
            $notices = app(PaymentNotices::class);
            $this->assertSame('external_refund_held', $notices->refundUpdated('stripe', 'evt_ext1', 'refund.created', new RefundResult('re_dash1', 'succeeded', null, 1500, 'pi_paid')));

            $refund = Refund::query()->where('transaction_id', $sale)->sole();
            $this->assertSame(['external', 'held', 'external_refund', 1500, 're_dash1', 'pi_paid'], [$refund->purpose, $refund->status, $refund->failure_reason, $refund->amount, $refund->provider_ref, $refund->payment_ref]);
            $this->assertSame(PaymentAttempt::query()->where('transaction_id', $sale)->where('status', 'paid')->sole()->id, $refund->attempt_id);
            // 台帳には書かない（人が確かめてから）
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count());
            $this->assertEquals(['refund' => $refund->id, 'amount' => 1500, 'reported_status' => 'succeeded'], DomainEvent::query()->where('type', 'payment.external_refund_held')->sole()->payload);
            $this->assertSame([$refund->id], ShopHome::refundIssues()->pluck('id')->all());

            // 同じ返金の続きの知らせは止めたまま。同じ知らせの二度目は何もしない
            $this->assertSame('refund_unchanged', $notices->refundUpdated('stripe', 'evt_ext2', 'refund.updated', new RefundResult('re_dash1', 'succeeded', null, 1500, 'pi_paid')));
            $this->assertSame('seen', $notices->refundUpdated('stripe', 'evt_ext1', 'refund.created', new RefundResult('re_dash1', 'succeeded', null, 1500, 'pi_paid')));
            $this->assertSame(1, Refund::query()->count());
            $this->assertSame('held', Refund::query()->sole()->status);
        });

        // 店のホームの確かめが要る返金に、理由つきで出る。発送などの操作は止めない（取消の返金の held とは違う）
        $this->send('GET', '/api/tenants/era/overview')
            ->assertJsonPath('refund_issues.0.transaction_id', $sale)
            ->assertJsonPath('refund_issues.0.failure_reason', 'external_refund');
        $this->send('GET', "/api/tenants/era/transactions/{$sale}")
            ->assertJsonPath('transaction.refunds.0.purpose', 'external')
            ->assertJsonPath('transaction.actions.ship', $shipBefore);
    }

    public function test_a_dashboard_refund_of_a_stray_payment_is_tied_to_that_payment(): void
    {
        $this->inside('era', function (): void {
            [$sale, $first] = $this->checkout();
            PaymentAttempt::query()->where('provider_ref', $first)->update(['expires_at' => now()->addMinute()]);
            $second = app(PaymentService::class)->startCheckout($sale, 'x', 'y')->ref;
            $payments = app(PaymentNotices::class);
            $payments->confirmPaid('stripe', 'evt_p1', 'checkout.session.completed', $first, $sale->id, 4400, 'jpy', 'pi_first');
            $payments->confirmPaid('stripe', 'evt_p2', 'checkout.session.completed', $second, $sale->id, 4400, 'jpy', 'pi_second');
            $stray = StrayPayment::query()->where('transaction_id', $sale->id)->sole();

            $this->assertSame('external_refund_held', $payments->refundUpdated('stripe', 'evt_ext3', 'refund.created', new RefundResult('re_dash2', 'succeeded', null, 4400, 'pi_second')));
            $refund = Refund::query()->sole();
            $this->assertSame([$sale->id, $stray->attempt_id, 'external'], [$refund->transaction_id, $refund->attempt_id, $refund->purpose]);
            // 返金が要る支払いは自動で「返金済み」にしない（人が確かめる）
            $this->assertSame('pending', $stray->fresh()?->status);
        });
    }

    public function test_a_refund_of_a_payment_this_shop_does_not_know_is_only_received(): void
    {
        $this->paid('era', 'pi_era_only');

        // ほかの店の支払い・支払いの ID が無い・額が無い: 記録しない
        $this->inside('erb', function (): void {
            $notices = app(PaymentNotices::class);
            $this->assertSame('unknown_refund', $notices->refundUpdated('stripe', 'evt_u1', 'refund.created', new RefundResult('re_x1', 'succeeded', null, 1000, 'pi_era_only')));
            $this->assertSame(0, Refund::query()->count());
        });
        $this->inside('era', function (): void {
            $notices = app(PaymentNotices::class);
            $this->assertSame('unknown_refund', $notices->refundUpdated('stripe', 'evt_u2', 'refund.created', new RefundResult('re_x2', 'succeeded', null, 1000, null)));
            $this->assertSame('unknown_refund', $notices->refundUpdated('stripe', 'evt_u3', 'refund.created', new RefundResult('re_x3', 'succeeded', null, null, 'pi_era_only')));
            $this->assertSame('unknown_refund', $notices->refundUpdated('stripe', 'evt_u4', 'refund.created', new RefundResult('re_x4', 'succeeded', null, 1000, 'pi_nobody')));
            $this->assertSame(0, Refund::query()->count());
        });
    }

    public function test_our_own_refund_notified_before_its_answer_is_not_taken_for_a_dashboard_refund(): void
    {
        $sale = $this->paid('era', 'pi_ours');

        $this->inside('era', function () use ($sale): void {
            // 取消の返金を頼んだ直後（答えはまだ。Stripe の返金の ID も無い）に、その返金の知らせが先に届く
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale)->where('status', 'paid')->sole();
            $ours = Refund::create([
                'transaction_id' => $sale, 'attempt_id' => $attempt->id, 'purpose' => 'cancel', 'amount' => 4400, 'currency' => 'jpy',
                'payment_ref' => 'pi_ours', 'idempotency_key' => 'refund-cancel-test', 'status' => 'requested',
            ]);

            $this->assertSame('refund_succeeded', app(PaymentNotices::class)->refundUpdated('stripe', 'evt_o1', 'refund.created', new RefundResult('re_ours', 'succeeded', null, 4400, 'pi_ours')));
            $this->assertSame(['re_ours', 'succeeded'], [$ours->fresh()?->provider_ref, $ours->fresh()?->status]);
            $this->assertSame(0, Refund::query()->where('purpose', 'external')->count());
        });
    }

    public function test_the_database_keeps_an_external_refund_held(): void
    {
        $this->paid('era', 'pi_db');

        $this->inside('era', function (): void {
            app(PaymentNotices::class)->refundUpdated('stripe', 'evt_d1', 'refund.created', new RefundResult('re_db', 'succeeded', null, 500, 'pi_db'));
            $refund = Refund::query()->sole();

            // 外でした返金を、人の確かめなしに成功へ進めて台帳の返金の対象にすることはできない
            $this->expectException(QueryException::class);
            Refund::query()->whereKey($refund->id)->update(['status' => 'succeeded', 'failure_reason' => null, 'settled_at' => now()]);
        });
    }

    public function test_the_stripe_refund_carries_the_payment_it_refunds(): void
    {
        $this->assertSame('pi_abc', StripeCheckout::normalizeRefund(['id' => 're_1', 'status' => 'succeeded', 'amount' => 100, 'payment_intent' => 'pi_abc'])['payment_ref']);
        $this->assertSame('pi_abc', StripeCheckout::normalizeRefund(['id' => 're_1', 'status' => 'succeeded', 'amount' => 100, 'payment_intent' => ['id' => 'pi_abc']])['payment_ref']);
        $this->assertNull(StripeCheckout::normalizeRefund(['id' => 're_1', 'status' => 'succeeded', 'payment_intent' => 'ch_abc'])['payment_ref']);
        $this->assertNull(StripeCheckout::normalizeRefund(['id' => 're_1', 'status' => 'succeeded', 'payment_intent' => "pi_a\nb"])['payment_ref']);
        $this->assertNull(StripeCheckout::normalizeRefund(['id' => 're_1', 'status' => 'succeeded'])['payment_ref']);
    }

    /**
     * 払われた注文（支払いの ID つき）
     */
    private function paid(string $shop, string $paymentRef): string
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
