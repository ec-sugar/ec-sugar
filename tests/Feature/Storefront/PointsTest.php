<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Admin\Services\SalesPeriod;
use App\Admin\Services\SalesReport;
use App\Core\Discount\Services\CouponService;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\OrderShippingCharges;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Order\Services\ShippingDifferenceRefunds;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Party\Services\CustomerSessions;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\RefundResult;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Points\Models\OrderPoints;
use App\Core\Points\Models\PointEntry;
use App\Core\Points\Services\PointException;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use App\Core\Shop\Services\ShippingMethods;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Shop\Shipping\Regions;
use App\Core\Tax\TaxClass;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\TaxBreakdown;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartPoints;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberCookie;
use App\Storefront\Orders\OrderReader;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * ポイント（ADR 0068。お金の核）。会員だけが貯めて使える。1 ポイント = 1 円。
 * 使った分は注文を作る錠の中で残高を確かめて引き、調整の行（points）で率ごとに割り振る。払われずに消えた・払った後の取消・全額の返金で戻す。
 * 付けるのは受渡が全部発送された時で、数は注文の時の率で写した予定。期限は最後に動いてから 1 年。帳簿は追記だけで、残高は DB でも負にならない
 */
final class PointsTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private string $knit;

    private string $cookie;

    private ?string $token = null;

    private ?string $memberToken = null;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->stripe = FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'pts', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'pts.localhost']);
        [$this->knit, $this->cookie] = $this->inside(function (): array {
            $seller = Seller::query()->value('id');
            $knit = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            $cookie = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'クッキー缶', 'unit_amount' => 1080, 'status' => 'active', 'tax_class' => TaxClass::Reduced]);
            $location = Location::query()->orderBy('id')->firstOrFail();
            app(StockService::class)->receive($knit, $location, 20);
            app(StockService::class)->receive($cookie, $location, 20);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 10000]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);

            return [$knit->id, $cookie->id];
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->app['auth']->forgetGuards();
        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_member_uses_exactly_the_balance_and_it_becomes_points_lines_split_over_goods_and_shipping(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 1000);
            $cart = $this->cart([$this->knit => 1]);

            // 残高ちょうど（1000）: 4,400 + 送料 550 = 4,950 から引いて 3,950
            $view = app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com', 1000);
            $this->assertSame(1000, $view->points->used);
            $this->assertSame('−¥1,000', $view->points->usedLabel());
            $this->assertSame(3950, $view->total());
            // 割り振り: 4,400 : 550 → 889 : 111（端数は余りの大きい方へ）。付く元は 4,400 − 889 = 3,511、1% で 35
            $this->assertSame(111, $view->points->onShipping);
            $this->assertSame(3511, $view->points->earnBase);
            $this->assertSame(35, $view->points->earn);

            $sale = $this->place($cart, points: 1000);
            $this->assertSame(3950, $sale->total_amount);
            $lines = $sale->lines()->where('kind', 'points')->get();
            $this->assertSame([-1000], $lines->pluck('amount')->all());
            $this->assertSame('ポイントのご利用', $lines->firstOrFail()->description);
            // 送料の後に並ぶ
            $this->assertGreaterThan($sale->lines()->where('kind', 'shipping')->value('position'), $lines->firstOrFail()->position);

            $order = OrderPoints::query()->findOrFail($sale->id);
            $this->assertSame([1000, 35, 3511, 100], [$order->spent, $order->earn, $order->earn_base, $order->rate_bp]);
            $this->assertSame(1000, array_sum($order->allocations));
            $shippingPosition = (int) $sale->lines()->where('kind', 'shipping')->value('position');
            $this->assertSame(111, $order->allocations[$shippingPosition]);

            $this->assertSame(0, app(PointService::class)->balance($member->id));
            $spend = PointEntry::query()->where('kind', 'spend')->sole();
            $this->assertSame([-1000, 0, $sale->id], [$spend->points, $spend->balance_after, $spend->transaction_id]);
            $this->assertEquals(['points' => -1000, 'balance' => 0, 'transaction' => $sale->id], DomainEvent::query()->where('type', 'points.spent')->sole()->payload);

            // ご注文の状況とメール: 使ったポイントは値引きの並びに 1 行、付く予定は別に
            $view = app(OrderReader::class)->read($sale);
            $this->assertContains(['title' => 'ポイントのご利用', 'amountLabel' => '−¥1,000'], $view->discounts);
            $this->assertSame([1000, 35, 0, 'pending'], [$view->points?->spent, $view->points?->earn, $view->points?->earned, $view->points?->state]);
            $this->assertSame('35 ポイントが付く予定です（発送時に付きます）', $view->points?->note());
        });
    }

    public function test_one_point_more_than_the_balance_is_refused_at_the_input_and_more_than_the_total_is_capped(): void
    {
        $account = $this->inside(function (): CustomerAccount {
            $member = $this->member('hanako@example.com');
            $this->give($member, 6000);

            return $member;
        });
        $this->signIn($account);
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => 1]);

        // 残高より 1 多い: 入力の画面で断る（理由は欄の下に）
        $this->send('POST', '/checkout', $this->form(['points' => ['mode' => 'some', 'amount' => '6001']]))
            ->assertRedirect()->assertSessionHasErrors('points.amount');

        // 全部を選ぶと、値引きの後の合計（送料込み 4,950）までだけ使う。0 円の注文になる
        $this->send('POST', '/checkout', $this->form(['points' => ['mode' => 'all']]))->assertRedirect('http://pts.localhost/checkout/confirm');
        $confirm = $this->send('GET', '/checkout/confirm')->assertOk();
        $confirm->assertSee('ポイントのご利用')->assertSee('−¥4,950')->assertSee('お支払いは不要です（ポイントで 0 円のご注文）');
        $fingerprint = $this->field($confirm, 'fingerprint');
        $key = $this->field($confirm, 'order_key');
        $this->send('POST', '/checkout/confirm', ['order_key' => $key, 'fingerprint' => $fingerprint])->assertRedirect('http://pts.localhost/checkout/complete');

        $this->inside(function () use ($account): void {
            $sale = Transaction::query()->sole();
            $this->assertSame(0, $sale->total_amount);
            $this->assertSame(1050, app(PointService::class)->balance($account->id));
            // 0 円でも払いを飛ばして確定する（ポイントで 0 円）。付く元は 0 なので付く予定も無い
            $this->assertSame(0, OrderPoints::query()->findOrFail($sale->id)->earn);
        });
    }

    public function test_members_see_points_on_the_product_cart_checkout_order_and_account_pages_and_guests_only_a_hint(): void
    {
        // ゲスト: 商品の画面とカートに「会員なら」、注文の入力に使う欄は出ない
        $this->send('GET', "/products/{$this->knit}")->assertOk()->assertSee('会員の方は 44 ポイントが付きます');
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => 1]);
        $this->send('GET', '/cart')->assertOk()->assertSee('会員の方は 44 ポイントが付きます');
        $this->send('GET', '/checkout')->assertOk()->assertDontSee('data-test="points-fields"', false);

        $account = $this->inside(function (): CustomerAccount {
            $member = $this->member('hanako@example.com');
            $this->give($member, 300);

            return $member;
        });
        $this->signIn($account);

        $this->send('GET', "/products/{$this->knit}")->assertOk()->assertSee('44 ポイントが付きます')->assertDontSee('会員の方は');
        $this->send('GET', '/cart')->assertOk()->assertSee('このご注文で 44 ポイントが付きます（発送時）');
        $this->send('GET', '/checkout')->assertOk()->assertSee('data-test="points-fields"', false)->assertSee('300');
        $this->send('POST', '/checkout', $this->form(['points' => ['mode' => 'some', 'amount' => '200']]))->assertRedirect('http://pts.localhost/checkout/confirm');
        $confirm = $this->send('GET', '/checkout/confirm')->assertOk();
        // 4,400 + 550 − 200 = 4,750。付くのは (4,400 − 178) × 1% = 42
        $confirm->assertSee('data-test="points-used"', false)->assertSee('−¥200')->assertSee('¥4,750')->assertSee('このご注文で 42 ポイントが付きます（発送時）');
        $this->send('POST', '/checkout/confirm', ['order_key' => $this->field($confirm, 'order_key'), 'fingerprint' => $this->field($confirm, 'fingerprint')]);

        $sale = $this->inside(fn (): Transaction => Transaction::query()->sole());
        $this->inside(fn () => $this->pay($sale));
        $order = $this->send('GET', '/account')->assertOk();
        $order->assertSee('data-test="account-points"', false)->assertSee('data-test="account-points-pending"', false)->assertSee('ご注文で利用');
        $this->assertStringContainsString('>100</strong>', (string) $order->getContent());

        // 注文のメール（払った後の知らせ）にも付く予定
        $mail = $this->inside(fn (): string => (string) view('mail.order-text', ['order' => app(OrderReader::class)->read($sale), 'kind' => 'order_paid', 'link' => 'x', 'shopName' => '店'])->render());
        $this->assertStringContainsString('42 ポイントが付く予定です（発送時に付きます）', $mail);
    }

    public function test_reduced_and_standard_rates_each_get_their_share_and_the_tax_breakdown_matches_the_confirmation(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 600);
            $cart = $this->cart([$this->cookie => 1, $this->knit => 1]);
            $view = app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com', PHP_INT_MAX);

            // 1,080（8%）: 4,400（10%）: 送料 550 で 600 を配る → 107 : 438 : 55。10% の行は商品と送料の分を合わせて 493
            $this->assertSame([800 => 107, 1000 => 493], $view->points->byRate);
            $this->assertSame(55, $view->points->onShipping);
            $this->assertSame(6030 - 600, $view->total());

            $sale = $this->place($cart, points: PHP_INT_MAX);
            $this->assertSame([800 => -107, 1000 => -493], $sale->lines()->where('kind', 'points')->orderBy('tax_rate_bp')->pluck('amount', 'tax_rate_bp')->all());
            // 確かめの画面の内訳と注文の内訳が 1 円もずれない
            $this->assertSame($view->taxBreakdown()->fingerprint(), TaxBreakdown::ofTransaction($sale)->fingerprint());
            $this->assertSame([800 => 973, 1000 => 4457], array_column(TaxBreakdown::ofTransaction($sale)->rows, 'amount', 'rateBp'));
            // 付く元は商品の代金（値引きと使ったポイントの後）: 5,480 − 545 = 4,935 → 49
            $this->assertSame(49, OrderPoints::query()->findOrFail($sale->id)->earn);
        });
    }

    public function test_points_go_after_a_coupon_and_together_they_can_reach_zero(): void
    {
        $this->inside(function (): void {
            app(CouponService::class)->create(['code' => 'OFF1000', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 1000], 'test');
            $member = $this->member('hanako@example.com');
            $this->give($member, 10000);
            $cart = $this->cart([$this->knit => 1]);
            app(CartService::class)->applyCoupon($cart, 'OFF1000');
            $view = app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com', PHP_INT_MAX);

            // 値引きが先（4,400 − 1,000 = 3,400）、ポイントは送料込みの 3,950 まで
            $this->assertSame([1000, 3950, 0], [$view->discount, $view->points->used, $view->total()]);
            $sale = $this->place($cart, points: PHP_INT_MAX);
            $this->assertSame(0, $sale->total_amount);
            $this->assertSame([-1000, -3950], [(int) $sale->lines()->where('kind', 'discount')->sum('amount'), (int) $sale->lines()->where('kind', 'points')->sum('amount')]);
            $this->assertSame(10000 - 3950, app(PointService::class)->balance($member->id));
            $this->assertSame('お支払いは不要です（クーポンとポイントで 0 円のご注文）', (new CheckoutSummary($view, $this->input(), null, null))->paymentMethod());
        });
    }

    public function test_the_same_balance_spent_by_two_orders_only_confirms_one(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 1000);
            $carts = app(CartService::class);
            $first = $this->cart([$this->knit => 1]);
            $second = $this->cart([$this->knit => 1]);
            // 2 つの画面で同じ残高を見て確かめた
            $shownFirst = CheckoutSummary::fingerprintOf($carts->view($first, $this->input()->destination(), 'hanako@example.com', 1000));
            $shownSecond = CheckoutSummary::fingerprintOf($carts->view($second, $this->input()->destination(), 'hanako@example.com', 1000));

            $this->place($first, points: 1000, fingerprint: $shownFirst);

            // 後の方は錠の中で残高から決め直すので、使える数が変わって確定しない（在庫も押さえない）
            try {
                $this->place($second, points: 1000, fingerprint: $shownSecond);
                $this->fail('同じ残高で 2 つ目の注文が確定した');
            } catch (OrderException $e) {
                $this->assertTrue($e->changed);
            }

            $this->assertSame(1, Transaction::query()->count());
            $this->assertSame(1, PointEntry::query()->where('kind', 'spend')->count());
            $this->assertSame(0, app(PointService::class)->balance($member->id));
        });
    }

    public function test_a_rate_change_after_the_confirmation_does_not_confirm_the_order(): void
    {
        $this->inside(function (): void {
            $this->member('hanako@example.com');
            $cart = $this->cart([$this->knit => 1]);
            // 確かめの画面で「44 ポイント付きます」を見た後に、店が率を変えた: 合計は同じでも、見せた付く数と違うので確定しない
            $shown = CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com'));
            app(PointSettings::class)->update(['enabled' => true, 'rate_bp' => 500], 'test');

            try {
                $this->place($cart, fingerprint: $shown);
                $this->fail('見せた付く数と違うのに確定した');
            } catch (OrderException $e) {
                $this->assertTrue($e->changed);
            }

            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_a_spend_waits_for_another_writer_holding_the_chain_lock(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 1000);
            $cart = $this->cart([$this->knit => 1]);
            $shown = CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com', 1000));

            // 別の接続が台帳のロックを持ったまま（同じ会員の別の注文の途中）: 確定は先に進まず待つ
            config(['database.connections.tenant_other' => config('database.connections.tenant')]);
            $other = DB::connection('tenant_other');
            $other->beginTransaction();

            try {
                $other->select('SELECT pg_advisory_xact_lock(?)', [0x5E_C0_E7]);
                DB::statement("SET lock_timeout = '300ms'");

                try {
                    $this->place($cart, points: 1000, fingerprint: $shown);
                    $this->fail('台帳のロックを待たずに確定した');
                } catch (QueryException $e) {
                    $this->assertSame('55P03', $e->getCode());
                }
            } finally {
                $other->rollBack();
                DB::statement('SET lock_timeout = 0');
                DB::purge('tenant_other');
            }

            $this->assertSame(1000, app(PointService::class)->balance($member->id));
        });
    }

    public function test_points_come_back_when_the_order_lapses_unpaid_or_is_cancelled_after_payment_and_the_expiry_moves(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 3000);
            $points = app(PointService::class);

            // 払われずに消えた
            $lapsed = $this->place($this->cart([$this->knit => 1]), points: 1000);
            $this->assertSame(2000, $points->balance($member->id));
            $this->travel(10)->days();
            DB::transaction(function () use ($lapsed): void {
                app(EventStore::class)->lock();
                app(PaymentNotices::class)->cancelUnpaidOrder(Transaction::query()->lockForUpdate()->findOrFail($lapsed->id), 'system:expiry');
            });
            $this->assertSame(3000, $points->balance($member->id));
            $release = PointEntry::query()->where('kind', 'release')->sole();
            $this->assertSame([1000, $lapsed->id], [$release->points, $release->transaction_id]);
            // 期限は戻した時から 1 年（全部の期限が延びる）
            $this->assertTrue($points->expiresAt($member->id)?->equalTo(CarbonImmutable::now()->addYear()->startOfSecond()) ?? false);

            // 払った後の取消（発送の前）: 返金と一緒に戻る。2 度目の取消・同じ戻しは何もしない
            $paid = $this->place($this->cart([$this->knit => 1]), points: 500);
            $this->pay($paid);
            app(SaleOrderService::class)->cancel($paid, 'test');
            $this->assertSame(TransactionStatus::Cancelled, $paid->fresh()?->status);
            $this->assertSame(3000, $points->balance($member->id));
            $this->assertSame(0, $points->release($paid->id, 'test'));
            $this->assertSame(2, PointEntry::query()->where('kind', 'release')->count());
            // 発送の前に取り消した注文には付かない
            $this->assertSame(0, $points->earn($paid->id, 'test'));
            $this->assertSame(0, PointEntry::query()->where('kind', 'earn')->count());
        });
    }

    public function test_points_are_earned_when_everything_has_shipped_with_the_rate_copied_at_order_time(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $sale = $this->place($this->cart([$this->knit => 2]));
            $this->assertSame(88, OrderPoints::query()->findOrFail($sale->id)->earn);

            // 注文の後に率を 5% に上げても、この注文は注文の時の 1%
            app(PointSettings::class)->update(['enabled' => true, 'rate_bp' => 500], 'test');
            $this->pay($sale);
            $this->assertSame([['transactionId' => $sale->id, 'points' => 88]], array_map(static fn (array $o): array => ['transactionId' => $o['transactionId'], 'points' => $o['points']], app(PointService::class)->pendingOrders($member->id)));

            $this->shipAll($sale);
            $this->assertSame(88, app(PointService::class)->balance($member->id));
            $this->assertSame([], app(PointService::class)->pendingOrders($member->id));
            $earn = PointEntry::query()->where('kind', 'earn')->sole();
            $this->assertSame([88, $sale->id], [$earn->points, $earn->transaction_id]);
            $this->assertSame('earned', app(PointService::class)->forOrder($sale->id)['state'] ?? null);

            // 同じ注文に 2 度は付けない（DB の一意の索引も断る）
            $this->assertSame(0, app(PointService::class)->earn($sale->id, 'test'));
            $this->expectDatabaseRefusal(fn () => PointEntry::create(['customer_account_id' => $member->id, 'kind' => 'earn', 'points' => 88, 'transaction_id' => $sale->id, 'occurred_at' => now()]), 'point_entries_once_per_order');
        });
    }

    public function test_a_full_refund_after_shipping_revokes_the_earned_points_without_going_negative(): void
    {
        [$sale, $member] = $this->inside(function (): array {
            $member = $this->member('hanako@example.com');
            $this->give($member, 100);
            $sale = $this->place($this->cart([$this->knit => 2]), points: 100);
            $this->pay($sale, 'pi_points');
            $this->shipAll($sale);
            // 8,800 + 550 − 100: 付く元は 8,800 − 94 = 8,706 → 87
            $this->assertSame(87, app(PointService::class)->balance($member->id));
            // 付いた分を使ってしまった（残りは 7）
            app(PointService::class)->adjust($member, -80, '使った分の例', 'test');

            return [$sale, $member];
        });

        // 発送の後に Stripe の画面で全額を返し、店の人が確かめて記録する
        $this->inside(function () use ($sale, $member): void {
            $notices = app(PaymentNotices::class);
            $notices->refundUpdated('stripe', 'evt_full', 'refund.created', new RefundResult('re_full', 'succeeded', null, (int) $sale->total_amount, 'pi_points'));
            app(PaymentRefunds::class)->resolve(Refund::query()->sole(), 'recorded', (int) $sale->total_amount, 'test');

            // 使った 100 は戻り、付けた 87 は残高（7 + 100 = 107）から引く
            $this->assertSame(20, app(PointService::class)->balance($member->id));
            $revoke = PointEntry::query()->where('kind', 'revoke')->sole();
            $this->assertSame([-87, 0, 'refund'], [$revoke->points, $revoke->shortfall, $revoke->cause]);
            $this->assertSame('revoked', app(PointService::class)->forOrder($sale->id)['state'] ?? null);
        });
    }

    public function test_a_revoke_larger_than_the_balance_takes_what_is_left_and_records_the_shortfall(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $sale = $this->place($this->cart([$this->knit => 2]));
            $this->pay($sale, 'pi_short');
            $this->shipAll($sale);
            app(PointService::class)->adjust($member, -80, '使った分の例', 'test');

            app(PointService::class)->refunded($sale->id, 'test');
            $revoke = PointEntry::query()->where('kind', 'revoke')->sole();
            // 付けた 88 のうち残高の 8 だけを引き、足りない 80 は記録に残す。残高は負にならない
            $this->assertSame([-8, 80, 0], [$revoke->points, $revoke->shortfall, $revoke->balance_after]);
            $this->assertEquals(['points' => -8, 'balance' => 0, 'transaction' => $sale->id, 'cause' => 'refund', 'shortfall' => 80], DomainEvent::query()->where('type', 'points.revoked')->sole()->payload);
            // 2 度目は何もしない
            app(PointService::class)->refunded($sale->id, 'test');
            $this->assertSame(1, PointEntry::query()->where('kind', 'revoke')->count());
        });
    }

    public function test_points_expire_one_year_after_the_last_move_and_a_move_pushes_the_expiry(): void
    {
        $this->inside(function (): void {
            $points = app(PointService::class);
            $member = $this->member('hanako@example.com');
            $start = CarbonImmutable::now()->startOfSecond();
            $this->travelTo($start);
            $this->give($member, 500);

            // 半年後に動く（使う）と、そこから 1 年に延びる
            $this->travelTo($start->addMonths(6));
            $this->place($this->cart([$this->knit => 1]), points: 100);
            $moved = CarbonImmutable::now()->startOfSecond();
            $this->assertTrue($points->expiresAt($member->id)?->equalTo($moved->addYear()) ?? false);

            // 最初から 1 年: もう延びているので消えない
            $this->travelTo($start->addYear());
            $points->expireDue(CarbonImmutable::now(), 'system:points');
            $this->assertSame(400, $points->balance($member->id));

            // 動いてから 1 年の 1 秒前は消えない、ちょうどで消える
            $this->travelTo($moved->addYear()->subSecond());
            $this->assertSame([], $points->dueAccounts(CarbonImmutable::now()));
            $this->assertFalse($points->expireAccount($member->id, CarbonImmutable::now(), 'system:points'));
            $this->travelTo($moved->addYear());
            $this->assertSame(1, $points->expireDue(CarbonImmutable::now(), 'system:points'));
            $this->assertSame(0, $points->balance($member->id));
            $expire = PointEntry::query()->where('kind', 'expire')->sole();
            $this->assertSame([-400, 'inactive'], [$expire->points, $expire->cause]);
        });
    }

    public function test_two_expiry_runs_at_the_same_time_expire_only_once(): void
    {
        $this->inside(function (): void {
            $points = app(PointService::class);
            $member = $this->member('hanako@example.com');
            $this->give($member, 300);
            $this->travel(366)->days();
            $now = CarbonImmutable::now();

            // 2 つの回が同じ時に集めた（錠の外）。片方が消した後に、もう片方が同じ会員を消しに来る
            $first = $points->dueAccounts($now);
            $second = $points->dueAccounts($now);
            $this->assertSame([$member->id], $first);
            $this->assertTrue($points->expireAccount($first[0], $now, 'system:points'));
            $this->assertFalse($points->expireAccount($second[0], $now, 'system:points'));

            $this->assertSame(1, PointEntry::query()->where('kind', 'expire')->count());
            $this->assertSame(0, $points->balance($member->id));
        });

        // コマンドを続けて 2 回流しても同じ
        Artisan::call('points:expire');
        Artisan::call('points:expire');
        $this->inside(fn () => $this->assertSame(1, PointEntry::query()->where('kind', 'expire')->count()));
    }

    public function test_leaving_the_shop_forfeits_the_balance(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 700);
            app(CustomerAccounts::class)->leave($member, 'storefront');

            $this->assertSame(0, app(PointService::class)->balance($member->id));
            $this->assertSame('left', PointEntry::query()->where('kind', 'expire')->sole()->cause);
            $this->assertNull(app(PointService::class)->expiresAt($member->id));
        });
    }

    public function test_the_ledger_refuses_updates_deletes_truncates_and_a_negative_balance(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $entry = $this->give($member, 100);

            $this->expectDatabaseRefusal(fn () => PointEntry::query()->whereKey($entry->id)->update(['points' => 1000]));
            $this->expectDatabaseRefusal(fn () => PointEntry::query()->whereKey($entry->id)->delete());
            $this->expectDatabaseRefusal(fn () => DB::statement('TRUNCATE point_entries CASCADE'));
            // アプリをすり抜けて残高より多く引く行を入れても、DB が残高を計算し直して断る
            $this->expectDatabaseRefusal(fn () => PointEntry::create(['customer_account_id' => $member->id, 'kind' => 'adjust', 'points' => -101, 'occurred_at' => now()]), 'point_entries_negative_balance');
            // 残高はアプリの値を信じず前の行から計算する（嘘の balance_after は上書きされる）
            DB::transaction(fn () => DB::table('point_entries')->insert(['id' => strtolower((string) Str::ulid()), 'customer_account_id' => $member->id, 'kind' => 'adjust', 'points' => -1, 'balance_after' => 99999, 'occurred_at' => now()]));
            $this->assertSame(99, app(PointService::class)->balance($member->id));

            // 帳簿の最後の残高は、行の合計といつも同じ
            $this->assertSame((int) PointEntry::query()->where('customer_account_id', $member->id)->sum('points'), app(PointService::class)->balance($member->id));
            $this->assertSame(99, app(PointService::class)->outstanding());
        });
    }

    public function test_guests_cannot_use_points_and_only_see_what_a_member_would_earn(): void
    {
        $this->inside(function (): void {
            $cart = $this->cart([$this->knit => 1]);
            $view = app(CartService::class)->view($cart, $this->input()->destination(), 'guest@example.com', PHP_INT_MAX);

            $this->assertSame([false, 0, 44], [$view->points->member, $view->points->used, $view->points->earn]);
            $this->assertSame('会員の方は 44 ポイントが付きます', $view->points->earnNote());
            $sale = $this->place($cart, 'guest@example.com', PHP_INT_MAX);
            $this->assertSame(4950, $sale->total_amount);
            $this->assertNull(OrderPoints::query()->find($sale->id));
            $this->assertSame(0, PointEntry::query()->count());

            // 会員でない台帳には直に書いても断る
            $guest = CustomerAccount::query()->sole();
            $this->expectException(PointException::class);
            app(PointService::class)->adjust($guest, 10, 'テスト', 'test');
        });
    }

    public function test_a_shop_that_turns_points_off_neither_adds_nor_lets_members_use_them_but_keeps_the_balance(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 500);
            app(PointSettings::class)->update(['enabled' => false, 'rate_bp' => 100], 'test');

            $cart = $this->cart([$this->knit => 1]);
            $view = app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com', 500);
            $this->assertSame([false, 0, 0, null], [$view->points->enabled, $view->points->used, $view->points->earn, $view->points->earnNote()]);
            $sale = $this->place($cart, points: 500);
            $this->assertSame(4950, $sale->total_amount);
            $this->assertNull(OrderPoints::query()->find($sale->id));
            $this->assertSame(500, app(PointService::class)->balance($member->id));
        });
    }

    public function test_points_events_carry_no_personal_information(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 100, '山田 花子さんへのお詫び');
            $this->place($this->cart([$this->knit => 1]), points: 50);

            $payloads = DomainEvent::query()->where('type', 'like', 'points.%')->pluck('payload')->all();
            $this->assertCount(2, $payloads);
            $json = json_encode($payloads, JSON_UNESCAPED_UNICODE);
            foreach (['hanako@example.com', '山田', 'お詫び'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $json);
            }
            // 理由は帳簿に暗号化して置く（列は暗号文）
            $this->assertStringNotContainsString('お詫び', (string) DB::table('point_entries')->whereNotNull('note')->value('note'));
            $this->assertSame('山田 花子さんへのお詫び', PointEntry::query()->whereNotNull('note')->sole()->note);
        });
    }

    public function test_another_shop_does_not_see_this_shops_points(): void
    {
        $other = Tenant::create(['id' => 'ptz', 'name' => '別の店']);

        try {
            $this->inside(function (): void {
                $member = $this->member('hanako@example.com');
                $this->give($member, 900);
            });

            tenancy()->initialize($other);

            try {
                // 同じメールでも、店ごとの台帳。ほかの店の残高は見えない
                $this->assertSame(0, PointEntry::query()->count());
                $this->assertSame(0, app(PointService::class)->outstanding());
            } finally {
                tenancy()->end();
            }
        } finally {
            $other->delete();
        }
    }

    public function test_an_order_that_will_not_earn_does_not_say_it_will(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 1000);
            $points = app(PointService::class);

            // 取り消した注文: 「付く予定です」を出さない
            $cancelled = $this->place($this->cart([$this->knit => 1]), points: 100);
            $this->assertSame('43 ポイントが付く予定です（発送時に付きます）', app(OrderReader::class)->read($cancelled)->points?->note());
            app(SaleOrderService::class)->cancel($cancelled, 'test');
            $this->assertSame('cancelled', $points->forOrder($cancelled->id)['state'] ?? null);
            $this->assertNull(app(OrderReader::class)->read(Transaction::query()->findOrFail($cancelled->id))->points?->note());

            // 払った後に退会し、発送しても付かない注文: pending のままにしない
            $left = $this->place($this->cart([$this->knit => 1]));
            $this->pay($left);
            app(CustomerAccounts::class)->leave(CustomerAccount::query()->findOrFail($member->id), 'storefront');
            $this->shipAll($left);
            $this->assertSame(0, PointEntry::query()->where('kind', 'earn')->count());
            $this->assertSame('none', $points->forOrder($left->id)['state'] ?? null);
            $this->assertNull(app(OrderReader::class)->read($left)->points?->note());
        });
    }

    public function test_a_disabled_account_keeps_the_points_that_come_back(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 1000);
            $sale = $this->place($this->cart([$this->knit => 1]), points: 300);
            // 店が台帳を止めた（退会ではない）: 戻ったポイントは消さない
            CustomerAccount::query()->whereKey($member->id)->update(['disabled_at' => now()]);
            app(SaleOrderService::class)->cancel($sale, 'test');
            $this->assertSame(1000, app(PointService::class)->balance($member->id));
            $this->assertSame(0, PointEntry::query()->where('kind', 'expire')->count());
        });
    }

    public function test_the_shipping_difference_of_a_gift_paid_only_with_points_comes_back_as_points(): void
    {
        $order = $this->giftOrder(10000, PHP_INT_MAX);

        $this->inside(function () use ($order): void {
            // ニット 4,400 + いちばん高い送料 2,000 = 6,400 を全部ポイントで（0 円の注文）
            $this->assertSame(0, (int) $order->total_amount);
            $this->assertSame('none', $this->settleGift($order));
            // 北海道の送料 1,500 との差額 500 は、現金で返せないのでポイントで戻す。期限は戻した時から 1 年
            $back = PointEntry::query()->where('kind', 'release')->sole();
            $this->assertSame([500, 'shipping'], [$back->points, $back->cause]);
            $this->assertSame(10000 - 6400 + 500, app(PointService::class)->balance($back->customer_account_id));
            $this->assertTrue($back->expires_at?->equalTo($back->occurred_at->addYear()) ?? false);
            // 2 度目は戻さない
            $this->assertSame('none', app(ShippingDifferenceRefunds::class)->refund($order->id, 'test'));
            $this->assertSame(0, app(PointService::class)->creditShipping($order->id, 500, 'test'));
            $this->assertSame(1, PointEntry::query()->where('kind', 'release')->count());
            $this->assertCount(0, $this->stripe->refunds);
            // 送料の差額の戻しは取消ではない（全部ポイントで払ったので付く予定は 0）
            $this->assertSame('none', app(PointService::class)->forOrder($order->id)['state'] ?? null);

            // 後で取り消すと、使った残り（6,400 − 500）だけを戻す
            app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($order->id), 'test');
            $this->assertSame(5900, (int) PointEntry::query()->where('kind', 'release')->whereNull('cause')->value('points'));
            $this->assertSame(10000, app(PointService::class)->balance($back->customer_account_id));
        });
    }

    public function test_the_part_of_a_gift_shipping_difference_the_cash_cannot_cover_comes_back_as_points(): void
    {
        $order = $this->giftOrder(6000, PHP_INT_MAX);

        $this->inside(function () use ($order): void {
            // 6,400 − 6,000 ポイント = 400 円を払った。差額 500 のうち 400 円は現金で、残り 100 はポイントで戻す
            $this->assertSame(400, (int) $order->total_amount);
            $this->pay($order);
            $this->assertSame('succeeded', $this->settleGift($order));
            $this->assertSame([400], array_column($this->stripe->refunds, 'amount'));
            $back = PointEntry::query()->where('kind', 'release')->sole();
            $this->assertSame([100, 'shipping'], [$back->points, $back->cause]);
            // 送料の差額の返金（shipping。成功の道）で受け取った額が 0 になっても、付けた分の取り消し・使った分の戻しはしない
            $this->assertSame(0, PointEntry::query()->where('kind', 'revoke')->count());
            $this->assertSame(1, PointEntry::query()->where('kind', 'release')->count());
        });
    }

    public function test_a_gift_shipping_difference_the_cash_covers_gives_no_points(): void
    {
        // 1,000 ポイントを使い、5,400 円を払った: 差額 500 は現金で返せるので、ポイントでは戻さない
        $order = $this->giftOrder(1000, 1000);

        $this->inside(function () use ($order): void {
            $this->assertSame(5400, (int) $order->total_amount);
            $this->pay($order);
            $this->assertSame('succeeded', $this->settleGift($order));
            $this->assertSame([500], array_column($this->stripe->refunds, 'amount'));
            $this->assertSame(0, PointEntry::query()->where('kind', 'release')->count());
        });
    }

    public function test_a_full_refund_of_an_order_that_can_still_ship_leaves_the_points_alone(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 100);
            $sale = $this->place($this->cart([$this->knit => 1]), points: 100);
            $this->pay($sale, 'pi_open');

            // まだ発送していない注文を Stripe の画面で全額返し、店の人が記録した（止めた返金の片付けの道）: 店はまだ送るかもしれないので、ポイントは動かさない
            app(PaymentNotices::class)->refundUpdated('stripe', 'evt_open', 'refund.created', new RefundResult('re_open', 'succeeded', null, (int) $sale->total_amount, 'pi_open'));
            app(PaymentRefunds::class)->resolve(Refund::query()->sole(), 'recorded', (int) $sale->total_amount, 'test');
            $this->assertSame(0, PointEntry::query()->whereIn('kind', ['release', 'revoke'])->count());

            // 発送すれば、注文の時の付く予定が付く
            $this->shipAll($sale);
            $this->assertSame(1, PointEntry::query()->where('kind', 'earn')->count());
        });
    }

    public function test_the_total_after_points_is_zero_or_at_least_fifty_yen(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 10000);
            $carts = app(CartService::class);
            $cart = $this->cart([$this->knit => 1]);
            $to = $this->input()->destination();
            $view = static fn (int $points) => $carts->view($cart, $to, 'hanako@example.com', $points);

            // 合計 4,950。49 円が残る数は 50 円が残る数に抑える。50 円・0 円が残る数はそのまま
            $this->assertSame([4900, 50, true], [$view(4901)->points->used, $view(4901)->total(), $view(4901)->points->lowered]);
            $this->assertSame([4900, 50, false], [$view(4900)->points->used, $view(4900)->total(), $view(4900)->points->lowered]);
            $this->assertSame([4950, 0, false], [$view(4950)->points->used, $view(4950)->total(), $view(4950)->points->lowered]);
            $this->assertSame(0, $view(PHP_INT_MAX)->total());

            // 「全部使う」で残高が合計に 30 足りない: 50 円が残る数（4,900）にする
            app(PointService::class)->adjust($member, -(10000 - 4920), 'テスト', 'test');
            $this->assertSame([4900, 50], [$view(PHP_INT_MAX)->points->used, $view(PHP_INT_MAX)->total()]);
            // 確定も同じ決め方（指紋がずれない）
            $this->assertSame(50, (int) $this->place($cart, points: PHP_INT_MAX)->total_amount);
        });
    }

    public function test_a_number_that_leaves_one_to_forty_nine_yen_is_refused_at_the_input(): void
    {
        $account = $this->inside(function (): CustomerAccount {
            $member = $this->member('hanako@example.com');
            $this->give($member, 10000);

            return $member;
        });
        $this->signIn($account);
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => 1]);

        $this->send('POST', '/checkout', $this->form(['points' => ['mode' => 'some', 'amount' => '4901']]))->assertRedirect()->assertSessionHasErrors(['points.amount' => CartPoints::BELOW_MINIMUM]);
        $this->send('POST', '/checkout', $this->form(['points' => ['mode' => 'some', 'amount' => '4900']]))->assertRedirect('http://pts.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertOk()->assertSee('−¥4,900')->assertSee('¥50');
    }

    public function test_the_ledger_numbers_each_members_rows_and_a_second_writer_waits(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 100);
            $this->give($member, 50);
            $this->assertSame([1, 2], PointEntry::query()->orderBy('account_seq')->pluck('account_seq')->all());

            // 連鎖のロックを通らない別の書き込みが、同じ会員の行を足したまま確定していない: 次の行は待つ（同じ前の行から計算しない）
            config(['database.connections.tenant_other' => config('database.connections.tenant')]);
            $other = DB::connection('tenant_other');
            $other->beginTransaction();

            try {
                $other->table('point_entries')->insert(['id' => strtolower((string) Str::ulid()), 'customer_account_id' => $member->id, 'kind' => 'adjust', 'points' => -150, 'occurred_at' => now()]);
                DB::statement("SET lock_timeout = '300ms'");

                try {
                    DB::transaction(static function () use ($member): void {
                        DB::table('point_entries')->insert(['id' => strtolower((string) Str::ulid()), 'customer_account_id' => $member->id, 'kind' => 'adjust', 'points' => -150, 'occurred_at' => now()]);
                    });
                    $this->fail('確定していない書き込みを待たずに、同じ残高から引けた');
                } catch (QueryException $e) {
                    $this->assertSame('55P03', $e->getCode());
                }
            } finally {
                $other->rollBack();
                DB::statement('SET lock_timeout = 0');
                DB::purge('tenant_other');
            }

            $this->assertSame(150, app(PointService::class)->balance($member->id));
        });
    }

    public function test_points_used_in_the_sales_report_leave_out_orders_that_gave_them_back(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $this->give($member, 1000);
            $kept = $this->place($this->cart([$this->knit => 1]), points: 300);
            $this->pay($kept);
            $back = $this->place($this->cart([$this->knit => 1]), points: 200);
            $this->pay($back);
            app(SaleOrderService::class)->cancel($back, 'test');
            // テストのモードの支払いは売上に数えないので、本番の支払いにする（見分けは test_mode 列）
            PaymentAttempt::query()->where('status', 'paid')->update(['test_mode' => false]);

            $report = app(SalesReport::class)->summary(SalesPeriod::of('today'));
            $this->assertSame(300, $report['totals']['points_used']);
        });
    }

    // --- 補助 ---

    private function member(string $email): CustomerAccount
    {
        $account = app(CustomerAccounts::class)->forEmail($email, 'test');
        CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);
        $account = CustomerAccount::query()->findOrFail($account->id);
        Auth::guard('customer')->setUser(new Member($account, 'pts'));

        return $account;
    }

    private function give(CustomerAccount $account, int $points, string $reason = 'テスト'): PointEntry
    {
        return app(PointService::class)->adjust($account, $points, $reason, 'test');
    }

    /**
     * @param  array<string, int>  $items
     */
    private function cart(array $items): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();

        foreach ($items as $listing => $quantity) {
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $listing)->value('id'), $quantity);
        }

        return $cart;
    }

    private function place(Cart $cart, string $email = 'hanako@example.com', int $points = 0, ?string $fingerprint = null, bool $gift = false): Transaction
    {
        $input = $this->input($email, $points, $gift);
        $fingerprint ??= CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart, $input->destination(), $email, $input->pointsRequested()));

        return app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $fingerprint);
    }

    private function input(string $email = 'hanako@example.com', int $points = 0, bool $gift = false): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => $email, 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null,
            'gift' => $gift ? ['sender' => 'はなこ', 'message' => 'おめでとう'] : null,
            'points' => match (true) {
                $points === PHP_INT_MAX => ['mode' => 'all'],
                $points > 0 => ['mode' => 'some', 'amount' => $points],
                default => null,
            }]);
        $this->assertNotNull($input);

        return $input;
    }

    /**
     * 会員がニット（4,400）を贈り物で買う（送料はいちばん高い 2,000。北海道は 1,500）
     */
    private function giftOrder(int $balance, int $points): Transaction
    {
        return $this->inside(function () use ($balance, $points): Transaction {
            $table = app(ShippingMethods::class);
            $table->replace(['methods' => [['id' => $table->all()[0]['id'], 'name' => '通常便', 'free_over' => null, 'rates' => [...Regions::flat(800), '北海道' => 1500, '沖縄県' => 2000]]]], 'test');
            app(PluginStates::class)->set('gift', true, 'test');
            $member = $this->member('hanako@example.com');

            if ($balance > 0) {
                $this->give($member, $balance);
            }

            return $this->place($this->cart([$this->knit => 1]), points: $points, gift: true);
        });
    }

    /**
     * 受け取る人が北海道の住所を入れて送料の差額が決まり、差額を返す（受け取りの後の仕事と同じ）
     */
    private function settleGift(Transaction $order): string
    {
        DB::transaction(function () use ($order): void {
            app(EventStore::class)->lock();
            $this->assertSame(500, app(OrderShippingCharges::class)->settle($order->id, '北海道', 'test'));
        });

        return app(ShippingDifferenceRefunds::class)->refund($order->id, 'test');
    }

    private function pay(Transaction $sale, ?string $paymentRef = null): void
    {
        app(PaymentService::class)->startCheckout($sale, 'x', 'y');
        $ref = PaymentAttempt::query()->where('transaction_id', $sale->id)->sole()->provider_ref;
        $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$sale->id, 'checkout.session.completed', $ref, $sale->id, (int) $sale->total_amount, 'jpy', $paymentRef ?? 'pi_'.$sale->id));
    }

    private function shipAll(Transaction $sale): void
    {
        foreach (Fulfillment::query()->where('transaction_id', $sale->id)->get() as $fulfillment) {
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale->id), $fulfillment->id, 'yamato', '1234', 'operator:test');
        }
    }

    private function expectDatabaseRefusal(Closure $write, string $message = '追記専用'): void
    {
        try {
            DB::transaction(static function () use ($write): void {
                $write();
            });
            $this->fail('DB が書き込みを断らなかった');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function signIn(CustomerAccount $account): void
    {
        $this->memberToken = $this->inside(fn (): string => app(CustomerSessions::class)->start($account));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function form(array $extra = []): array
    {
        return ['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1'], 'same_as_buyer' => '1', ...$extra];
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function field(TestResponse $response, string $name): string
    {
        preg_match('/name="'.$name.'" value="([^"]+)"/', (string) $response->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, "{$name} が無い");

        return html_entity_decode($m[1]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        if ($this->memberToken !== null) {
            $this->withCookie(MemberCookie::NAME, $this->memberToken);
        }

        $this->app['auth']->forgetGuards();
        $url = "http://pts.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(CartCookie::NAME);

        if ($cookie !== null) {
            $this->token = (string) $cookie->getValue();
        }

        return $response;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inside(Closure $work): mixed
    {
        tenancy()->initialize($this->tenant);

        try {
            return $work();
        } finally {
            tenancy()->end();
        }
    }
}
