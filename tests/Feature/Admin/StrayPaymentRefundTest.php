<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
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
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 返金が要る支払い。二重に払われた・取り消した注文に届いた支払いを残し、概要で知らせ、Stripe で 1 回だけ返金する。
 * このお金は台帳に入れていないので、返金も台帳に入れない。お金が動くので再確認つき。
 */
final class StrayPaymentRefundTest extends TestCase
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
        $this->tenant('spa');
        $this->tenant('spb');
        foreach (['spa', 'spb'] as $shop) {
            $this->inside($shop, fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => implode('_', ['whsec', self::BODY.'1234'])]));
        }
        [$this->operator, $this->authenticator] = $this->enrolledOperator();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_double_payment_is_listed_and_refunded_once_outside_the_ledger(): void
    {
        [$sale, $stray] = $this->doublePaid('spa');

        // 概要と注文の画面で知らせる
        $this->send('GET', '/api/tenants/spa/overview')->assertJsonPath('stray_payments.0.transaction_id', $sale)->assertJsonPath('stray_payments.0.amount', 4400);
        $this->send('GET', "/api/tenants/spa/transactions/{$sale}")
            ->assertJsonPath('transaction.stray_payments.0.reason', 'duplicate')
            ->assertJsonPath('transaction.stray_payments.0.refundable', true);

        $url = "/api/tenants/spa/transactions/{$sale}/stray-payments/{$stray}/refund";
        $this->send('POST', $url)->assertStatus(423);
        $this->assertSame([], $this->stripe->refunds);

        $this->reconfirm($this->operator, $this->authenticator);
        $this->send('POST', $url)->assertOk()->assertJsonPath('transaction.stray_payments.0.status', 'refunded');
        $this->assertSame([['pi_second', 4400]], array_map(static fn (array $r): array => [$r['payment_intent'], $r['amount']], $this->stripe->refunds));
        $this->assertSame("refund-stray-{$stray}", $this->stripe->lastHeader('Idempotency-Key'));

        $this->inside('spa', function () use ($sale, $stray): void {
            $row = StrayPayment::query()->findOrFail($stray);
            $this->assertSame([$this->stripe->refunds[0]['id'], 'refunded'], [$row->refund_ref, $row->status]);
            // 注文の代金は払われたまま、台帳に返金は入れない
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($sale)->phase);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count());
            $this->assertEquals(['stray' => $stray, 'reason' => 'duplicate', 'amount' => 4400], DomainEvent::query()->where('type', 'payment.stray_refunded')->sole()->payload);
        });

        // 二度目は断る。概要の知らせも消える
        $this->send('POST', $url)->assertStatus(409)->assertJsonPath('user_message', 'この支払いはすでに返金済みです。');
        $this->assertCount(1, $this->stripe->refunds);
        $this->send('GET', '/api/tenants/spa/overview')->assertJsonPath('stray_payments', []);
    }

    public function test_a_pending_stray_refund_is_not_marked_refunded(): void
    {
        [$sale, $stray] = $this->doublePaid('spa');
        $this->reconfirm($this->operator, $this->authenticator);
        $this->stripe->refundStatus = 'pending';
        $url = "/api/tenants/spa/transactions/{$sale}/stray-payments/{$stray}/refund";

        // Stripe で保留: 返金済みにしない（ADR 0027）。二度目は「手続きの途中」で断り、新しく頼まない
        $this->send('POST', $url)->assertOk()->assertJsonPath('transaction.stray_payments.0.status', 'pending');
        $this->send('POST', $url)->assertStatus(409)->assertJsonPath('user_message', 'この支払いの返金は、Stripe で手続き中です。');
        $this->assertCount(1, $this->stripe->refunds);

        $this->inside('spa', function () use ($stray): void {
            $this->assertSame('pending', StrayPayment::query()->findOrFail($stray)->status);
            $this->assertSame('pending', Refund::query()->where('stray_payment_id', $stray)->sole()->status);
            $this->assertSame(0, DomainEvent::query()->where('type', 'payment.stray_refunded')->count());

            // 後で Stripe が確定を知らせると、返金済みになる（同じ知らせの二度目は何もしない）
            $notices = app(PaymentNotices::class);
            $ref = (string) $this->stripe->refunds[0]['id'];
            $this->assertSame('refund_succeeded', $notices->refundUpdated('stripe', 'evt_rs1', 'refund.updated', new RefundResult($ref, 'succeeded')));
            $this->assertSame('seen', $notices->refundUpdated('stripe', 'evt_rs1', 'refund.updated', new RefundResult($ref, 'succeeded')));
            $this->assertSame(['refunded', $ref], [StrayPayment::query()->findOrFail($stray)->status, StrayPayment::query()->findOrFail($stray)->refund_ref]);
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.stray_refunded')->count());
        });
    }

    public function test_a_declined_stray_refund_can_be_retried_with_a_new_key(): void
    {
        [$sale, $stray] = $this->doublePaid('spa');
        $this->reconfirm($this->operator, $this->authenticator);
        $url = "/api/tenants/spa/transactions/{$sale}/stray-payments/{$stray}/refund";

        // Stripe がはっきり断った（4xx）: 返金が要るまま。理由を記録する
        $this->stripe->respond = static fn (): array => ['{"error":{"type":"invalid_request_error","code":"charge_disputed","message":"x"}}', 400, []];
        $this->send('POST', $url)->assertStatus(409)->assertJsonPath('user_message', fn (string $m): bool => str_contains($m, 'charge_disputed'));
        $this->stripe->respond = null;
        $this->inside('spa', function () use ($stray): void {
            $this->assertSame('pending', StrayPayment::query()->findOrFail($stray)->status);
            $this->assertSame(['failed', 'charge_disputed'], [Refund::query()->where('stray_payment_id', $stray)->sole()->status, Refund::query()->where('stray_payment_id', $stray)->sole()->failure_reason]);
        });

        // やり直しは新しい冪等キー（-2）で、1 回だけ返金する
        $this->send('POST', $url)->assertOk()->assertJsonPath('transaction.stray_payments.0.status', 'refunded');
        $this->assertSame("refund-stray-{$stray}-2", $this->stripe->lastHeader('Idempotency-Key'));
        $this->assertCount(1, $this->stripe->refunds);
    }

    public function test_refusals_and_boundaries(): void
    {
        [$sale, $stray] = $this->doublePaid('spa');
        $this->reconfirm($this->operator, $this->authenticator);

        // Stripe が断った: 返金が要るまま
        $this->stripe->respond = static fn (): array => ['{"error":{"type":"api_error","message":"down"}}', 500, []];
        $this->send('POST', "/api/tenants/spa/transactions/{$sale}/stray-payments/{$stray}/refund")->assertStatus(503);
        $this->stripe->respond = null;
        $this->inside('spa', fn () => $this->assertSame('pending', StrayPayment::query()->findOrFail($stray)->status));

        // 別の注文・別の店の URL では見つからない
        [$otherSale] = $this->doublePaid('spa');
        $this->send('POST', "/api/tenants/spa/transactions/{$otherSale}/stray-payments/{$stray}/refund")->assertNotFound();
        $this->send('POST', "/api/tenants/spb/transactions/{$sale}/stray-payments/{$stray}/refund")->assertNotFound();

        // 取り消した注文に届いた支払い（Stripe の支払いの ID が無い）: ここからは返金できない
        $late = $this->inside('spa', function (): string {
            [$sale, $ref] = $this->checkout();
            app(TransactionService::class)->transition($sale, Phase::Cancel);
            $this->assertSame('late', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_late', 'checkout.session.completed', $ref, $sale->id, 4400, 'jpy', null));

            return $sale->id;
        });
        $this->send('GET', "/api/tenants/spa/transactions/{$late}")
            ->assertJsonPath('transaction.stray_payments.0.reason', 'late')
            ->assertJsonPath('transaction.stray_payments.0.refundable', false);
        $lateStray = $this->inside('spa', fn (): string => StrayPayment::query()->where('transaction_id', $late)->sole()->id);
        $this->send('POST', "/api/tenants/spa/transactions/{$late}/stray-payments/{$lateStray}/refund")->assertStatus(503)
            ->assertJsonPath('user_message', fn (string $m): bool => str_contains($m, 'Stripe のダッシュボードで返金'));
        $this->assertCount(0, $this->stripe->refunds);

        // 同じ通知がもう一度来ても、返金が要る支払いは増えない
        $this->inside('spa', fn () => $this->assertSame(3, StrayPayment::query()->count()));
    }

    /**
     * 2 つの支払いの画面で二重に払われた注文
     *
     * @return array{0: string, 1: string} 取引と返金が要る支払い
     */
    private function doublePaid(string $shop): array
    {
        return $this->inside($shop, function (): array {
            [$sale, $first] = $this->checkout();
            PaymentAttempt::query()->where('provider_ref', $first)->update(['expires_at' => now()->addMinute()]);
            $second = app(PaymentService::class)->startCheckout($sale, 'x', 'y')->ref;
            $payments = app(PaymentNotices::class);
            $this->assertSame('paid', $payments->confirmPaid('stripe', "evt_{$first}", 'checkout.session.completed', $first, $sale->id, 4400, 'jpy', 'pi_first'));
            $this->assertSame('duplicate', $payments->confirmPaid('stripe', "evt_{$second}", 'checkout.session.completed', $second, $sale->id, 4400, 'jpy', 'pi_second'));
            // 同じ通知の送り直し
            $payments->confirmPaid('stripe', "evt_{$second}", 'checkout.session.completed', $second, $sale->id, 4400, 'jpy', 'pi_second');

            return [$sale->id, StrayPayment::query()->where('transaction_id', $sale->id)->sole()->id];
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
