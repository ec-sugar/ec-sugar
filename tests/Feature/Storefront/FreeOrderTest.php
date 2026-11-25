<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Enums\OrderStatus;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartLine;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Orders\OrderMail;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 合計 0 円の注文（レビューの指摘 3。無料の注文も完了できるようにする）。
 * 支払いを飛ばして確定し（発送待ち）、お金は動かないので台帳には書かない。期限切れの片付けは触らず、取消・発送・完了は今までどおり
 */
final class FreeOrderTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private FakeStripe $stripe;

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->stripe = FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'fra', 'name' => '無料の見本の店']);
        $this->tenant->domains()->create(['domain' => 'fra.localhost']);
        $this->inside(function (): void {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(ShopSettingsService::class)->setShipping(['fee' => 0, 'free_over' => null], 'test');
            // 0 円の品は店が売ると決めたときだけ。この節のテストは売る店で
            app(ShopSettingsService::class)->setSellFreeItems(true, 'test');
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

    public function test_a_free_order_is_confirmed_without_payment_and_can_be_shipped(): void
    {
        Mail::fake();
        $sample = $this->listing('試供品', 0);

        $order = $this->place($sample);

        // Stripe には行かず、受け付けた画面へ。注文は確定（発送待ち）、在庫は押さえる、お金は動かないので台帳に書かない
        $this->assertSame([], $this->stripe->requests);
        $this->inside(function () use ($order, $sample): void {
            $transaction = Transaction::query()->findOrFail($order);
            $this->assertSame([TransactionStatus::Open, Phase::Payment, 0], [$transaction->status, $transaction->phase, (int) $transaction->total_amount]);
            $this->assertSame(OrderStatus::Confirmed, app(SaleOrderService::class)->status($transaction));
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.not_required')->where('aggregate_id', $order)->count());
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $order)->count());
            $this->assertSame(0, PaymentAttempt::query()->where('transaction_id', $order)->count());
            $this->assertSame(1, (int) InventoryStock::query()->where('listing_id', $sample)->sum('reserved'));
        });

        // 受け付けた画面: どのテーマでも「お支払いは不要です」、払う押しボタンは出さない
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $html = (string) $this->send('GET', '/checkout/complete')->assertOk()->getContent();
            $this->assertStringContainsString('data-test="order-free"', $html, $theme);
            $this->assertStringNotContainsString('data-test="pay"', $html, $theme);
            $this->assertStringNotContainsString('data-test="payment-due"', $html, $theme);
            // 支払いへ進めない、という誤った知らせも出さない
            $this->assertStringNotContainsString('現在お支払いに進めません', $html, $theme);
        }
        // 「お支払いへ進む」を押されても Stripe へは行かない
        $this->send('POST', '/checkout/pay')->assertRedirect('http://fra.localhost/checkout/complete');
        $this->assertSame([], $this->stripe->requests);
        $this->assertStringNotContainsString('現在お支払いに進めません', (string) $this->send('GET', '/checkout/complete')->getContent());

        // 注文の知らせのメールは「お支払いは不要です」
        // 注文の知らせのメールは、HTML のバージョンも文字だけのバージョンも「お支払いは不要です」（「お支払いを受け付けました」は出さない）
        Mail::assertSent(OrderMail::class, function (OrderMail $mail): bool {
            $mail->assertSeeInHtml('お支払いは不要です')->assertSeeInText('お支払いは不要です')
                ->assertDontSeeInHtml('お支払いを受け付けました')->assertDontSeeInText('お支払いを受け付けました');

            return $mail->kind === 'order_paid';
        });

        // 期限切れの片付けは触らない
        $this->travel(120)->minutes();
        $this->artisan('storefront:expire-unpaid-orders')->expectsOutputToContain('取り消し 0 件')->assertSuccessful();
        $this->inside(fn () => $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($order)->status));

        // 発送と完了は今までどおり
        $this->inside(function () use ($order): void {
            $orders = app(SaleOrderService::class);
            $transaction = Transaction::query()->findOrFail($order);
            $orders->ship($transaction, (string) $transaction->fulfillments()->value('id'), 'yamato', '1234-5678', 'operator:test');
            $orders->complete(Transaction::query()->findOrFail($order), 'operator:test');
            $this->assertSame(OrderStatus::Completed, $orders->status(Transaction::query()->findOrFail($order)));
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $order)->count());
        });
    }

    public function test_a_free_order_is_cancelled_without_a_refund(): void
    {
        $sample = $this->listing('試供品', 0);
        $order = $this->place($sample);

        $this->inside(function () use ($order, $sample): void {
            $result = app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($order), 'operator:test');
            $this->assertSame([0, 0], [$result['refunded'], $result['refund_pending']]);
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($order)->status);
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $sample)->sum('reserved'));
        });
        $this->assertSame([], $this->stripe->requests);

        // 受け付けた画面は「取り消しました」。期限切れの文は出さない
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $html = (string) $this->send('GET', '/checkout/complete')->assertOk()->getContent();
            $this->assertStringContainsString('ご注文を取り消しました。くわしくは', $html, $theme);
            $this->assertStringNotContainsString('お支払いの期限が過ぎた', $html, $theme);
        }
    }

    public function test_a_free_item_with_a_shipping_fee_is_paid_as_usual(): void
    {
        // 品物が 0 円でも送料がかかれば、合計は 1 円以上なので今までどおり Stripe で払う
        $this->inside(fn () => app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => null], 'test'));
        $order = $this->place($this->listing('試供品', 0), expectFree: false);

        $this->inside(function () use ($order): void {
            $transaction = Transaction::query()->findOrFail($order);
            $this->assertSame([Phase::Agreement, 550], [$transaction->phase, (int) $transaction->total_amount]);
            $this->assertSame(0, DomainEvent::query()->where('type', 'payment.not_required')->count());
            $this->assertSame(1, PaymentAttempt::query()->where('transaction_id', $order)->count());
        });
    }

    public function test_free_items_are_sold_only_when_the_shop_chooses_to(): void
    {
        $sample = $this->listing('試供品', 0);
        $this->listing('ニット', 4400);

        // 売らない店（既定）: 0 円の品はショップに出ず、カートにも入らない
        $this->inside(fn () => app(ShopSettingsService::class)->setSellFreeItems(false, 'test'));
        $list = (string) $this->send('GET', '/products')->assertOk()->getContent();
        $this->assertStringNotContainsString('試供品', $list);
        $this->assertStringContainsString('ニット', $list);
        $this->send('GET', "/products/{$sample}")->assertNotFound();
        $this->send('POST', '/cart/items', ['listing_id' => $sample, 'quantity' => '1']);
        $this->assertStringNotContainsString('試供品', (string) $this->send('GET', '/cart')->getContent());

        // 売る店: 出て、カートに入る。ただし 1 回のご注文で 1 点まで
        $this->inside(fn () => app(ShopSettingsService::class)->setSellFreeItems(true, 'test'));
        $this->assertStringContainsString('試供品', (string) $this->send('GET', '/products')->getContent());
        $this->send('POST', '/cart/items', ['listing_id' => $sample, 'quantity' => '2']);
        $this->assertStringNotContainsString('試供品', (string) $this->send('GET', '/cart')->getContent());
        $this->send('POST', '/cart/items', ['listing_id' => $sample, 'quantity' => '1']);
        $this->send('POST', '/cart/items', ['listing_id' => $sample, 'quantity' => '1']);
        $this->inside(fn () => $this->assertSame(1, (int) CartLine::query()->where('listing_id', $sample)->value('quantity')));

        // 確認の画面の支払いの方法と時期は「お支払いは不要です」
        $this->send('POST', '/checkout', ['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => ''], 'same_as_buyer' => '1']);
        $confirm = (string) $this->send('GET', '/checkout/confirm')->getContent();
        $this->assertStringContainsString('お支払いは不要です', $confirm);
        $this->assertStringNotContainsString('クレジットカード', $confirm);

        // カートに入れた後に店が売るのをやめた: 確定で断る（注文も在庫の確保も作らない）
        $this->inside(fn () => app(ShopSettingsService::class)->setSellFreeItems(false, 'test'));
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertRedirect();
        $this->inside(function (): void {
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, (int) InventoryStock::query()->sum('reserved'));
        });
    }

    public function test_free_orders_from_the_same_customer_are_limited_per_hour(): void
    {
        // 他人宛ての注文のメールを出させ続ける悪用を防ぐ: 同じ接続元・同じメールからの 0 円のご注文は 1 時間に 3 回まで
        $sample = $this->listing('試供品', 0);
        for ($i = 0; $i < 3; $i++) {
            $this->place($sample);
        }

        $this->send('POST', '/cart/items', ['listing_id' => $sample, 'quantity' => '1']);
        $this->send('POST', '/checkout', ['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => ''], 'same_as_buyer' => '1']);
        $confirm = (string) $this->send('GET', '/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertRedirect('http://fra.localhost/checkout/confirm');
        $this->assertStringContainsString('無料の品のご注文が続いています', (string) $this->send('GET', '/checkout/confirm')->getContent());
        $this->inside(fn () => $this->assertSame(3, Transaction::query()->count()));

        // 1 時間たてば、また受け付ける
        $this->travel(61)->minutes();
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertRedirect('http://fra.localhost/checkout/complete');
        $this->inside(fn () => $this->assertSame(4, Transaction::query()->count()));
    }

    /**
     * カート → 入力 → 確認 → 確定。確定した注文の ID
     */
    private function place(string $listing, bool $expectFree = true): string
    {
        $this->send('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);
        $this->send('POST', '/checkout', ['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => ''], 'same_as_buyer' => '1']);
        $confirm = (string) $this->send('GET', '/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $response = $this->send('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]]);

        if ($expectFree) {
            $response->assertRedirect('http://fra.localhost/checkout/complete');
        } else {
            $response->assertStatus(303);
        }

        return $this->inside(fn (): string => (string) CheckoutOrder::query()->latest('created_at')->value('transaction_id'));
    }

    private function listing(string $title, int $amount): string
    {
        return $this->inside(function () use ($title, $amount): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 5);

            return $listing->id;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $response = $method === 'GET' ? $this->get("http://fra.localhost{$path}") : $this->post("http://fra.localhost{$path}", $data);
        tenancy()->end();
        $this->token = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->token;

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
