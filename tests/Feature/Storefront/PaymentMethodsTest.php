<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Admin\Http\Presenters\TransactionPresenter;
use App\Core\Discount\Models\CouponRedemption;
use App\Core\Discount\Services\CouponService;
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
use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\Models\CheckoutPlan;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Payment\Models\WebhookEvent;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Payment\Services\PaymentService;
use App\Core\Payment\Stripe\StripeCheckout;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Orders\OrderMail;
use App\Storefront\Orders\OrderReader;
use App\Storefront\Orders\SendOrderMail;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 支払いの手段を増やす（ADR 0058）。店が選んだ手段だけを Stripe の支払いの画面に出し、
 * コンビニ払いは「入金待ち」→ 入金の知らせで確定・払われなかった知らせか期限の後の見回りで取り消す（在庫とクーポンの数を戻す）。
 * 取り消した後に届いた入金は返金の道へ、同じ知らせの二重・順の入れ替わりは 1 回だけ効く
 */
final class PaymentMethodsTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const string BODY = 'FakeForTestsOnly000';

    private const string SHOP = 'pmt';

    private FakeStripe $stripe;

    private string $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->stripe = FakeStripe::install();
        $this->tenant(self::SHOP);
        $this->listing = $this->inside(self::SHOP, function (): string {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => $this->webhookSecret()]);
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 10);
            app(CouponService::class)->create(['code' => 'OFF100', 'kind' => 'fixed', 'value' => 100, 'name' => 'テスト'], 'test');

            return $listing->id;
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_the_shop_chooses_methods_and_the_checkout_offers_only_those_the_order_can_use(): void
    {
        // 何も選んでいない店はカードだけ（今までと同じ頼み方）
        $card = $this->order();
        $params = $this->checkoutParams();
        // 2026-09-30 の API から手段は名前で渡せない: カードのほかを外す
        $this->assertArrayNotHasKey('payment_method_types', $params);
        $this->assertSame(['konbini', 'paypay', 'customer_balance', 'alipay', 'wechat_pay'], $params['excluded_payment_method_types']);
        $this->assertArrayNotHasKey('payment_method_options', $params);
        $this->inside(self::SHOP, fn () => $this->assertSame('card', PaymentAttempt::query()->where('transaction_id', $card)->sole()->methods));

        $this->inside(self::SHOP, function (): void {
            $settings = app(PaymentMethodSettings::class);
            $settings->update(['konbini' => true, 'paypay' => true, 'konbini_days' => 7], 'operator:test');
            // 同じ値で保存しても記録を増やさない
            $settings->update(['konbini' => '1', 'paypay' => '1', 'konbini_days' => '7'], 'operator:test');
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.methods_updated')->count());

            // 額で使える手段だけ（コンビニ払いは 120 円〜30 万円、PayPay は 50 円〜100 万円）。カードを預ける支払いはカードだけ
            $this->assertSame(['card', 'paypay'], $settings->forCheckout(100)->types);
            $this->assertSame(['card', 'konbini', 'paypay'], $settings->forCheckout(120)->types);
            $this->assertSame(['card', 'paypay'], $settings->forCheckout(300_001)->types);
            $this->assertSame(['card'], $settings->forCheckout(1_000_001)->types);
            $this->assertSame(['card'], $settings->forCheckout(4400, savingCard: true)->types);

            // 期限の日数は 1〜30 日
            foreach ([0, 31, 'x'] as $days) {
                try {
                    $settings->update(['konbini' => true, 'paypay' => false, 'konbini_days' => $days], 'operator:test');
                    $this->fail("{$days} 日を受けた");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('konbini_days', $e->errors());
                }
            }
        });

        // 支払いの画面には選んだ手段を名前で渡し、コンビニ払いの期限の日数も渡す。頼む前の予約と記録にも同じ中身
        $sale = $this->order();
        $params = $this->checkoutParams();
        $this->assertArrayNotHasKey('payment_method_types', $params);
        // 使う手段は外さず、核が扱えない手段だけを外す
        $this->assertSame(['customer_balance', 'alipay', 'wechat_pay'], $params['excluded_payment_method_types']);
        $this->assertEquals(['konbini' => ['expires_after_days' => 7]], $params['payment_method_options']);
        $this->inside(self::SHOP, function () use ($sale): void {
            $this->assertSame('card,konbini:7,paypay', CheckoutPlan::query()->where('transaction_id', $sale)->sole()->methods);
            $this->assertSame('card,konbini:7,paypay', PaymentAttempt::query()->where('transaction_id', $sale)->sole()->methods);
        });
    }

    public function test_stripe_connected_shops_cannot_offer_paypay(): void
    {
        config(['payment.stripe_platform_secret' => implode('_', ['sk', 'test', self::BODY.'platform'])]);

        $this->inside(self::SHOP, function (): void {
            $settings = app(PaymentMethodSettings::class);
            $settings->update(['konbini' => true, 'paypay' => true, 'konbini_days' => 3], 'operator:test');
            // 後から Stripe へ接続した: 支払いの画面には PayPay を出さず、選び直しも断る
            PaymentGateway::create(['provider' => 'stripe_connect', 'mode' => 'test', 'key_hint' => 'conn', 'connect_status' => 'active', 'credentials' => ['account_id' => 'acct_test_connected']]);

            $this->assertSame(['card', 'konbini'], $settings->forCheckout(4400)->types);
            $this->assertFalse($settings->status()['paypay_available']);

            try {
                $settings->update(['konbini' => true, 'paypay' => true, 'konbini_days' => 3], 'operator:test');
                $this->fail('接続した店で PayPay を受けた');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('paypay', $e->errors());
            }
        });
    }

    public function test_the_final_confirmation_states_the_methods_and_the_timing(): void
    {
        $this->inside(self::SHOP, function (): void {
            $cart = $this->cart();
            $view = app(CartService::class)->view($cart);
            $input = $this->input();
            $card = new CheckoutSummary($view, $input, null, null, methods: CheckoutMethods::cardOnly());
            $this->assertSame('クレジットカード（次の決済画面でお支払い）', $card->paymentMethod());
            $this->assertSame('ご注文の確定と同時にお支払いいただきます。', $card->paymentTiming());

            $konbini = new CheckoutSummary($view, $input, null, null, methods: new CheckoutMethods(['card', 'konbini', 'paypay'], 5));
            $this->assertSame('クレジットカード・コンビニ払い・PayPay（次の決済画面でお選びいただけます）', $konbini->paymentMethod());
            $this->assertStringContainsString('コンビニ払いは、ご注文から 5 日後の 23:59 までにコンビニでお支払いください', $konbini->paymentTiming());
        });
    }

    public function test_a_konbini_order_waits_for_the_money_then_the_payment_confirms_it_once(): void
    {
        Queue::fake();
        $this->enableKonbini();
        $sale = $this->order(coupon: 'OFF100');
        $ref = $this->ref($sale);
        $due = now()->addDays(3)->getTimestamp();
        $intent = $this->stripe->konbiniVoucher($ref, $due);

        // 払込票が出た（支払いの画面は終わり・まだ払われていない）: 入金待ち。注文・在庫・クーポンの数はそのまま
        $completed = $this->sessionEvent('evt_k1', 'checkout.session.completed', $ref, $sale, 4300, 'unpaid', $intent);
        $this->deliver($completed)->assertOk();
        $this->inside(self::SHOP, function () use ($sale, $due): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale)->sole();
            $this->assertSame(['awaiting', 'konbini', $due], [$attempt->status, $attempt->method, $attempt->pay_by?->getTimestamp()]);
            // 払込票の画面は暗号化して置く（DB の写しから払い方を見られない）
            $this->assertSame('https://payment.stripe.com/konbini/voucher/test_voucher', $attempt->instructions_url);
            $this->assertStringNotContainsString('stripe.com', (string) DB::table('payment_attempts')->where('id', $attempt->id)->value('instructions_url'));
            $this->assertSame(Phase::Agreement, Transaction::query()->findOrFail($sale)->phase);
            $this->assertSame('reserved', CouponRedemption::query()->where('transaction_id', $sale)->sole()->status);
            $event = DomainEvent::query()->where('type', 'payment.awaiting')->sole();
            $this->assertSame(['konbini', 'webhook:stripe'], [$event->payload['method'], $event->actor]);
        });
        $this->assertSame(1, $this->reserved());
        Queue::assertPushed(SendOrderMail::class, fn (SendOrderMail $job): bool => $job->kind === 'order_awaiting_payment' && $job->transactionId === $sale);

        // 「お支払いへ進む」をもう一度押しても、新しい画面は作らず払込票の画面へ（二重に払われないように）
        $before = count($this->stripe->sessions);
        $session = $this->inside(self::SHOP, fn () => app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($sale), 'x', 'y'));
        $this->assertSame('https://payment.stripe.com/konbini/voucher/test_voucher', $session->url);
        $this->assertCount($before, $this->stripe->sessions);

        // お客さんの画面とメールに「入金待ち」と期限・払込票の画面
        $order = $this->inside(self::SHOP, fn () => app(OrderReader::class)->read(Transaction::query()->findOrFail($sale)));
        $this->assertTrue($order->awaitingPayment());
        $mail = new OrderMail('order_awaiting_payment', '店', $order, 'https://pmt.localhost/orders/x');
        $mail->assertSeeInText('コンビニでのお支払いをお待ちしています');
        $mail->assertSeeInText('https://payment.stripe.com/konbini/voucher/test_voucher');
        $mail->assertSeeInText(now()->addDays(3)->setTimezone('Asia/Tokyo')->format('Y/m/d H:i'));

        // ご注文の状況（どのテーマでも核の部品が描く）: 入金待ちと期限、払込票の画面へのリンク（次の画面へ URL を渡さない）
        $link = $this->inside(self::SHOP, fn (): string => OrderReader::link(Transaction::query()->findOrFail($sale), 'http://'.self::SHOP.'.localhost'));

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->inside(self::SHOP, fn () => Tenant::query()->findOrFail(self::SHOP)->forceFill(['theme' => $theme])->save());
            $html = (string) $this->get($link)->assertOk()->getContent();
            tenancy()->end();
            $this->assertStringContainsString('data-test="payment-awaiting"', $html, $theme);
            $this->assertStringContainsString('href="https://payment.stripe.com/konbini/voucher/test_voucher" target="_blank" rel="noopener noreferrer"', $html, $theme);
        }

        // 同じ知らせの送り直しと、払込票の期限の前の見回りは何もしない
        $this->deliver($completed)->assertOk();
        $this->travel(2)->days();
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 0 件')->assertSuccessful();

        // お店で払われた: 確定する（入金済み・クーポンは使った）
        $this->stripe->intents[$intent]['status'] = 'succeeded';
        $this->deliver($this->sessionEvent('evt_k2', 'checkout.session.async_payment_succeeded', $ref, $sale, 4300, 'paid', $intent))->assertOk();
        // 順が入れ替わって遅れて届いた「払込票が出た」の知らせ・二度目の「払われた」は、何もしない
        $this->deliver($this->sessionEvent('evt_k3', 'checkout.session.completed', $ref, $sale, 4300, 'unpaid', $intent))->assertOk();
        $this->deliver($this->sessionEvent('evt_k4', 'checkout.session.async_payment_succeeded', $ref, $sale, 4300, 'paid', $intent))->assertOk();
        $this->deliver($this->sessionEvent('evt_k5', 'checkout.session.async_payment_failed', $ref, $sale, 4300, 'unpaid', $intent))->assertOk();

        $this->inside(self::SHOP, function () use ($sale, $intent): void {
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($sale)->phase);
            $this->assertSame($intent, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Payment)->sole()->reference);
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale)->sole();
            $this->assertSame(['paid', 'konbini'], [$attempt->status, $attempt->method]);
            $this->assertSame('used', CouponRedemption::query()->where('transaction_id', $sale)->sole()->status);
            $this->assertEqualsCanonicalizing(['awaiting', 'paid', 'already_paid', 'already_paid', 'ignored'], WebhookEvent::query()->pluck('outcome')->all());
            $this->assertSame('コンビニ払い', app(OrderReader::class)->read(Transaction::query()->findOrFail($sale))->payment?->label);
        });
        // 払われた後のご注文の状況は「お支払いの方法　コンビニ払い」（払込票のリンクは出さない）
        $html = (string) $this->get($link)->assertOk()->getContent();
        tenancy()->end();
        $this->assertStringContainsString('お支払い方法　コンビニ払い', $html);
        $this->assertStringNotContainsString('konbini/voucher', $html);
        Queue::assertPushed(SendOrderMail::class, fn (SendOrderMail $job): bool => $job->kind === 'order_paid' && $job->transactionId === $sale);
    }

    public function test_an_unpaid_konbini_order_is_cancelled_and_a_payment_after_that_goes_to_the_refund_path(): void
    {
        Queue::fake();
        $this->enableKonbini();
        $sale = $this->order(coupon: 'OFF100');
        $ref = $this->ref($sale);
        $intent = $this->stripe->konbiniVoucher($ref, now()->addDays(3)->getTimestamp());
        $this->deliver($this->sessionEvent('evt_f1', 'checkout.session.completed', $ref, $sale, 4300, 'unpaid', $intent))->assertOk();

        // 期限が過ぎた（Stripe の「払われなかった」）: 取り消し、在庫とクーポンの数を戻し、お客さんへ知らせる
        $this->stripe->intents[$intent]['status'] = 'requires_payment_method';
        $failed = $this->sessionEvent('evt_f2', 'checkout.session.async_payment_failed', $ref, $sale, 4300, 'unpaid', $intent);
        $this->deliver($failed)->assertOk();
        $this->deliver($failed)->assertOk();

        $this->inside(self::SHOP, function () use ($sale): void {
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame('failed', PaymentAttempt::query()->where('transaction_id', $sale)->sole()->status);
            $this->assertSame('released', CouponRedemption::query()->where('transaction_id', $sale)->sole()->status);
            $this->assertEquals(['attempts_expired' => 0, 'reason' => 'payment_lapsed'], DomainEvent::query()->where('type', 'payment.order_expired')->sole()->payload);
            $this->assertSame(['awaiting', 'cancelled'], WebhookEvent::query()->orderBy('created_at')->pluck('outcome')->all());
        });
        $this->assertSame(0, $this->reserved());
        Queue::assertPushed(SendOrderMail::class, fn (SendOrderMail $job): bool => $job->kind === 'order_payment_lapsed' && $job->transactionId === $sale);

        // 取り消した後に入金が届いた（お店のレジで期限の間際に払った）: 支払いにせず、返金が要る支払いとして残す
        $this->deliver($this->sessionEvent('evt_f3', 'checkout.session.async_payment_succeeded', $ref, $sale, 4300, 'paid', $intent))->assertOk();
        $this->inside(self::SHOP, function () use ($sale, $intent): void {
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale)->where('type', JournalType::Payment)->count());
            $stray = StrayPayment::query()->where('transaction_id', $sale)->sole();
            $this->assertSame(['late', 4300, $intent, 'pending'], [$stray->reason, $stray->amount, $stray->payment_ref, $stray->status]);
        });
    }

    public function test_the_sweeper_waits_for_the_due_date_then_cancels_the_voucher_and_the_order(): void
    {
        Queue::fake();
        $this->enableKonbini();
        $sale = $this->order(coupon: 'OFF100');
        $busy = $this->order();
        $ref = $this->ref($sale);
        $busyRef = $this->ref($busy);
        $intent = $this->stripe->konbiniVoucher($ref, now()->addDays(3)->getTimestamp());
        $busyIntent = $this->stripe->konbiniVoucher($busyRef, now()->addDays(3)->getTimestamp());
        $this->deliver($this->sessionEvent('evt_s1', 'checkout.session.completed', $ref, $sale, 4300, 'unpaid', $intent))->assertOk();
        $this->deliver($this->sessionEvent('evt_s2', 'checkout.session.completed', $busyRef, $busy, 4400, 'unpaid', $busyIntent))->assertOk();

        // 期限の後の猶予（24 時間）の中は、Stripe に問い合わせもしない
        $this->travel(3)->days();
        $this->travel(23)->hours();
        $asked = count($this->stripe->requests);
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 0 件')->assertSuccessful();
        $this->assertCount($asked, $this->stripe->requests);

        // 猶予の後も「払われなかった」の知らせが届かない: 払込票を Stripe で取り消してから、注文を取り消す。
        // お店で払っている途中（取り消せない）なら待つ
        $this->travel(2)->hours();
        $this->stripe->intents[$busyIntent]['status'] = 'processing';
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 1 件')->assertSuccessful();

        $this->assertSame([$intent], $this->stripe->cancelledIntents);
        $this->inside(self::SHOP, function () use ($sale, $busy): void {
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame('expired', PaymentAttempt::query()->where('transaction_id', $sale)->sole()->status);
            $this->assertSame('released', CouponRedemption::query()->where('transaction_id', $sale)->sole()->status);
            $this->assertEquals(['attempts_expired' => 1, 'reason' => 'payment_lapsed'], DomainEvent::query()->where('type', 'payment.order_expired')->sole()->payload);
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($busy)->status);
            $this->assertSame('awaiting', PaymentAttempt::query()->where('transaction_id', $busy)->sole()->status);
        });
        Queue::assertPushed(SendOrderMail::class, fn (SendOrderMail $job): bool => $job->kind === 'order_payment_lapsed' && $job->transactionId === $sale);

        // お店で払われていた（知らせが届かなかった）: 確定する
        $this->stripe->sessions[$busyRef]['payment_status'] = 'paid';
        $this->stripe->intents[$busyIntent]['status'] = 'succeeded';
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('支払いを確定 1 件')->assertSuccessful();
        $this->inside(self::SHOP, fn () => $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($busy)->phase));
    }

    public function test_the_sweeper_keeps_an_order_whose_voucher_notice_was_missed(): void
    {
        Queue::fake();
        $this->enableKonbini();
        $sale = $this->order();
        $ref = $this->ref($sale);
        $due = now()->addDays(3)->getTimestamp();
        // 払込票は出たが、その知らせが届かなかった。支払いの画面の期限と猶予が過ぎた
        $this->stripe->konbiniVoucher($ref, $due);
        $this->travel(80)->minutes();

        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 0 件')->assertSuccessful();

        $this->inside(self::SHOP, function () use ($sale, $due): void {
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($sale)->status);
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale)->sole();
            $this->assertSame(['awaiting', $due], [$attempt->status, $attempt->pay_by?->getTimestamp()]);
        });
        $this->assertSame(1, $this->reserved());
        Queue::assertPushed(SendOrderMail::class, fn (SendOrderMail $job): bool => $job->kind === 'order_awaiting_payment');
    }

    public function test_cancelling_an_awaiting_order_cancels_the_voucher_first(): void
    {
        Queue::fake();
        $this->enableKonbini();
        $sale = $this->order();
        $paid = $this->order();
        $intent = $this->stripe->konbiniVoucher($this->ref($sale), now()->addDays(3)->getTimestamp());
        $paidIntent = $this->stripe->konbiniVoucher($this->ref($paid), now()->addDays(3)->getTimestamp());
        $this->deliver($this->sessionEvent('evt_c1', 'checkout.session.completed', $this->ref($sale), $sale, 4400, 'unpaid', $intent))->assertOk();
        $this->deliver($this->sessionEvent('evt_c2', 'checkout.session.completed', $this->ref($paid), $paid, 4400, 'unpaid', $paidIntent))->assertOk();

        // 店の人が取り消す: 払込票を Stripe で使えなくしてから取り消す（取り消した注文に後で払われないように）
        $this->inside(self::SHOP, fn () => app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale), 'operator:test'));
        $this->assertSame([$intent], $this->stripe->cancelledIntents);
        $this->inside(self::SHOP, function () use ($sale): void {
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($sale)->status);
            $this->assertSame('expired', PaymentAttempt::query()->where('transaction_id', $sale)->sole()->status);
        });

        // もうお店で払われていた（知らせはまだ）: 取り消さない
        $this->stripe->sessions[$this->ref($paid)]['payment_status'] = 'paid';
        $this->stripe->intents[$paidIntent]['status'] = 'succeeded';
        $this->inside(self::SHOP, function () use ($paid): void {
            try {
                app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($paid), 'operator:test');
                $this->fail('払われた注文を払っていない注文として取り消した');
            } catch (PaymentUnavailableException) {
                $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($paid)->status);
            }
        });
    }

    public function test_a_card_payment_on_another_page_closes_the_voucher_left_on_the_same_order(): void
    {
        Queue::fake();
        $this->enableKonbini();
        $sale = $this->order();
        $first = $this->ref($sale);
        // 1 つ目の画面でコンビニ払いを選び、払込票が出た（知らせはまだ）。画面の期限の間際に、お客さんが「お支払いへ進む」をもう一度押した
        $voucher = $this->stripe->konbiniVoucher($first, now()->addDays(3)->getTimestamp());
        $expires = $this->inside(self::SHOP, fn () => PaymentAttempt::query()->where('provider_ref', $first)->sole()->expires_at);
        $this->travelTo($expires->subMinutes(4));
        $this->inside(self::SHOP, fn () => app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($sale), 'x', 'y'));
        $second = $this->inside(self::SHOP, fn (): string => PaymentAttempt::query()->where('transaction_id', $sale)->where('provider_ref', '!=', $first)->sole()->provider_ref);
        // 払込票の知らせが先に届いて入金待ちに。その後、2 つ目の画面でカードで払われた
        $this->deliver($this->sessionEvent('evt_l1', 'checkout.session.completed', $first, $sale, 4400, 'unpaid', $voucher))->assertOk();
        $card = $this->stripe->paidWith($second, 'card');
        $this->deliver($this->sessionEvent('evt_l2', 'checkout.session.completed', $second, $sale, 4400, 'paid', $card))->assertOk();

        // 生きたまま残った払込票を Stripe で取り消した（お店で払われて二重にならない）。記録も閉じた
        $this->assertSame([$voucher], $this->stripe->cancelledIntents);
        $this->inside(self::SHOP, function () use ($sale, $first, $second): void {
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($sale)->phase);
            $this->assertSame('failed', PaymentAttempt::query()->where('provider_ref', $first)->sole()->status);
            $this->assertSame('paid', PaymentAttempt::query()->where('provider_ref', $second)->sole()->status);
            $this->assertSame(['failed'], DomainEvent::query()->where('type', 'payment.leftover_closed')->get()->map(fn (DomainEvent $e) => $e->payload['status'])->all());
        });
        // 遅れて届いた払込票の「払われなかった」の知らせは、払われた注文を取り消さない
        $this->deliver($this->sessionEvent('evt_l3', 'checkout.session.async_payment_failed', $first, $sale, 4400, 'unpaid', $voucher))->assertOk();
        $this->inside(self::SHOP, fn () => $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($sale)->status));
    }

    public function test_the_sweeper_closes_a_voucher_that_could_not_be_cancelled_when_the_order_was_paid(): void
    {
        Queue::fake();
        $this->enableKonbini();
        $sale = $this->order();
        $first = $this->ref($sale);
        $voucher = $this->stripe->konbiniVoucher($first, now()->addDays(3)->getTimestamp());
        $expires = $this->inside(self::SHOP, fn () => PaymentAttempt::query()->where('provider_ref', $first)->sole()->expires_at);
        $this->travelTo($expires->subMinutes(4));
        $this->inside(self::SHOP, fn () => app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($sale), 'x', 'y'));
        $second = $this->inside(self::SHOP, fn (): string => PaymentAttempt::query()->where('transaction_id', $sale)->where('provider_ref', '!=', $first)->sole()->provider_ref);
        $this->deliver($this->sessionEvent('evt_s1', 'checkout.session.completed', $first, $sale, 4400, 'unpaid', $voucher))->assertOk();

        // カードで払われた時、払込票はお店のレジで処理中だった（取り消せない）: 払込票は残し、カードの支払いは確定する
        $this->stripe->intents[$voucher]['status'] = 'processing';
        $card = $this->stripe->paidWith($second, 'card');
        $this->deliver($this->sessionEvent('evt_s2', 'checkout.session.completed', $second, $sale, 4400, 'paid', $card))->assertOk();
        $this->assertSame([], $this->stripe->cancelledIntents);
        $this->inside(self::SHOP, function () use ($sale, $first): void {
            $this->assertSame(Phase::Payment, Transaction::query()->findOrFail($sale)->phase);
            $this->assertSame('awaiting', PaymentAttempt::query()->where('provider_ref', $first)->sole()->status);
        });

        // レジの処理が戻った（払われなかった）: 次の見回りで取り消す。もう一度の見回りは何もしない
        $this->stripe->intents[$voucher]['status'] = 'requires_action';
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('払われた注文に残った支払いの画面を閉じた 1 件')->assertSuccessful();
        $this->assertSame([$voucher], $this->stripe->cancelledIntents);
        $this->inside(self::SHOP, fn () => $this->assertSame('failed', PaymentAttempt::query()->where('provider_ref', $first)->sole()->status));
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('払われた注文に残った支払いの画面を閉じた 0 件')->assertSuccessful();
        $this->assertSame([$voucher], $this->stripe->cancelledIntents);

        // 払われていない注文の払込票は、この見回りでは触らない（払いの期限まで待つ）
        $other = $this->order();
        $otherVoucher = $this->stripe->konbiniVoucher($this->ref($other), now()->addDays(3)->getTimestamp());
        $this->deliver($this->sessionEvent('evt_s3', 'checkout.session.completed', $this->ref($other), $other, 4400, 'unpaid', $otherVoucher))->assertOk();
        $this->artisan('storefront:expire-unpaid-orders')->assertSuccessful();
        $this->assertNotContains($otherVoucher, $this->stripe->cancelledIntents);
        // 直に呼んでも、払われていない注文の払込票は閉じない
        $this->assertSame(0, $this->inside(self::SHOP, fn (): int => app(PaymentService::class)->closeLeftoversOfPaid($other)));
        $this->assertNotContains($otherVoucher, $this->stripe->cancelledIntents);
        $this->inside(self::SHOP, fn () => $this->assertSame('awaiting', PaymentAttempt::query()->where('transaction_id', $other)->sole()->status));
    }

    public function test_the_paid_method_is_recorded_only_when_more_than_cards_were_offered(): void
    {
        // カードだけの店: 手段を問い合わせない（今までと同じ）
        $card = $this->order();
        $before = count($this->stripe->requests);
        $this->deliver($this->sessionEvent('evt_p1', 'checkout.session.completed', $this->ref($card), $card, 4400, 'paid', 'pi_test_card'))->assertOk();
        $this->assertCount($before, $this->stripe->requests);
        $this->inside(self::SHOP, function () use ($card): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $card)->sole();
            $this->assertSame([null, 'card'], [$attempt->method, $attempt->paymentMethod()]);
            $this->assertArrayNotHasKey('method', DomainEvent::query()->where('type', 'payment.received')->sole()->payload);
        });

        // PayPay と、カードの中の Apple Pay
        $this->inside(self::SHOP, fn () => app(PaymentMethodSettings::class)->update(['konbini' => false, 'paypay' => true, 'konbini_days' => 3], 'operator:test'));
        $paypay = $this->order();
        $apple = $this->order();
        $paypayIntent = $this->stripe->paidWith($this->ref($paypay), 'paypay');
        $appleIntent = $this->stripe->paidWith($this->ref($apple), 'card', 'apple_pay');
        $this->deliver($this->sessionEvent('evt_p2', 'checkout.session.completed', $this->ref($paypay), $paypay, 4400, 'paid', $paypayIntent))->assertOk();
        $this->deliver($this->sessionEvent('evt_p3', 'checkout.session.completed', $this->ref($apple), $apple, 4400, 'paid', $appleIntent))->assertOk();

        $this->inside(self::SHOP, function () use ($paypay, $apple): void {
            $this->assertSame('paypay', PaymentAttempt::query()->where('transaction_id', $paypay)->sole()->method);
            $this->assertSame('apple_pay', PaymentAttempt::query()->where('transaction_id', $apple)->sole()->method);
            $this->assertSame('PayPay', app(OrderReader::class)->read(Transaction::query()->findOrFail($paypay))->payment?->label);
            $this->assertSame('paypay', DomainEvent::query()->where('type', 'payment.received')->where('aggregate_id', $paypay)->sole()->payload['method']);
        });

        // 管理画面の注文にも手段（API の形）
        $this->inside(self::SHOP, function () use ($paypay): void {
            $presented = app(TransactionPresenter::class)->present(Transaction::query()->findOrFail($paypay));
            $this->assertSame(['method' => 'paypay', 'method_label' => 'PayPay', 'awaiting' => false, 'pay_by' => null, 'direct' => false, 'on_delivery' => false, 'test' => true], $presented['payment']);
        });
    }

    public function test_the_sweeper_moves_a_leftover_it_cannot_close_behind_the_others(): void
    {
        Queue::fake();
        $this->enableKonbini();
        // 1 回に 1 件だけ見る。先に払われた注文の払込票はレジで処理中のまま（閉じられない）、後の注文の払込票は閉じられる
        config(['payment.expiry_batch' => 1]);
        [$stuck, $stuckVoucher] = $this->paidWithLeftoverVoucher('a');
        [$later, $laterVoucher] = $this->paidWithLeftoverVoucher('b');
        $this->stripe->intents[$laterVoucher]['status'] = 'requires_action';

        // 1 回目: まだ見ていない古い方（閉じられない）を見て、印を付けて後ろへ回す
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('払われた注文に残った支払いの画面を閉じた 0 件')->assertSuccessful();
        $this->inside(self::SHOP, fn () => $this->assertNotNull(PaymentAttempt::query()->where('transaction_id', $stuck)->where('status', 'awaiting')->sole()->leftover_checked_at));
        // 2 回目: 閉じられないものに塞がれず、後ろの注文の払込票を閉じる
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('払われた注文に残った支払いの画面を閉じた 1 件')->assertSuccessful();
        $this->assertSame([$laterVoucher], $this->stripe->cancelledIntents);
        $this->inside(self::SHOP, function () use ($stuck, $later): void {
            $this->assertSame('failed', PaymentAttempt::query()->where('transaction_id', $later)->where('kind', 'checkout')->where('status', '!=', 'paid')->sole()->status);
            $this->assertSame('awaiting', PaymentAttempt::query()->where('transaction_id', $stuck)->where('kind', 'checkout')->where('status', '!=', 'paid')->sole()->status);
        });
        $this->assertNotContains($stuckVoucher, $this->stripe->cancelledIntents);
    }

    public function test_a_page_that_saves_the_card_for_subscriptions_offers_only_cards(): void
    {
        $this->enableKonbini();
        $this->inside(self::SHOP, function (): void {
            $view = app(CartService::class)->view($cart = $this->cart());
            // 指紋には確かめの画面で見せた支払いの手段も入る（確かめの画面と同じ決め方で）
            $sale = app(PlaceOrder::class)->place($cart, $this->input(), rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), CheckoutSummary::fingerprintOf($view, app(PaymentMethodSettings::class)->forCheckout($view->total())));
            $credentials = app(PaymentGatewayService::class)->credentials();
            $this->assertNotNull($credentials);
            // 定期便の 1 回目（カードを預ける）: 店がコンビニ払いを選んでいても、カードだけ（コンビニ払いと PayPay は預けられない）
            app(StripeCheckout::class)->create($credentials, $sale, 'x', 'y', 'key-subscription', now()->addHour()->toImmutable(), 'cus_test_1', new CheckoutMethods(['card', 'konbini'], 5));
        });
        $params = $this->checkoutParams();
        // 2026-09-30 の API から手段は名前で渡せない: カードのほかを外す
        $this->assertArrayNotHasKey('payment_method_types', $params);
        $this->assertSame(['konbini', 'paypay', 'customer_balance', 'alipay', 'wechat_pay'], $params['excluded_payment_method_types']);
        $this->assertArrayNotHasKey('payment_method_options', $params);
        $this->assertSame('off_session', $params['payment_intent_data']['setup_future_usage']);
    }

    public function test_notices_that_do_not_match_or_target_a_card_only_page_do_not_start_waiting(): void
    {
        $this->enableKonbini();
        $sale = $this->order();
        $ref = $this->ref($sale);
        $intent = $this->stripe->konbiniVoucher($ref, now()->addDays(3)->getTimestamp());

        // 金額・通貨・取引の違う「払込票が出た」は信じない
        $this->deliver($this->sessionEvent('evt_m1', 'checkout.session.completed', $ref, $sale, 1, 'unpaid', $intent))->assertOk();
        $this->deliver($this->sessionEvent('evt_m2', 'checkout.session.completed', $ref, $sale, 4400, 'unpaid', $intent, currency: 'usd'))->assertOk();
        $this->deliver($this->sessionEvent('evt_m3', 'checkout.session.completed', $ref, '01aaaaaaaaaaaaaaaaaaaaaaaa', 4400, 'unpaid', $intent))->assertOk();

        $this->inside(self::SHOP, function () use ($sale): void {
            $this->assertSame('open', PaymentAttempt::query()->where('transaction_id', $sale)->sole()->status);
            $this->assertSame(3, DomainEvent::query()->where('type', 'payment.mismatch')->count());
        });
    }

    /**
     * 払込票が出た後に別の画面のカードで払われ、払込票がレジで処理中のまま残った注文（取り消せずに残る）。[注文, 払込票の支払い]
     *
     * @return array{string, string}
     */
    private function paidWithLeftoverVoucher(string $key): array
    {
        $sale = $this->order();
        $first = $this->ref($sale);
        $voucher = $this->stripe->konbiniVoucher($first, now()->addDays(3)->getTimestamp());
        $expires = $this->inside(self::SHOP, fn () => PaymentAttempt::query()->where('provider_ref', $first)->sole()->expires_at);
        $this->travelTo($expires->subMinutes(4));
        $this->inside(self::SHOP, fn () => app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($sale), 'x', 'y'));
        $second = $this->inside(self::SHOP, fn (): string => PaymentAttempt::query()->where('transaction_id', $sale)->where('provider_ref', '!=', $first)->sole()->provider_ref);
        $this->deliver($this->sessionEvent("evt_{$key}1", 'checkout.session.completed', $first, $sale, 4400, 'unpaid', $voucher))->assertOk();
        $this->stripe->intents[$voucher]['status'] = 'processing';
        $card = $this->stripe->paidWith($second, 'card');
        $this->deliver($this->sessionEvent("evt_{$key}2", 'checkout.session.completed', $second, $sale, 4400, 'paid', $card))->assertOk();
        $this->inside(self::SHOP, fn () => $this->assertSame('awaiting', PaymentAttempt::query()->where('provider_ref', $first)->sole()->status));

        return [$sale, $voucher];
    }

    private function enableKonbini(): void
    {
        $this->inside(self::SHOP, fn () => app(PaymentMethodSettings::class)->update(['konbini' => true, 'paypay' => false, 'konbini_days' => 3], 'operator:test'));
    }

    /**
     * ショップの注文を確定し、支払いの画面を作る
     */
    private function order(?string $coupon = null): string
    {
        return $this->inside(self::SHOP, function () use ($coupon): string {
            $cart = $this->cart();

            if ($coupon !== null) {
                app(CartService::class)->applyCoupon($cart, $coupon);
            }

            $carts = app(CartService::class);
            $view = $carts->view($cart->fresh() ?? $cart);
            $sale = app(PlaceOrder::class)->place($cart, $this->input(), rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), CheckoutSummary::fingerprintOf($view, app(PaymentMethodSettings::class)->forCheckout($view->total())));
            app(PaymentService::class)->startCheckout($sale, 'x', 'y');

            return $sale->id;
        });
    }

    private function cart(): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->listing)->value('id'), 1);

        return $cart;
    }

    private function input(): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null]);
        $this->assertNotNull($input);

        return $input;
    }

    /**
     * いちばん新しい支払いの画面を作る頼みの中身
     *
     * @return array<string, mixed>
     */
    private function checkoutParams(): array
    {
        foreach (array_reverse($this->stripe->requests) as $request) {
            if ($request['method'] === 'post' && str_ends_with($request['url'], '/v1/checkout/sessions')) {
                return $request['params'];
            }
        }

        $this->fail('支払いの画面を頼んでいない');
    }

    private function ref(string $sale): string
    {
        return $this->inside(self::SHOP, fn (): string => PaymentAttempt::query()->where('transaction_id', $sale)->sole()->provider_ref);
    }

    private function reserved(): int
    {
        return $this->inside(self::SHOP, fn (): int => (int) InventoryStock::query()->where('listing_id', $this->listing)->sum('reserved'));
    }

    /**
     * Stripe の checkout.session.* の知らせ（必要なところだけ）
     *
     * @return array<string, mixed>
     */
    private function sessionEvent(string $id, string $type, string $ref, string $sale, int $amount, string $paymentStatus, string $intent, string $currency = 'jpy'): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'livemode' => false,
            'created' => time(),
            'data' => ['object' => [
                'id' => $ref,
                'object' => 'checkout.session',
                'mode' => 'payment',
                'status' => 'complete',
                'amount_total' => $amount,
                'currency' => $currency,
                'client_reference_id' => $sale,
                'payment_status' => $paymentStatus,
                'payment_intent' => $intent,
            ]],
        ];
    }

    /**
     * 署名つきで店の受け口へ送る（Stripe と同じ形）
     *
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    private function deliver(array $event): TestResponse
    {
        $payload = (string) json_encode($event);
        $time = time();
        $signature = "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", $this->webhookSecret());
        $response = $this->call('POST', 'http://'.self::SHOP.'.localhost/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
        tenancy()->end();

        return $response;
    }

    private function webhookSecret(): string
    {
        return implode('_', ['whsec', self::BODY.self::SHOP]);
    }
}
