<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Log;
use LogicException;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 支払いの画面を用意する（ADR 0019）。金額はこちらの取引から、個人情報は送らない、画面は使い回し、断るときは理由だけ。
 */
final class PaymentCheckoutTest extends TestCase
{
    use DatabaseTruncation;

    private const string BODY = 'FakeForTestsOnly000';

    private Tenant $tenant;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'pca', 'name' => '店']);
        tenancy()->initialize($this->tenant);
        app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => implode('_', ['whsec', self::BODY.'1234'])]);
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

    public function test_the_checkout_is_built_from_the_transaction_without_personal_data(): void
    {
        $sale = $this->sale([[$this->listing('<b>ニット</b>', 4400, 3), 2]], shipping: 550);
        $payments = app(PaymentService::class);

        $session = $payments->startCheckout($sale, 'http://pca.localhost/checkout/complete?returned=1', 'http://pca.localhost/checkout/complete', 'storefront');
        $this->assertSame(9350, $session->amount);
        $this->assertStringStartsWith('https://checkout.stripe.com/c/pay/cs_test_', $session->url);

        $params = $this->stripe->lastParams();
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $this->stripe->requests[0]['url']);
        $this->assertSame('payment', $params['mode']);
        // カードだけ（後で払う方法は、払われる途中に期限切れの片付けが取り消してしまう）
        $this->assertArrayNotHasKey('payment_method_types', $params);
        $this->assertSame(['konbini', 'paypay', 'customer_balance', 'alipay', 'wechat_pay'], $params['excluded_payment_method_types']);
        // 明細は取引の明細から（単価 × 数、送料は 1 行）。名前は文字のまま渡す（Stripe の画面がエスケープする）
        $this->assertSame([
            ['price_data' => ['currency' => 'jpy', 'unit_amount' => 4400, 'product_data' => ['name' => '<b>ニット</b>']], 'quantity' => 2],
            ['price_data' => ['currency' => 'jpy', 'unit_amount' => 550, 'product_data' => ['name' => '送料']], 'quantity' => 1],
        ], $params['line_items']);
        $this->assertSame($sale->id, $params['client_reference_id']);
        $this->assertSame(['transaction_id' => $sale->id, 'tenant' => 'pca'], $params['metadata']);
        $this->assertSame('http://pca.localhost/checkout/complete?returned=1', $params['success_url']);
        $this->assertEqualsWithDelta(now()->addMinutes(60)->getTimestamp(), $params['expires_at'], 5);
        // 冪等キーは取引と何回目か。鍵は店のもの
        $this->assertSame("checkout-{$sale->id}-1", $this->stripe->lastHeader('Idempotency-Key'));
        $this->assertSame('Bearer '.implode('_', ['sk', 'test', self::BODY.'1234']), $this->stripe->lastHeader('Authorization'));
        // お客さんの個人情報は送らない（メールも Stripe の画面で入れてもらう）
        $this->assertArrayNotHasKey('customer_email', $params);
        $sent = (string) json_encode($params, JSON_UNESCAPED_UNICODE);
        foreach (['山田', 'hanako', '09012345678', '渋谷'] as $pii) {
            $this->assertStringNotContainsString($pii, $sent);
        }

        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame([$session->ref, 9350, 'open', 'JPY'], [$attempt->provider_ref, $attempt->amount, $attempt->status, $attempt->currency]);
        $event = DomainEvent::query()->where('type', 'payment.checkout_started')->sole();
        $this->assertEquals(['attempt' => $attempt->id, 'provider' => 'stripe', 'amount' => 9350], $event->payload);

        // もう一度押しても、まだ使える画面を使い回す（Stripe に問い合わせない）
        $this->assertSame($session->url, $payments->startCheckout($sale, 'x', 'y')->url);
        $this->assertCount(1, $this->stripe->requests);

        // 期限が近い画面は使わず、新しく作る（2 回目の冪等キー）
        $attempt->forceFill(['expires_at' => now()->addMinutes(3)])->save();
        $this->assertNotSame($session->url, $payments->startCheckout($sale, 'x', 'y')->url);
        $this->assertSame("checkout-{$sale->id}-2", $this->stripe->lastHeader('Idempotency-Key'));
        $this->assertSame(2, PaymentAttempt::query()->count());
    }

    public function test_lines_that_do_not_add_up_are_sent_as_one_total(): void
    {
        // 値引き（負の行）があると、Stripe の明細にできないので合計 1 行
        $sale = $this->sale([[$this->listing('コート', 30000, 1), 1]], discount: -3000);
        app(PaymentService::class)->startCheckout($sale, 'x', 'y');

        $this->assertSame([
            ['price_data' => ['currency' => 'jpy', 'unit_amount' => 27000, 'product_data' => ['name' => 'ご注文 '.strtoupper($sale->id)]], 'quantity' => 1],
        ], $this->stripe->lastParams()['line_items']);
    }

    public function test_refusals_leave_no_attempt_and_do_not_log_the_key(): void
    {
        $sale = $this->sale([[$this->listing('ニット', 4400, 3), 1]]);
        $payments = app(PaymentService::class);
        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        // Stripe が断る（鍵の誤りなど）
        $this->stripe->respond = static fn (): array => [(string) json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'api_key_expired', 'message' => 'Expired API Key provided: sk_test_***1234']]), 401, ['request-id' => 'req_1']];
        $this->assertRefused(fn () => $payments->startCheckout($sale, 'x', 'y'), 'お支払いの画面を用意できませんでした');
        $this->assertStringContainsString('api_key_expired', implode("\n", $logged));
        $this->assertStringNotContainsString(self::BODY, implode("\n", $logged));

        // Stripe の答えの合計が取引と違う、リダイレクト先が Stripe の画面でない
        foreach ([['amount_total' => 1, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_x'], ['amount_total' => 4400, 'url' => 'https://evil.example/pay']] as $answer) {
            $this->stripe->respond = static fn (): array => [(string) json_encode(['id' => 'cs_test_'.bin2hex(random_bytes(4)), 'object' => 'checkout.session', 'expires_at' => time() + 3600] + $answer), 200, []];
            $this->assertRefused(fn () => $payments->startCheckout($sale, 'x', 'y'), 'お支払いの画面を用意できませんでした');
        }
        $this->assertSame(0, PaymentAttempt::query()->count());

        // 払えない状態の取引（取消・済み）、鍵の無い店。Stripe には問い合わせない
        $this->stripe->respond = null;
        $this->stripe->requests = [];
        $paid = $this->sale([[$this->listing('帽子', 1000, 3), 1]]);
        PaymentAttempt::create(['transaction_id' => $paid->id, 'provider' => 'stripe', 'provider_ref' => 'cs_test_paid', 'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_paid', 'amount' => 1000, 'currency' => 'JPY', 'status' => 'paid', 'expires_at' => now()->addHour(), 'paid_at' => now()]);
        $this->assertRefused(fn () => $payments->startCheckout($paid, 'x', 'y'), '済んでいます');
        $cancelled = app(TransactionService::class)->transition($this->sale([[$this->listing('靴', 1000, 3), 1]]), Phase::Cancel);
        $this->assertRefused(fn () => $payments->startCheckout($cancelled, 'x', 'y'), '現在お支払いに進めません');
        app(PaymentGatewayService::class)->removeStripe();
        $this->assertFalse($payments->available());
        $this->assertRefused(fn () => $payments->startCheckout($sale, 'x', 'y'), PaymentService::UNAVAILABLE);
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_session_created_twice_at_once_is_recorded_once_and_notices_do_not_break_it(): void
    {
        $sale = $this->sale([[$this->listing('ニット', 4400, 3), 1]]);
        $payments = app(PaymentService::class);
        // 同時に押された 2 つが同じ画面を受け取る（Stripe の冪等キー）。後の方は記録の一意で弾かれても、同じ画面へ送る
        // （Stripe の答えに知らせがあると部品が PHP の警告を出す。例外にせずログへ）
        $this->stripe->respond = static fn (): array => [(string) json_encode(['id' => 'cs_test_same', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_same', 'amount_total' => 4400, 'expires_at' => time() + 120]), 200, ['stripe-notice' => 'この API のバージョンは古くなります']];

        $first = $payments->startCheckout($sale, 'x', 'y');
        $second = $payments->startCheckout($sale, 'x', 'y');
        $this->assertSame([$first->url, 1], [$second->url, PaymentAttempt::query()->count()]);
        $this->assertCount(2, $this->stripe->requests);
    }

    public function test_the_fake_stripe_address_is_refused_in_production(): void
    {
        $sale = $this->sale([[$this->listing('ニット', 4400, 3), 1]]);
        config(['payment.stripe_api_base' => 'http://127.0.0.1:9/']);
        $this->app['env'] = 'production';

        try {
            app(PaymentService::class)->startCheckout($sale, 'x', 'y');
            $this->fail('本番で送信先を差し替えられた');
        } catch (LogicException $e) {
            $this->assertStringContainsString('STRIPE_API_BASE', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * @param  list<array{0: Listing, 1: int}>  $items
     */
    private function sale(array $items, int $shipping = 0, int $discount = 0): Transaction
    {
        $drafts = array_map(static fn (array $item): LineDraft => LineDraft::fromListing($item[0], $item[1], 1000, Location::query()->firstOrFail()), $items);

        if ($shipping > 0) {
            $drafts[] = LineDraft::adjustment('shipping', '送料', $shipping, 1000);
        }

        if ($discount < 0) {
            $drafts[] = LineDraft::adjustment('discount', '値引き', $discount, 1000);
        }

        $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com', 'phone' => '09012345678']);

        return app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), $customer, $drafts, actor: 'test');
    }

    private function listing(string $title, int $amount, int $stock): Listing
    {
        $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => 'active']);
        app(StockService::class)->receive($listing, Location::query()->firstOrFail(), $stock);

        return $listing;
    }

    private function assertRefused(Closure $action, string $message): void
    {
        try {
            $action();
            $this->fail("断られなかった（{$message}）");
        } catch (PaymentUnavailableException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
