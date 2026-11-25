<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Models\CheckoutPlan;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\PlaceOrder;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 払われない注文の期限切れ。支払いの画面の期限と猶予が過ぎた店頭の注文を取り消し、在庫を戻す。
 * 取り消す前に Stripe に問い合わせ、払われていれば（通知が届かなかった）支払いを確定し、届かなければ次の回へ回す。
 */
final class ExpireUnpaidOrdersTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private FakeStripe $stripe;

    private string $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->stripe = FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'exa', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'exa.localhost']);
        $this->listing = $this->inside(function (): string {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 10);

            return $listing->id;
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_unpaid_orders_are_cancelled_after_the_grace_and_their_stock_returns(): void
    {
        $old = $this->order();
        $paidLate = $this->order();
        $unreachable = $this->order(checkout: false);
        // 管理画面などで作った取引（店頭の注文ではない）。同じだけ古くても触らない
        $admin = $this->inside(fn (): string => app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), null, [LineDraft::fromListing(Listing::query()->findOrFail($this->listing), 1, 1000, Location::query()->firstOrFail())], actor: 'test')->id);
        $this->travel(61 + 5)->minutes();
        // 期限の後の猶予（10 分）の中: まだ取り消さない
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 0 件')->assertSuccessful();
        $this->assertSame(4, $this->reserved());

        $this->travel(6)->minutes();
        $fresh = $this->order();
        // 通知が届かなかったが、Stripe では払われていた
        $this->stripe->sessions[$this->ref($paidLate)] = ['payment_status' => 'paid', 'status' => 'complete', 'payment_intent' => 'pi_test_late'] + $this->stripe->sessions[$this->ref($paidLate)];

        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 2 件、支払いを確定 1 件')->assertSuccessful();

        $this->inside(function () use ($old, $paidLate, $unreachable, $fresh, $admin): void {
            // 支払いの画面の期限が切れた注文と、支払いの画面を作れなかった注文は取り消し。在庫の確保が戻る
            foreach ([$old, $unreachable] as $id) {
                $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($id)->status);
            }
            $this->assertSame('expired', PaymentAttempt::query()->where('transaction_id', $old)->sole()->status);
            $this->assertSame(2, DomainEvent::query()->where('type', 'payment.order_expired')->count());
            // Stripe で払われていた注文は支払いを確定（取り消さない）
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($paidLate)->phase);
            $this->assertSame('pi_test_late', LedgerJournal::query()->where('transaction_id', $paidLate)->where('type', JournalType::Payment)->sole()->reference);
            // 新しい注文と、管理画面などで作った取引には触らない
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($fresh)->status);
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($admin)->status);
        });
        // 押さえているのは、払われた 1 点・新しい注文の 1 点・管理画面の取引の 1 点
        $this->assertSame(3, $this->reserved());

        // もう一度回しても、同じ注文を二度取り消さない
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 0 件')->assertSuccessful();
    }

    public function test_orders_are_kept_while_stripe_cannot_be_asked_or_still_takes_payment(): void
    {
        $unreachable = $this->order();
        $stillOpen = $this->order();
        $this->travel(80)->minutes();

        // Stripe に届かない間は取り消さない（払われているかもしれない）
        $this->stripe->unreachable = true;
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 0 件、支払いを確定 0 件、次の回へ 2 件')->assertSuccessful();
        $this->stripe->unreachable = false;

        // Stripe ではまだ払える（こちらの記録と食い違う）。もう 1 つは期限が切れている
        $this->stripe->sessions[$this->ref($stillOpen)]['keep_open'] = true;
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 1 件、支払いを確定 0 件、次の回へ 1 件')->assertSuccessful();
        $this->inside(fn () => $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($stillOpen)->status));

        // 取り消した後に届いた支払いの通知は、支払いにせず記録（返金は人が判断）
        $this->inside(function () use ($unreachable): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $unreachable)->sole();
            $this->assertSame('late', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_late', 'checkout.session.completed', $attempt->provider_ref, $unreachable, 4400, 'jpy', 'pi_x'));
        });
    }

    public function test_a_new_payment_page_or_a_moved_transaction_is_not_cancelled(): void
    {
        // 古い注文でも、お客さんが「お支払いへ進む」で新しい支払いの画面を作っていれば、その期限まで待つ（Stripe に聞けなくても）
        $order = $this->order();
        $this->travel(80)->minutes();
        $this->inside(fn () => app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($order), 'x', 'y'));
        $this->inside(fn () => app(PaymentGatewayService::class)->removeStripe());
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 0 件')->assertSuccessful();

        // 問い合わせの間に、取引がほかで進んだ（古い取引の値を渡されても、ロックの後に確かめ直して取り消さない）
        $this->travel(80)->minutes();
        $this->inside(function () use ($order): void {
            $stale = Transaction::query()->findOrFail($order);
            app(TransactionService::class)->transition(Transaction::query()->findOrFail($order), Phase::Payment);
            $this->assertSame('closed', app(PaymentService::class)->expireIfUnpaid($stale, now()->toImmutable()));
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($order)->status);
        });
    }

    public function test_two_clicks_at_once_make_one_payment_page(): void
    {
        $order = $this->order(checkout: false);
        // 後の押下が「使える画面は無い」と見た直後に、先の押下が画面を作り終える（別の窓のレビュー 1）
        $this->afterFirstQuery('"expires_at" >', function () use ($order): void {
            app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($order), 'x', 'y');
        });

        $session = $this->inside(fn () => app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($order), 'x', 'y'));

        $this->inside(function () use ($order, $session): void {
            // 画面は 1 つ。後の押下も同じ画面へ送る
            $this->assertSame($session->ref, PaymentAttempt::query()->where('transaction_id', $order)->sole()->provider_ref);
        });
        $this->assertSame(1, $this->checkoutsCreated());
    }

    public function test_a_payment_page_made_while_the_expiry_asks_stripe_keeps_the_order(): void
    {
        // 期限の過ぎた注文を片付けが Stripe に問い合わせている間に、お客さんが「お支払いへ進む」で新しい画面を作った（レビュー 2）
        $order = $this->order();
        $this->travel(80)->minutes();
        // 割り込みは店の文脈の中で走る（入り直さない）
        $this->stripe->before = function () use ($order): void {
            app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($order), 'x', 'y');
        };

        $this->assertSame('waiting', $this->inside(fn () => app(PaymentService::class)->expireIfUnpaid(Transaction::query()->findOrFail($order), now()->subMinutes(10)->toImmutable())));

        $this->inside(function () use ($order): void {
            // 取り消さず、新しい画面は払えるまま（待つときは何も書き換えない。古い画面は次の回に片付く）
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($order)->status);
            $this->assertSame(2, PaymentAttempt::query()->where('transaction_id', $order)->count());
            $this->assertSame('open', PaymentAttempt::query()->where('transaction_id', $order)->latest('created_at')->firstOrFail()->status);
            $this->assertSame(0, DomainEvent::query()->where('type', 'payment.order_expired')->count());
        });
    }

    public function test_an_order_cancelled_before_the_page_is_planned_gets_no_page(): void
    {
        // 押下が「払える注文」と見た後、予約を取る前に、片付けが注文を取り消した（レビュー 2 の逆の順）
        $order = $this->order(checkout: false);
        $this->travel(80)->minutes();
        $this->afterFirstQuery('"expires_at" >', function () use ($order): void {
            $this->assertSame('cancelled', app(PaymentService::class)->expireIfUnpaid(Transaction::query()->findOrFail($order), now()->subMinutes(10)->toImmutable()));
        });

        $this->inside(function () use ($order): void {
            try {
                app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($order), 'x', 'y');
                $this->fail('取り消した注文に支払いの画面を作った');
            } catch (PaymentUnavailableException $e) {
                $this->assertStringContainsString('現在お支払いに進めません', $e->getMessage());
            }
            $this->assertSame(0, PaymentAttempt::query()->where('transaction_id', $order)->count());
        });
        $this->assertSame(0, $this->checkoutsCreated());
    }

    public function test_an_order_cancelled_while_stripe_makes_the_page_closes_that_page(): void
    {
        $order = $this->order(checkout: false);
        $this->travel(80)->minutes();

        // 画面を作っている間は、片付けは取り消さずに待つ（作りかけの予約がある）
        $this->stripe->before = function () use ($order): void {
            $this->assertSame('waiting', app(PaymentService::class)->expireIfUnpaid(Transaction::query()->findOrFail($order), now()->subMinutes(10)->toImmutable()));
        };
        $this->inside(fn () => app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($order), 'x', 'y'));
        $this->inside(fn () => $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($order)->status));

        // 画面を作っている間は、運営の取消も断る（作りかけの予約がある）
        $second = $this->order(checkout: false);
        $this->stripe->before = function () use ($second): void {
            try {
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($second), 'operator:test');
                $this->fail('画面を作っている間に運営が取り消せた');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('支払いの画面を開いている途中', $e->getMessage());
            }
            // それでもほかの道で取り消された場合（最後の守り）: 作った画面はすぐ閉じ、閉じた画面として記録に残す（後から支払いが届けば返金が要る支払いになる）
            app(TransactionService::class)->transition(Transaction::query()->findOrFail($second), Phase::Cancel, 'test');
        };
        $this->inside(function () use ($second): void {
            try {
                app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($second), 'x', 'y');
                $this->fail('取り消した注文の画面を払える形で返した');
            } catch (PaymentUnavailableException) {
            }
            $attempt = PaymentAttempt::query()->where('transaction_id', $second)->sole();
            $this->assertSame('expired', $attempt->status);
            $this->assertSame('expired', $this->stripe->sessions[$attempt->provider_ref]['status']);
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($second)->status);
        });
    }

    public function test_orders_that_cannot_be_settled_do_not_block_the_ones_behind_them(): void
    {
        // 1 回に見る数より多く、決まりきらない注文（Stripe がまだ払えると言う）が先頭に溜まっても、後ろの注文に順番が来る（レビュー 4）
        config(['payment.expiry_batch' => 2]);
        $stuck = [$this->order(), $this->order()];
        $this->travel(1)->minutes();
        $behind = $this->order();
        foreach ($stuck as $order) {
            $this->stripe->sessions[$this->ref($order)]['keep_open'] = true;
        }
        $this->travel(80)->minutes();

        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 0 件、支払いを確定 0 件、次の回へ 2 件')->assertSuccessful();
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 1 件')->assertSuccessful();

        $this->inside(function () use ($stuck, $behind): void {
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($behind)->status);
            foreach ($stuck as $order) {
                $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($order)->status);
            }
        });
    }

    public function test_an_operator_cancel_waits_while_the_customer_opens_a_payment_page(): void
    {
        $order = $this->order(checkout: false);
        // 運営の取消が「開いている画面は無い」と見た直後に、お客さんが画面を作り終える（見直しの指摘 1）
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $order): void {
            // 運営の取消が開いている画面と入金待ち（ADR 0058）を読む問い合わせ（closeOpenCheckouts）の直後
            if ($fired || ! str_starts_with($query->sql, 'select * from "payment_attempts"') || ! str_ends_with($query->sql, '"status" in (?, ?)')) {
                return;
            }
            $fired = true;
            app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($order), 'x', 'y');
        });

        $this->inside(function () use ($order): void {
            try {
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($order), 'operator:test');
                $this->fail('お客さんが画面を開いている途中に取り消した');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('支払いの画面を開いている途中', $e->getMessage());
            }
            // 取り消さず、画面は払えるまま
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($order)->status);
            $this->assertSame('open', PaymentAttempt::query()->where('transaction_id', $order)->sole()->status);
        });
        $this->assertTrue($fired);

        // 作りかけの予約（Stripe の答えを待っている）がある間も取り消さない。ただし数分だけ（答えの届かなかった古い予約では待たない）
        $second = $this->order(checkout: false);
        $this->inside(function () use ($second): void {
            CheckoutPlan::create(['transaction_id' => $second, 'attempt' => 1, 'idempotency_key' => "checkout-{$second}-1", 'expires_at' => now()->addMinutes(60)->startOfSecond()]);
            try {
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($second), 'operator:test');
                $this->fail('作りかけの予約があるのに取り消した');
            } catch (TransactionException $e) {
                $this->assertSame(SaleOrderService::CHECKOUT_IN_FLIGHT, $e->getMessage());
            }
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($second)->status);
        });
        $this->travel(4)->minutes();
        $this->inside(function () use ($second): void {
            app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($second), 'operator:test');
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($second)->status);
        });
    }

    public function test_a_refused_cancel_still_records_the_pages_it_closed(): void
    {
        // 運営の取消が古い画面を Stripe で閉じている間に、お客さんが新しい画面を作った: 取消は断るが、閉じた画面は記録でも閉じる
        $order = $this->order();
        $old = $this->ref($order);
        $this->travel(57)->minutes(); // 古い画面は期限まで 5 分を切り、使い回されない

        // 閉じる操作（expire）の直前に、お客さんが新しい画面を作る
        $hook = function (string $method, string $url) use (&$hook, $order): void {
            if ($method === 'post' && str_ends_with($url, '/expire')) {
                app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($order), 'x', 'y');

                return;
            }
            $this->stripe->before = $hook;
        };
        $this->stripe->before = $hook;

        $this->inside(function () use ($order, $old): void {
            try {
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($order), 'operator:test');
                $this->fail('お客さんが新しい画面を作ったのに取り消した');
            } catch (TransactionException $e) {
                $this->assertSame(SaleOrderService::CHECKOUT_IN_FLIGHT, $e->getMessage());
            }
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($order)->status);
            // 閉じた古い画面は記録でも閉じ、新しい画面は払えるまま
            $this->assertSame('expired', PaymentAttempt::query()->where('provider_ref', $old)->sole()->status);
            $this->assertSame('open', PaymentAttempt::query()->where('transaction_id', $order)->where('provider_ref', '<>', $old)->sole()->status);
        });
    }

    public function test_an_order_that_fails_does_not_block_the_ones_behind_it(): void
    {
        // 1 件が例外で落ちても、印を付けて後ろへ回し、ほかの注文に順番が来る（見直しの指摘 3）
        config(['payment.expiry_batch' => 1]);
        $broken = $this->order();
        $this->travel(1)->minutes();
        $behind = $this->order();
        $this->travel(80)->minutes();
        DB::listen(static function (QueryExecuted $query) use ($broken): void {
            if (str_contains($query->sql, 'for update') && str_contains($query->sql, '"transactions"') && in_array($broken, $query->bindings, true)) {
                throw new RuntimeException('わざと落とす');
            }
        });

        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('失敗 1 件')->assertSuccessful();
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 1 件')->assertSuccessful();

        $this->inside(function () use ($broken, $behind): void {
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($behind)->status);
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($broken)->status);
            // 取引ごとに店頭の注文の記録は 1 つ（一意の索引）
            $this->assertNotEmpty(DB::select("select 1 from pg_indexes where indexname = 'checkout_orders_transaction_id_unique'"));
        });
    }

    public function test_a_shop_that_fails_as_a_whole_does_not_stop_the_command(): void
    {
        // 1 店の見回りの最初の問い合わせが落ちても（DB が無い・移行が足りない）、コマンドは落ちずに数える
        $this->order();
        $this->travel(80)->minutes();
        DB::listen(static function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'expiry_checked_at')) {
                throw new RuntimeException('わざと落とす');
            }
        });

        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('失敗 1 件')->assertSuccessful();
    }

    public function test_an_old_order_cannot_open_new_payment_pages_forever(): void
    {
        // 押し続けて新しい画面を作り続け、在庫を押さえ続けられない（注文から決めた時間まで。見直しの指摘 8）
        $limit = (int) config('payment.order_payable_minutes');
        $order = $this->order(checkout: false);
        $this->travel($limit - 10)->minutes();
        $page = $this->inside(fn () => app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($order), 'x', 'y'));

        // 上限を過ぎても、まだ払える画面には戻れる
        $this->travel(11)->minutes();
        $this->assertSame($page->ref, $this->inside(fn () => app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($order), 'x', 'y'))->ref);
        $this->assertSame(1, $this->checkoutsCreated());

        // 画面の期限が近づき、新しい画面が要るときは断る（期限の文で）
        $this->travel(50)->minutes();
        $this->inside(function () use ($order): void {
            try {
                app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($order), 'x', 'y');
                $this->fail('古い注文に新しい支払いの画面を作った');
            } catch (PaymentUnavailableException $e) {
                $this->assertSame(PaymentService::ORDER_EXPIRED, $e->getMessage());
            }
        });
        $this->assertSame(1, $this->checkoutsCreated());
        $this->travel(20)->minutes();
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 1 件')->assertSuccessful();
    }

    public function test_the_customer_sees_that_the_order_was_cancelled(): void
    {
        $token = null;
        $send = function (string $method, string $path, array $data = []) use (&$token) {
            if ($token !== null) {
                $this->withCookie(CartCookie::NAME, $token);
            }
            $response = $method === 'GET' ? $this->get("http://exa.localhost{$path}") : $this->post("http://exa.localhost{$path}", $data);
            tenancy()->end();
            $token = $response->getCookie(CartCookie::NAME)?->getValue() ?? $token;

            return $response;
        };

        $send('POST', '/cart/items', ['listing_id' => $this->listing, 'quantity' => '1']);
        $send('POST', '/checkout', ['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => ''], 'same_as_buyer' => '1']);
        preg_match('/name="order_key" value="([^"]+)"/', (string) $send('GET', '/checkout/confirm')->getContent(), $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', (string) $send('GET', '/checkout/confirm')->getContent(), $fingerprint);
        $send('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertStatus(303);

        $this->travel(90)->minutes();
        $this->artisan('storefront:expire-unpaid-orders')->assertSuccessful();

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $html = (string) $send('GET', '/checkout/complete')->assertOk()->getContent();
            $this->assertStringContainsString('data-test="order-cancelled"', $html, $theme);
            $this->assertStringContainsString('お支払いの期限が過ぎたため', $html, $theme);
            $this->assertStringNotContainsString('data-test="pay"', $html, $theme);
        }
        // 取り消した注文は払いに進めない
        $send('POST', '/checkout/pay')->assertRedirect('http://exa.localhost/checkout/complete');
        $this->assertStringContainsString('現在お支払いに進めません', (string) $send('GET', '/checkout/complete')->getContent());
    }

    /**
     * 店頭の注文を作り（カート → 確定）、支払いの画面を用意する
     */
    private function order(bool $checkout = true): string
    {
        return $this->inside(function () use ($checkout): string {
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->listing)->value('id'), 1);
            $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null]);
            $this->assertNotNull($input);
            $sale = app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), CheckoutSummary::fingerprintOf($carts->view($cart)));

            if ($checkout) {
                app(PaymentService::class)->startCheckout($sale, 'x', 'y');
            }

            return $sale->id;
        });
    }

    /**
     * 決まった問い合わせ（SQL に $needle を含む）が最初に終わった直後に、1 回だけ $work を割り込ませる。同時の操作の順番を決めて起こすため
     */
    private function afterFirstQuery(string $needle, Closure $work): void
    {
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $needle, $work): void {
            if ($fired || ! str_contains($query->sql, 'from "payment_attempts"') || ! str_contains($query->sql, $needle)) {
                return;
            }
            $fired = true;
            $work();
        });
    }

    private function checkoutsCreated(): int
    {
        return count(array_filter($this->stripe->requests, static fn (array $r): bool => $r['method'] === 'post' && str_ends_with($r['url'], '/v1/checkout/sessions')));
    }

    private function ref(string $sale): string
    {
        return $this->inside(fn (): string => PaymentAttempt::query()->where('transaction_id', $sale)->sole()->provider_ref);
    }

    private function reserved(): int
    {
        return $this->inside(fn (): int => (int) InventoryStock::query()->where('listing_id', $this->listing)->sum('reserved'));
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
