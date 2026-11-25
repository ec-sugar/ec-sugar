<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\RefundReconciler;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\WebhookEvent;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 返金の確定（ADR 0027）。保留の返金は、Stripe の返金の知らせ（Webhook）か、定期の確かめ直しで確定する。
 * 成功したら 1 回だけ台帳に返金を書く。失敗・成功の後に戻されたものは台帳を勝手に動かさず、概要の「要対応」に出す。
 * 答えが分からないまま残った返金は、定期の確かめ直しが同じ冪等キーで続きから進める
 */
final class RefundNoticeTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BODY = 'FakeForTestsOnly000';

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->stripe = FakeStripe::install();
        $this->tenant('rna');
        $this->inside('rna', fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => $this->webhookSecret()]));
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_pending_refund_is_booked_once_when_stripe_confirms_it(): void
    {
        $sale = $this->cancelledWithPendingRefund();
        $ref = $this->stripe->refunds[0]['id'];

        $this->deliver($this->refundEvent('evt_r1', $ref, 'succeeded'))->assertOk();
        $this->deliver($this->refundEvent('evt_r1', $ref, 'succeeded'))->assertOk();
        // 別の知らせ（charge.refund.updated）でも、もう確定した返金は二重に書かない
        $this->deliver($this->refundEvent('evt_r2', $ref, 'succeeded', type: 'charge.refund.updated'))->assertOk();

        $this->inside('rna', function () use ($sale): void {
            $refund = Refund::query()->where('transaction_id', $sale)->sole();
            $this->assertSame('succeeded', $refund->status);
            $this->assertNotNull($refund->settled_at);
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count());
            // 台帳は差し引き 0（請求の取り消し・受領・返金）
            $this->assertSame([], array_filter(app(LedgerService::class)->balances(Transaction::query()->findOrFail($sale))));
            $this->assertSame(['refund_succeeded', 'refund_unchanged'], WebhookEvent::query()->whereIn('event_id', ['evt_r1', 'evt_r2'])->orderBy('event_id')->pluck('outcome')->all());
        });
    }

    public function test_a_refund_that_fails_or_is_reversed_is_kept_for_a_person(): void
    {
        [$operator] = $this->enrolledOperator();

        // 保留の後に失敗: 台帳には書かない（お金は預かったまま）。注文は取消済みなので「要対応」
        $failedSale = $this->cancelledWithPendingRefund();
        $this->deliver($this->refundEvent('evt_f1', $this->stripe->refunds[0]['id'], 'failed', 'insufficient_funds', type: 'refund.failed'))->assertOk();

        // 成功の後に戻された: 台帳の返金は自動で戻さず、「要対応」。知らせの額が違っても戻されたことは捨てない
        $this->stripe->refundStatus = 'succeeded';
        $reversedSale = $this->paidOrder();
        $this->inside('rna', fn () => app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($reversedSale), $operator->actor()));
        $this->deliver($this->refundEvent('evt_f2', $this->stripe->refunds[1]['id'], 'failed', 'lost_or_stolen_card', type: 'refund.failed', amount: 4000))->assertOk();

        $this->inside('rna', function () use ($failedSale, $reversedSale): void {
            $failed = Refund::query()->where('transaction_id', $failedSale)->sole();
            $this->assertSame(['failed', 'insufficient_funds'], [$failed->status, $failed->failure_reason]);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $failedSale)->where('type', JournalType::Refund)->count());

            $reversed = Refund::query()->where('transaction_id', $reversedSale)->sole();
            $this->assertSame(['reversed', 'lost_or_stolen_card'], [$reversed->status, $reversed->failure_reason]);
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $reversedSale)->where('type', JournalType::Refund)->count());
        });

        // 概要に「要対応の返金」として出る。詳細には返金の記録
        $issues = $this->send('GET', '/api/tenants/rna/overview')->assertOk()->json('refund_issues');
        $this->assertEqualsCanonicalizing([[$failedSale, 'failed'], [$reversedSale, 'reversed']], array_map(static fn (array $i): array => [$i['transaction_id'], $i['status']], $issues));
        $this->send('GET', "/api/tenants/rna/transactions/{$failedSale}")->assertJsonPath('transaction.refunds.0.status', 'failed')->assertJsonPath('transaction.refunds.0.failure_reason', 'insufficient_funds');

        // 知らない返金（ダッシュボードで直接した返金など）は受け取るだけ。形の違う知らせは断る
        $this->deliver($this->refundEvent('evt_u1', 're_unknown_1', 'succeeded'))->assertOk();
        $this->inside('rna', fn () => $this->assertSame('unknown_refund', WebhookEvent::query()->where('event_id', 'evt_u1')->sole()->outcome));
        $this->deliver($this->refundEvent('evt_u2', 'not-a-refund', 'succeeded'))->assertStatus(400);
    }

    public function test_the_reconciler_resumes_unanswered_refunds_and_asks_about_pending_ones(): void
    {
        // 答えが分からなかった返金（5xx）: すぐには頼み直さず、10 分を過ぎたら同じ冪等キーで続きから最後まで
        $unanswered = $this->paidOrder();
        $this->stripe->respond = static fn (): array => ['{"error":{"type":"api_error","message":"down"}}', 500, []];
        $this->inside('rna', function () use ($unanswered): void {
            try {
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($unanswered), 'operator:test');
                $this->fail('応答が分からないのに取り消した');
            } catch (PaymentUnavailableException) {
            }
        });
        $this->stripe->respond = null;

        $this->artisan('storefront:reconcile-refunds')->assertSuccessful();
        $this->inside('rna', fn () => $this->assertSame('requested', Refund::query()->where('transaction_id', $unanswered)->sole()->status));

        $this->travel(RefundReconciler::REQUESTED_AFTER_MINUTES + 1)->minutes();
        $this->artisan('storefront:reconcile-refunds')->expectsOutputToContain('再依頼 1 件')->assertSuccessful();
        $this->assertCount(1, $this->stripe->refunds);
        $this->assertSame("refund-cancel-{$unanswered}", $this->stripe->lastHeader('Idempotency-Key'));
        $this->inside('rna', function () use ($unanswered): void {
            $this->assertSame('succeeded', Refund::query()->where('transaction_id', $unanswered)->sole()->status);
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($unanswered)->status);
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $unanswered)->where('type', JournalType::Refund)->count());
            // 頼み直したのは定期の処理（記録に誰の処理かが残る）
            $this->assertSame(RefundReconciler::ACTOR, DomainEvent::query()->where('type', 'payment.refund_recorded')->latest('sequence')->firstOrFail()->actor);
        });

        // 保留のまま（Webhook の取りこぼし）: 30 分を過ぎたら Stripe に問い合わせて確定させる
        $pending = $this->cancelledWithPendingRefund();
        $this->stripe->refunds[1]['status'] = 'succeeded';
        $this->artisan('storefront:reconcile-refunds')->assertSuccessful();
        $this->inside('rna', fn () => $this->assertSame('pending', Refund::query()->where('transaction_id', $pending)->sole()->status));

        $this->travel(RefundReconciler::PENDING_AFTER_MINUTES + 1)->minutes();
        $this->artisan('storefront:reconcile-refunds')->expectsOutputToContain('確定 1 件')->assertSuccessful();
        $this->inside('rna', function () use ($pending): void {
            $this->assertSame('succeeded', Refund::query()->where('transaction_id', $pending)->sole()->status);
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $pending)->where('type', JournalType::Refund)->count());
        });
    }

    public function test_refunds_that_stay_pending_do_not_block_the_ones_behind_them(): void
    {
        // 1 回に見る数より多く、保留のままの返金が先頭に溜まっても、後ろの返金に順番が来る（別の窓のレビュー 4 と同じ形）
        config(['payment.refund_reconcile_batch' => 2]);
        $stuck = [$this->cancelledWithPendingRefund(), $this->cancelledWithPendingRefund()];
        $this->travel(1)->minutes();
        $behind = $this->cancelledWithPendingRefund();
        $this->stripe->refunds[2]['status'] = 'succeeded';
        $this->travel(RefundReconciler::PENDING_AFTER_MINUTES + 1)->minutes();

        $this->artisan('storefront:reconcile-refunds')->expectsOutputToContain('確定 0 件')->assertSuccessful();
        $this->artisan('storefront:reconcile-refunds')->expectsOutputToContain('確定 1 件')->assertSuccessful();

        $this->inside('rna', function () use ($stuck, $behind): void {
            $this->assertSame('succeeded', Refund::query()->where('transaction_id', $behind)->sole()->status);
            foreach ($stuck as $sale) {
                $this->assertSame('pending', Refund::query()->where('transaction_id', $sale)->sole()->status);
            }
        });
    }

    public function test_a_refund_that_fails_does_not_block_the_ones_behind_it(): void
    {
        // 1 件が例外で落ちても、印を付けて後ろへ回し、ほかの返金に順番が来る
        config(['payment.refund_reconcile_batch' => 1]);
        $broken = $this->cancelledWithPendingRefund();
        $this->travel(1)->minutes();
        $behind = $this->cancelledWithPendingRefund();
        $this->stripe->refunds[0]['status'] = 'succeeded';
        $this->stripe->refunds[1]['status'] = 'succeeded';
        $this->travel(RefundReconciler::PENDING_AFTER_MINUTES + 1)->minutes();
        $brokenRefund = $this->inside('rna', fn (): string => Refund::query()->where('transaction_id', $broken)->sole()->id);
        DB::listen(static function (QueryExecuted $query) use ($brokenRefund): void {
            if (str_contains($query->sql, 'for update') && str_contains($query->sql, '"refunds"') && in_array($brokenRefund, $query->bindings, true)) {
                throw new RuntimeException('わざと落とす');
            }
        });

        $this->artisan('storefront:reconcile-refunds')->expectsOutputToContain('失敗 1 件')->assertSuccessful();
        $this->artisan('storefront:reconcile-refunds')->expectsOutputToContain('確定 1 件')->assertSuccessful();

        $this->inside('rna', function () use ($broken, $behind): void {
            $this->assertSame('succeeded', Refund::query()->where('transaction_id', $behind)->sole()->status);
            $this->assertSame('pending', Refund::query()->where('transaction_id', $broken)->sole()->status);
        });
    }

    public function test_a_refund_of_a_different_amount_is_held_for_a_person_on_every_path(): void
    {
        $this->enrolledOperator();

        // ① 頼んだ直後の答えの額が違う（Stripe が 4,000 円の返金を返した）: 取り消さず、台帳にも書かず、止めて人が見る
        $sale = $this->paidOrder();
        $this->stripe->respond = static fn (array $params): array => [(string) json_encode(['id' => 're_short', 'object' => 'refund', 'amount' => 4000, 'status' => 'succeeded']), 200, []];
        $this->inside('rna', function () use ($sale): void {
            try {
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:test');
                $this->fail('額の違う返金で取り消した');
            } catch (PaymentUnavailableException $e) {
                $this->assertSame(PaymentRefunds::HELD, $e->getMessage());
            }

            $refund = Refund::query()->where('transaction_id', $sale)->sole();
            $this->assertSame(['held', 'amount_mismatch', 're_short', null], [$refund->status, $refund->failure_reason, $refund->provider_ref, $refund->settled_at]);
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count());
        });
        $this->stripe->respond = null;
        $calls = count($this->stripe->requests);

        $this->inside('rna', function () use ($sale): void {
            $orders = app(SaleOrderService::class);
            // 発送・完了・取消のやり直しは、どれも断る（お金を返したうえに品物も送らない。新しい返金も頼まない）
            foreach ([
                'ship' => fn () => $orders->ship(Transaction::query()->findOrFail($sale), Transaction::query()->findOrFail($sale)->fulfillments()->value('id') ?? 'x', null, null, 'operator:test'),
                'complete' => fn () => $orders->complete(Transaction::query()->findOrFail($sale), 'operator:test'),
                'cancel' => fn () => $orders->cancel(Transaction::query()->findOrFail($sale), 'operator:test'),
            ] as $what => $try) {
                try {
                    $try();
                    $this->fail("止めている返金があるのに {$what} できた");
                } catch (PaymentUnavailableException|TransactionException $e) {
                    // 完了は発送の前なので先に断られる。発送と取消は止めた理由を伝える
                    if ($what !== 'complete') {
                        $this->assertSame(PaymentRefunds::HELD, $e->getMessage(), $what);
                    }
                }
            }
            $this->assertSame(1, Refund::query()->where('transaction_id', $sale)->count());
        });
        $this->assertCount($calls, $this->stripe->requests);

        // 画面: 注文のボタンは出さず、概要の「要対応の返金」にすぐ出る（1 時間を待たない）
        foreach (['ship', 'complete', 'cancel'] as $action) {
            $this->send('GET', "/api/tenants/rna/transactions/{$sale}")->assertOk()->assertJsonPath("transaction.actions.{$action}", false);
        }
        $issues = $this->send('GET', '/api/tenants/rna/overview')->assertOk()->json('refund_issues');
        $this->assertSame([[$sale, 'held', 'amount_mismatch']], array_map(static fn (array $i): array => [$i['transaction_id'], $i['status'], $i['failure_reason']], $issues));

        // 定期の確かめ直しも、正しい額の知らせも、止めた返金を勝手に進めない
        $this->travel(RefundReconciler::PENDING_AFTER_MINUTES + 1)->minutes();
        $this->artisan('storefront:reconcile-refunds')->expectsOutputToContain('額が違って止めている 1 件')->assertSuccessful();
        $this->deliver($this->refundEvent('evt_h1', 're_short', 'succeeded'))->assertOk();
        $this->inside('rna', function () use ($sale): void {
            $this->assertSame('held', Refund::query()->where('transaction_id', $sale)->sole()->status);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count());
            $this->assertSame('amount_mismatch', DomainEvent::query()->where('type', 'payment.refund_recorded')->latest('sequence')->firstOrFail()->payload['reason']);
        });
        $this->assertCount($calls, $this->stripe->requests);

        // ② 保留の返金の知らせの額が違う: 保留から止める
        $pending = $this->cancelledWithPendingRefund();
        $ref = $this->stripe->refunds[0]['id'];
        $this->deliver($this->refundEvent('evt_h2', $ref, 'succeeded', amount: 3000))->assertOk();
        $this->inside('rna', function () use ($pending): void {
            $this->assertSame('held', Refund::query()->where('transaction_id', $pending)->sole()->status);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $pending)->where('type', JournalType::Refund)->count());
        });

        // DB も、止めた返金に理由が無い形と、止めた返金を終わった扱いにする形を断る
        $this->inside('rna', function () use ($pending): void {
            foreach ([['failure_reason' => null], ['settled_at' => now()]] as $broken) {
                try {
                    DB::transaction(static fn () => Refund::query()->where('transaction_id', $pending)->update($broken));
                    $this->fail('DB が止めた返金の崩れた形を受け付けた');
                } catch (QueryException) {
                }
            }

            // 止めた返金がある間は、移行を戻さない（戻すと額の違いの印が消え、確かめ直しが勝手に進める）
            $migration = require base_path('database/migrations/tenant/2026_10_29_000300_add_held_to_refunds.php');
            try {
                $migration->down();
                $this->fail('止めた返金があるのに移行を戻した');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('止めている返金がある', $e->getMessage());
            }
            $this->assertSame(2, Refund::query()->where('status', 'held')->count());
        });
    }

    /**
     * 払われた注文（Stripe の支払いの画面を作り、支払いの知らせを受けた）
     */
    private function paidOrder(): string
    {
        return $this->inside('rna', function (): string {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 3);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子']);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test');
            $ref = app(PaymentService::class)->startCheckout($sale, 'x', 'y')->ref;
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_paid_'.$sale->id, 'checkout.session.completed', $ref, $sale->id, 4400, 'jpy', 'pi_'.$sale->id));

            return $sale->id;
        });
    }

    /**
     * 取り消して、Stripe の返金が保留の注文
     */
    private function cancelledWithPendingRefund(): string
    {
        $sale = $this->paidOrder();
        $this->stripe->refundStatus = 'pending';
        $this->inside('rna', function () use ($sale): void {
            app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:test');
            $this->assertSame('pending', Refund::query()->where('transaction_id', $sale)->sole()->status);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Refund)->count());
        });
        $this->stripe->refundStatus = 'succeeded';

        return $sale;
    }

    /**
     * Stripe の返金の知らせの形（必要なところだけ）
     *
     * @return array<string, mixed>
     */
    private function refundEvent(string $id, string $refundRef, string $status, ?string $reason = null, string $type = 'refund.updated', int $amount = 4400): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'livemode' => false,
            'created' => time(),
            'data' => ['object' => ['id' => $refundRef, 'object' => 'refund', 'amount' => $amount, 'status' => $status, 'failure_reason' => $reason]],
        ];
    }

    /**
     * 店の Webhook の受け口へ、Stripe と同じ署名で送る
     *
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    private function deliver(array $event): TestResponse
    {
        $payload = (string) json_encode($event);
        $time = time();
        $signature = "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", $this->webhookSecret());
        $response = $this->call('POST', 'http://rna.localhost/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
        tenancy()->end();

        return $response;
    }

    private function webhookSecret(): string
    {
        return implode('_', ['whsec', self::BODY.'rna']);
    }
}
