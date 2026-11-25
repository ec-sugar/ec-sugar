<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\ShopHome;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Jobs\RefundShippingDifference;
use App\Core\Order\Models\OrderShipping;
use App\Core\Order\Services\RefundReconciler;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Order\Services\ShippingDifferenceRefunds;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShippingMethods;
use App\Core\Shop\Shipping\Regions;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Postal\PostalPrefectures;
use Carbon\CarbonImmutable;
use EcSugar\PluginGift\Models\GiftOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 贈り物の送料（ADR 0055 の 7、ADR 0064 の 6）: 買う時はいちばん高い額で取り、受け取りの都道府県の額（注文の時の表）との差額を返金する。
 * 差額 0 は何もしない。返金は 1 回。断られたら止めて店のやることに出し、店の人が頼み直す（お金の段階・直前の確かめ）。取消の返金は差額の返金を引いた額。
 * 返金の記録を作る前に止まった差額（キューの仕事が届かなかった・支払いの準備で止まった）は、返金の見回りが 1 回だけ拾い直す
 */
final class GiftShippingTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const array BUYER = ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''];

    private const array SAPPORO = ['name' => '鈴木 一郎', 'phone' => '0111234567', 'postal' => '0600001', 'prefecture' => '北海道', 'city' => '札幌市中央区', 'line1' => '北一条西 2-1', 'line2' => ''];

    private const array NAHA = ['name' => '比嘉 次郎', 'phone' => '0981234567', 'postal' => '9000001', 'prefecture' => '沖縄県', 'city' => '那覇市', 'line1' => '港町 1-1', 'line2' => ''];

    private FakeStripe $stripe;

    private ?string $cart = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->stripe = FakeStripe::install();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Asia/Tokyo'));
        $this->tenant('gsh', '贈り物の店');
        $this->inside('gsh', function (): void {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            // 通常便: 本州 ¥800・北海道 ¥1,500・沖縄 ¥2,000（贈り物はいちばん高い ¥2,000 で取る）
            $table = app(ShippingMethods::class);
            $table->replace(['methods' => [['id' => $table->all()[0]['id'], 'name' => '通常便', 'free_over' => null, 'rates' => [...Regions::flat(800), '北海道' => 1500, '沖縄県' => 2000]]]], 'test');
            app(PluginStates::class)->set('gift', true, 'test');
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_the_difference_is_refunded_once_from_the_rates_at_the_time_of_the_order(): void
    {
        $order = $this->paidGift();
        $this->inside('gsh', function () use ($order): void {
            $this->assertSame(7000, (int) Transaction::query()->findOrFail($order)->total_amount);
            $row = OrderShipping::query()->where('transaction_id', $order)->sole();
            $this->assertSame(['highest', 2000, 1500], [$row->basis, $row->fee, $row->rates['北海道'] ?? null]);
            // 受け取りの前に表を変えても、差額は注文の時の表で決める
            $table = app(ShippingMethods::class);
            $table->setFlat(100, null, 'test');
        });

        $this->claim($order, self::SAPPORO);

        // 差額 ¥500 を 1 回だけ返金し、台帳にも返金を書く
        $this->assertCount(1, $this->stripe->refunds);
        $this->assertSame(500, $this->stripe->refunds[0]['amount']);
        $this->inside('gsh', function () use ($order): void {
            $refund = Refund::query()->where('transaction_id', $order)->sole();
            $this->assertSame(['shipping', 'succeeded', 500, "refund-shipping-{$order}"], [$refund->purpose, $refund->status, $refund->amount, $refund->idempotency_key]);
            $this->assertSame(500, (int) LedgerJournal::query()->where('transaction_id', $order)->where('type', JournalType::Refund)->sole()->postings()->where('amount', '>', 0)->sum('amount'));
            $this->assertSame(1500, OrderShipping::query()->where('transaction_id', $order)->value('settled_fee'));
            // 記録に都道府県は残さない
            $settled = DomainEvent::query()->where('type', 'order.shipping_settled')->sole();
            $this->assertSame(['difference' => 500], $settled->payload);

            // 送り直しても返金は増えない（同じ記録・同じ冪等キー）
            $this->assertSame('succeeded', app(ShippingDifferenceRefunds::class)->refund($order, 'test'));
        });
        $this->assertCount(1, $this->stripe->refunds);

        // 店が取り消すと、残り（¥7,000 − ¥500）を返す
        $this->inside('gsh', function () use ($order): void {
            $result = app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($order), 'test');
            $this->assertSame(6500, $result['refunded']);
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($order)->status);
        });
        $this->assertSame([500, 6500], array_column($this->stripe->refunds, 'amount'));
    }

    public function test_the_receivers_prefecture_must_match_the_postal_code(): void
    {
        $order = $this->paidGift();
        $token = $this->inside('gsh', fn (): string => (string) GiftOrder::query()->where('transaction_id', $order)->sole()->token_sealed);

        // 札幌の郵便番号で沖縄県を選ぶ（差額を多く返させる）: 断り、受け取りも差額も決めない
        $this->shop('POST', '/p/gift/'.$token, ['address' => [...self::SAPPORO, 'prefecture' => '沖縄県']])
            ->assertRedirect()->assertSessionHasErrors(['address.postal' => PostalPrefectures::MISMATCH]);
        $this->inside('gsh', function () use ($order): void {
            $this->assertSame('waiting', GiftOrder::query()->where('transaction_id', $order)->value('status'));
            $this->assertNull(OrderShipping::query()->where('transaction_id', $order)->value('settled_fee'));
        });

        // 合う組み合わせなら受け取れ、差額は郵便番号の県（北海道）で決まる
        $this->claim($order, self::SAPPORO);
        $this->assertSame([500], array_column($this->stripe->refunds, 'amount'));
    }

    public function test_no_difference_means_no_refund(): void
    {
        $order = $this->paidGift();
        $this->claim($order, self::NAHA);

        $this->assertSame([], $this->stripe->refunds);
        $this->inside('gsh', function () use ($order): void {
            $this->assertSame(0, Refund::query()->where('transaction_id', $order)->count());
            $this->assertSame(2000, OrderShipping::query()->where('transaction_id', $order)->value('settled_fee'));
            $this->assertSame(['difference' => 0], DomainEvent::query()->where('type', 'order.shipping_settled')->sole()->payload);
        });
    }

    public function test_a_declined_difference_refund_is_kept_for_the_shop_and_the_cancel_refunds_everything(): void
    {
        $order = $this->paidGift();
        $this->stripe->refundStatus = 'failed';
        $this->claim($order, self::SAPPORO);

        $this->inside('gsh', function () use ($order): void {
            // 受け取りは済む（返金の失敗で受け取りを戻さない）。返金は失敗として残り、店のやることに出る。台帳には書かない
            $this->assertSame('claimed', GiftOrder::query()->where('transaction_id', $order)->value('status'));
            $refund = Refund::query()->where('transaction_id', $order)->sole();
            $this->assertSame(['shipping', 'failed'], [$refund->purpose, $refund->status]);
            $this->assertSame([$refund->id], ShopHome::refundIssues()->pluck('id')->all());
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $order)->where('type', JournalType::Refund)->count());
        });

        // 取り消すと、返せていない差額も含めて全額を返す。やることからも消える（取消の返金が成功した）
        $this->stripe->refundStatus = 'succeeded';
        $this->inside('gsh', function () use ($order): void {
            $this->assertSame(7000, app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($order), 'test')['refunded']);
        });
        $this->assertSame(7000, $this->stripe->refunds[1]['amount']);
        // 取り消した注文の断られた差額は、取消の返金が全部を返したので、やることから消える
        $this->inside('gsh', fn () => $this->assertSame([], ShopHome::refundIssues()->pluck('id')->all()));
    }

    public function test_an_unanswered_difference_refund_blocks_the_cancel_until_it_is_settled(): void
    {
        $order = $this->paidGift();
        $this->stripe->refundStatus = 'pending';
        $this->claim($order, self::SAPPORO);

        $this->inside('gsh', function () use ($order): void {
            $this->assertSame('pending', Refund::query()->where('transaction_id', $order)->sole()->status);

            try {
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($order), 'test');
                $this->fail('差額の返金の応答を待たずに取り消した');
            } catch (PaymentUnavailableException $e) {
                $this->assertStringContainsString('送料の差額の返金', $e->getMessage());
            }

            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($order)->status);
        });
        $this->assertCount(1, $this->stripe->refunds);
    }

    public function test_a_difference_left_without_a_refund_record_is_picked_up_once_by_the_reconciler(): void
    {
        $order = $this->paidGift();
        // キューの仕事が届かなかった（キューに載ったまま流れない）
        Queue::fake();
        $this->claim($order, self::SAPPORO);
        Queue::assertPushed(RefundShippingDifference::class, 1);
        $this->inside('gsh', fn () => $this->assertSame(0, Refund::query()->where('transaction_id', $order)->count()));

        // 受け取りの直後はキューの仕事に任せる（見回りは拾わない）
        $this->travel(5)->minutes();
        Artisan::call('storefront:reconcile-refunds');
        $this->assertSame([], $this->stripe->refunds);

        // 間を置いても記録が無ければ、見回りが差額を 1 回だけ返す
        $this->travel(ShippingDifferenceRefunds::STRANDED_AFTER_MINUTES)->minutes();
        Artisan::call('storefront:reconcile-refunds');
        Artisan::call('storefront:reconcile-refunds');
        $this->assertSame([500], array_column($this->stripe->refunds, 'amount'));

        // 遅れて流れた仕事も、記録が残っているので返金を増やさない
        $this->inside('gsh', function () use ($order): void {
            (new RefundShippingDifference($order, 'system:gift'))->handle(app(ShippingDifferenceRefunds::class));
            $refund = Refund::query()->where('transaction_id', $order)->sole();
            $this->assertSame(['shipping', 'succeeded', 500], [$refund->purpose, $refund->status, $refund->amount]);
            $this->assertSame('system:refunds', DomainEvent::query()->where('type', 'payment.refund_requested')->sole()->actor);
            $this->assertSame(500, (int) LedgerJournal::query()->where('transaction_id', $order)->where('type', JournalType::Refund)->sole()->postings()->where('amount', '>', 0)->sum('amount'));
            $this->assertSame([], ShippingDifferenceRefunds::stranded(CarbonImmutable::now(), 10));
        });
        $this->assertCount(1, $this->stripe->refunds);
    }

    public function test_a_difference_stopped_at_the_payment_preparation_fails_the_job_and_the_reconciler_finishes_it(): void
    {
        $order = $this->paidGift();
        Queue::fake();
        $this->claim($order, self::SAPPORO);

        // 支払いの設定が今は無い（設定を替えている途中など）: 記録を作れない。仕事は落ちてキューにやり直させる
        $this->inside('gsh', function () use ($order): void {
            PaymentGateway::query()->where('provider', 'stripe')->delete();

            try {
                (new RefundShippingDifference($order, 'system:gift'))->handle(app(ShippingDifferenceRefunds::class));
                $this->fail('頼めないのに仕事が済んだことにした');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('送料の差額の返金', $e->getMessage());
            }

            $this->assertSame(0, Refund::query()->where('transaction_id', $order)->count());
        });

        // 見回りも、頼めない間は次の回へ（記録を作らない）
        $this->travel(ShippingDifferenceRefunds::STRANDED_AFTER_MINUTES + 1)->minutes();
        Artisan::call('storefront:reconcile-refunds');
        $this->assertStringContainsString('次の回へ 1 件', Artisan::output());
        $this->assertSame([], $this->stripe->refunds);

        // 設定が戻れば、見回りが 1 回だけ返す
        $this->inside('gsh', fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]));
        Artisan::call('storefront:reconcile-refunds');
        Artisan::call('storefront:reconcile-refunds');
        $this->assertSame([500], array_column($this->stripe->refunds, 'amount'));
        $this->inside('gsh', fn () => $this->assertSame('succeeded', Refund::query()->where('transaction_id', $order)->sole()->status));
    }

    public function test_an_unanswered_difference_refund_fails_the_job_and_concurrent_retries_refund_once(): void
    {
        $order = $this->paidGift();
        Queue::fake();
        $this->claim($order, self::SAPPORO);
        // Stripe の答えが分からない（500）
        $this->stripe->respond = static fn (): array => ['{"error":{"type":"api_error","message":"down"}}', 500, []];

        $this->inside('gsh', function () use ($order): void {
            try {
                (new RefundShippingDifference($order, 'system:gift'))->handle(app(ShippingDifferenceRefunds::class));
                $this->fail('応答の分からない返金で仕事が済んだことにした');
            } catch (RuntimeException $e) {
                // キューがやり直す（PHPUnit の失敗も RuntimeException なので、文で見分ける）
                $this->assertStringContainsString('送料の差額の返金', $e->getMessage());
            }

            $refund = Refund::query()->where('transaction_id', $order)->sole();
            $this->assertSame('requested', $refund->status);
            // 記録がある注文は「止まった差額」に数えない（続きは requested の返金の見回り）
            $this->assertSame([], ShippingDifferenceRefunds::stranded(CarbonImmutable::now()->addHour(), 10));

            // 生きた差額の返金は 1 注文に 1 つ（DB の一意の索引）
            try {
                Refund::create(['transaction_id' => $order, 'attempt_id' => $refund->attempt_id, 'purpose' => 'shipping', 'amount' => 500, 'currency' => 'jpy', 'payment_ref' => $refund->payment_ref, 'idempotency_key' => 'refund-shipping-twice', 'status' => 'requested']);
                $this->fail('差額の返金を 2 つ作れた');
            } catch (QueryException) {
                // 断られた
            }
        });

        // キューのやり直しが Stripe へ頼んでいる間に、見回りも同じ差額を拾う: 同じ記録・同じ冪等キーなので返金は 1 回
        $this->stripe->respond = null;
        $this->stripe->before = function () use ($order): void {
            $this->assertSame('resumed', app(RefundReconciler::class)->recoverShipping($order));
        };
        $this->inside('gsh', function () use ($order): void {
            (new RefundShippingDifference($order, 'system:gift'))->handle(app(ShippingDifferenceRefunds::class));
            $refund = Refund::query()->where('transaction_id', $order)->sole();
            $this->assertSame(['succeeded', "refund-shipping-{$order}"], [$refund->status, $refund->idempotency_key]);
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $order)->where('type', JournalType::Refund)->count());
        });
        $this->assertSame([500], array_column($this->stripe->refunds, 'amount'));
    }

    public function test_money_staff_retry_a_declined_difference_after_reconfirming_even_after_shipping(): void
    {
        $order = $this->paidGift();
        $this->stripe->refundStatus = 'failed';
        $this->claim($order, self::SAPPORO);
        [$declined, $fulfillment] = $this->inside('gsh', fn (): array => [Refund::query()->where('transaction_id', $order)->sole()->id, (string) Fulfillment::query()->where('transaction_id', $order)->sole()->id]);

        // 発送した後でも頼み直せる
        $this->inside('gsh', fn () => app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($order), $fulfillment, null, null, 'test'));
        // 断られた差額は自動では頼み直さない（見回りも拾わない）
        $this->travel(1)->hour();
        Artisan::call('storefront:reconcile-refunds');
        $this->assertCount(1, $this->stripe->refunds);

        $this->configureAdminHost();
        [$owner, $authenticator] = $this->enrolledOperator();
        $url = "/api/tenants/gsh/transactions/{$order}/shipping-refund/retry";
        $this->send('GET', "/api/tenants/gsh/transactions/{$order}")->assertOk()->assertJsonPath('transaction.actions.retry_shipping_refund', true);

        // 直前の確かめが無ければ断る
        $this->send('POST', $url)->assertStatus(423);
        $this->reconfirm($owner, $authenticator);

        // また断られた: 前の記録は「頼み直した」で片付き、新しい記録が失敗として残る（やることには新しい 1 つだけ）
        $this->send('POST', $url)->assertOk()
            ->assertJsonPath('transaction.refunds.0.resolution', 'retried')
            ->assertJsonPath('transaction.refunds.1.status', 'failed')
            ->assertJsonPath('transaction.actions.retry_shipping_refund', true);
        $this->inside('gsh', function () use ($order, $declined): void {
            $this->assertSame(['failed', 'retried'], [Refund::query()->findOrFail($declined)->status, Refund::query()->findOrFail($declined)->resolution]);
            $this->assertSame(Refund::query()->where('transaction_id', $order)->whereNull('resolution')->pluck('id')->all(), ShopHome::refundIssues()->pluck('id')->all());
        });

        // 直した後の頼み直し: 返金が済み、やることから消え、台帳に 1 回だけ返金を書く。冪等キーは断られた数の続きの番号
        $this->stripe->refundStatus = 'succeeded';
        $this->send('POST', $url)->assertOk()
            ->assertJsonPath('transaction.refunds.2.status', 'succeeded')
            ->assertJsonPath('transaction.actions.retry_shipping_refund', false);
        $this->assertSame([500, 500, 500], array_column($this->stripe->refunds, 'amount'));
        $this->inside('gsh', function () use ($order, $owner): void {
            $this->assertSame([], ShopHome::refundIssues()->pluck('id')->all());
            $rows = Refund::query()->where('transaction_id', $order)->orderBy('created_at')->orderBy('id')->get();
            $this->assertSame(['retried', 'retried', null], $rows->pluck('resolution')->all());
            $this->assertSame(["refund-shipping-{$order}", "refund-shipping-{$order}-2", "refund-shipping-{$order}-3"], $rows->pluck('idempotency_key')->all());
            $this->assertSame(500, (int) LedgerJournal::query()->where('transaction_id', $order)->where('type', JournalType::Refund)->sole()->postings()->where('amount', '>', 0)->sum('amount'));
            $this->assertSame(2, DomainEvent::query()->where('type', 'payment.refund_resolved')->where('actor', $owner->actor())->count());
        });

        // 二度目は断る（もう頼み直せる差額が無い）
        $this->send('POST', $url)->assertStatus(409);
        $this->assertCount(3, $this->stripe->refunds);

        // 頼み直しは中央の監査ログに残る（誰が・どの店で・どの操作を・結果）
        $audited = AuditEvent::query()->where('type', 'operator.tenant_request')->where('operator_id', $owner->id)->get()
            ->filter(static fn (AuditEvent $e): bool => ($e->data['route'] ?? null) === 'api/tenants/{tenant}/transactions/{transactionId}/shipping-refund/retry');
        $this->assertSame([423, 200, 200, 409], $audited->map(static fn (AuditEvent $e): mixed => $e->data['status'] ?? null)->values()->all());
        $this->assertSame(['gsh'], $audited->pluck('tenant_id')->unique()->values()->all());
    }

    public function test_only_money_staff_may_retry_and_other_shops_are_not_reached(): void
    {
        $order = $this->paidGift();
        $this->stripe->refundStatus = 'failed';
        $this->claim($order, self::SAPPORO);
        $this->tenant('gsx', 'ほかの店');

        $this->configureAdminHost();
        [$staff, $authenticator] = $this->enrolledOperator('operate@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'gsh', 'operate', 'test');
        $this->reconfirm($staff, $authenticator);
        $this->send('POST', "/api/tenants/gsh/transactions/{$order}/shipping-refund/retry")->assertForbidden();

        // ほかの店でお金の段階でも、その店の取引の ID は見つからない（店の境界）
        app(OperatorAccess::class)->grant($staff, 'gsx', 'money', 'test');
        $this->send('POST', "/api/tenants/gsx/transactions/{$order}/shipping-refund/retry")->assertNotFound();
        $this->assertCount(1, $this->stripe->refunds);
        $this->inside('gsh', fn () => $this->assertNull(Refund::query()->where('transaction_id', $order)->sole()->resolution));
    }

    /**
     * 贈り物として ¥5,000 の品を買い（送料はいちばん高い ¥2,000）、払いを確かにする
     */
    private function paidGift(): string
    {
        $listing = $this->inside('gsh', function (): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '花束', 'unit_amount' => 5000, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 5);

            return $listing->id;
        });
        $this->shop('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);
        $this->shop('POST', '/checkout', ['buyer' => self::BUYER, 'same_as_buyer' => '0', 'gift' => ['on' => '1', 'sender' => 'はなこ', 'message' => 'おめでとう']])->assertRedirect();
        $confirm = (string) $this->shop('GET', '/checkout/confirm')->getContent();
        $this->assertStringContainsString('¥2,000', $confirm);
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->shop('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertStatus(303);
        $order = $this->inside('gsh', fn (): string => (string) CheckoutOrder::query()->sole()->transaction_id);

        $this->inside('gsh', function () use ($order): void {
            $ref = (string) PaymentAttempt::query()->where('transaction_id', $order)->value('provider_ref');
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$order, 'checkout.session.completed', $ref, $order, 7000, 'jpy', 'pi_'.$order));
        });

        return $order;
    }

    /**
     * 受け取る人がお届け先を入れて確定する（確定の後の返金の仕事は、テストではその場で流れる）
     *
     * @param  array<string, string>  $address
     */
    private function claim(string $order, array $address): void
    {
        $token = $this->inside('gsh', fn (): string => (string) GiftOrder::query()->where('transaction_id', $order)->sole()->token_sealed);
        $this->shop('POST', '/p/gift/'.$token, ['address' => $address])->assertRedirect();
        $this->shop('POST', '/p/gift/'.$token.'/confirm')->assertRedirect();
        $this->inside('gsh', fn () => $this->assertSame('claimed', GiftOrder::query()->where('transaction_id', $order)->value('status')));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->cart !== null) {
            $this->withCookie(CartCookie::NAME, $this->cart);
        }

        $url = "http://gsh.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $this->cart = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->cart;

        return $response;
    }
}
