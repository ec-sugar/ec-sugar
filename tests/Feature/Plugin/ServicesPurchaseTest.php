<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Models\Operator;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\AddonService;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「サービスの販売」。設置の代行のような役務を、オプションつきでふだんのカート（ゲスト）から買う。送料・お届け先・在庫なしで、受渡は in_person。
 * 作業を終えたら店の人が管理画面の注文の「提供済みにする」で完了まで進める。ほかの扱いの商品と同じ注文には入れない。
 * 払う前・サービスだけでない注文・予約の品の役務の注文は「提供済みにする」で断る。App を止めた店では売らない
 */
final class ServicesPurchaseTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private ?Operator $operator = null;

    private ?string $token = null;

    private string $setup;

    private string $mug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->configureAdminHost();
        Cache::store()->flush();
        FakeStripe::install();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Asia/Tokyo'));
        $this->tenant('svc', '公式の店');
        [$this->setup, $this->mug] = $this->inside('svc', function (): array {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => null]);
            app(PluginStates::class)->set('services', true, 'test');
            $seller = Seller::query()->value('id');
            $setup = Listing::create(['seller_id' => $seller, 'kind' => 'service_item', 'title' => '設置代行', 'unit_amount' => 55000, 'status' => 'active']);
            app(AddonService::class)->define($setup, $setup->version, [
                ['kind' => 'toggle', 'name' => 'Stripe の接続のお手伝い', 'amount' => 11000],
                ['kind' => 'toggle', 'name' => '商品の登録の代行（20 点まで）', 'amount' => 22000],
            ]);
            $mug = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'マグカップ', 'unit_amount' => 2000, 'status' => 'active']);
            app(StockService::class)->receive($mug, Location::query()->orderBy('id')->firstOrFail(), 5);

            return [$setup->id, $mug->id];
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_guest_buys_a_service_with_options_and_the_shop_marks_it_delivered(): void
    {
        [$stripeHelp] = $this->addons();
        $this->shop('POST', '/cart/items', ['listing_id' => $this->setup, 'quantity' => '1', 'addons' => [$stripeHelp => '1']])->assertRedirect();
        // 1 回の注文で 1 点まで
        $this->shop('POST', '/cart/items', ['listing_id' => $this->setup, 'quantity' => '1', 'addons' => [$stripeHelp => '1']]);
        $this->inside('svc', fn () => $this->assertSame(1, (int) DB::table('cart_lines')->sum('quantity')));

        // お届け先を聞かない。送料も付かない（本体 + オプション）
        $form = (string) $this->shop('GET', '/checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-ship-to', $form);
        $this->shop('POST', '/checkout', ['buyer' => self::buyer(), 'same_as_buyer' => '1'])->assertRedirect('http://svc.localhost/checkout/confirm');
        $confirm = (string) $this->shop('GET', '/checkout/confirm')->assertOk()->getContent();
        $this->assertStringContainsString('¥66,000', $confirm);
        $this->toStripe($confirm);

        $order = $this->inside('svc', function (): Transaction {
            $order = Transaction::query()->sole();
            $this->assertSame(66000, (int) $order->total_amount);
            // 受渡は in_person の 1 つ（お届け先も拠点も無い）。在庫を押さえない
            $fulfillment = Fulfillment::query()->where('transaction_id', $order->id)->sole();
            $this->assertSame([FulfillmentMethod::InPerson, null, null, FulfillmentStatus::Pending], [$fulfillment->method, $fulfillment->address, $fulfillment->location_id, $fulfillment->status]);
            $this->assertSame(0, (int) InventoryStock::query()->sum('reserved'));
            $this->assertTrue(app(SaleOrderService::class)->isServiceOrder($order));

            return $order;
        });
        $url = "/api/tenants/svc/transactions/{$order->id}";

        // 払う前は「提供済みにする」を出さず、送っても断る
        $this->api('GET', $url)->assertOk()->assertJsonPath('transaction.actions.deliver_service', false)->assertJsonPath('transaction.actions.ship', false);
        $this->api('POST', "{$url}/deliver-service")->assertStatus(409)->assertJsonPath('user_message', '支払いの前は提供済みにできません');

        $this->pay($order);

        // 払った後: 発送の操作は出さず、「提供済みにする」だけ
        $this->api('GET', $url)->assertOk()->assertJsonPath('transaction.order_status', 'paid')
            ->assertJsonPath('transaction.actions.deliver_service', true)->assertJsonPath('transaction.actions.ship', false)->assertJsonPath('transaction.actions.complete', false);
        $this->api('POST', "{$url}/deliver-service")->assertOk()
            ->assertJsonPath('transaction.status', 'completed')->assertJsonPath('transaction.actions.deliver_service', false);
        // 二度押しは状態で断る
        $this->api('POST', "{$url}/deliver-service")->assertStatus(409);

        $this->inside('svc', function () use ($order): void {
            $fresh = Transaction::query()->findOrFail($order->id);
            $this->assertSame([TransactionStatus::Completed, Phase::Settlement], [$fresh->status, $fresh->phase]);
            $this->assertSame(FulfillmentStatus::Completed, Fulfillment::query()->where('transaction_id', $order->id)->sole()->status);
            // 発送の知らせ（メール）は出さない
            $this->assertSame(0, DomainEvent::query()->where('type', 'fulfillment.shipped')->count());
            // 記録に個人情報を入れない
            $payloads = DomainEvent::query()->pluck('payload')->map(fn ($p) => json_encode($p, JSON_UNESCAPED_UNICODE))->implode("\n");
            foreach (['hanako', '山田'] as $secret) {
                $this->assertStringNotContainsString($secret, $payloads);
            }
        });
    }

    public function test_a_service_is_not_ordered_with_other_items(): void
    {
        $this->shop('POST', '/cart/items', ['listing_id' => $this->mug, 'quantity' => '1'])->assertRedirect();
        $this->shop('POST', '/cart/items', ['listing_id' => $this->setup, 'quantity' => '1'])->assertRedirect();

        // サービスの行は止まり、マグだけが買える（送料はマグの分）
        $cart = (string) $this->shop('GET', '/cart')->assertOk()->getContent();
        $this->assertStringContainsString(e(CartService::SERVICES_MIXED), $cart);
        $view = $this->inside('svc', fn () => app(CartService::class)->view(Cart::query()->sole()));
        $purchasable = collect($view->lines)->mapWithKeys(static fn (array $l): array => [$l['listingId'] => $l['purchasable']])->all();
        $this->assertSame([$this->mug => true, $this->setup => false], [$this->mug => $purchasable[$this->mug], $this->setup => $purchasable[$this->setup]]);
        $this->assertSame(1, $view->count);
        $this->assertTrue($view->needsShipping());

        // 止まった行のあるカートは確定に進めない（サービスを外すか、別に注文してもらう）。注文は作らない
        $this->shop('POST', '/checkout', ['buyer' => self::buyer(), 'same_as_buyer' => '1']);
        $confirm = (string) $this->shop('GET', '/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->shop('POST', '/checkout/confirm', ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''])->assertRedirect();
        $this->inside('svc', fn () => $this->assertSame(0, Transaction::query()->count()));
    }

    public function test_deliver_service_refuses_orders_that_are_not_service_only(): void
    {
        // ふつうの商品の注文（発送するもの）は「提供済みにする」で済ませられない
        $this->shop('POST', '/cart/items', ['listing_id' => $this->mug, 'quantity' => '1'])->assertRedirect();
        $this->shop('POST', '/checkout', ['buyer' => self::buyer(), 'same_as_buyer' => '1'])->assertRedirect();
        $this->toStripe((string) $this->shop('GET', '/checkout/confirm')->getContent());
        $order = $this->inside('svc', fn (): Transaction => Transaction::query()->sole());
        $this->pay($order);

        $url = "/api/tenants/svc/transactions/{$order->id}";
        $this->api('GET', $url)->assertJsonPath('transaction.actions.deliver_service', false)->assertJsonPath('transaction.actions.ship', true);
        $this->api('POST', "{$url}/deliver-service")->assertStatus(409)->assertJsonPath('user_message', 'サービスだけの注文ではありません');

        $this->inside('svc', function () use ($order): void {
            $this->assertSame(FulfillmentStatus::Pending, Fulfillment::query()->where('transaction_id', $order->id)->sole()->status);

            try {
                app(SaleOrderService::class)->deliverService(Transaction::query()->findOrFail($order->id), 'operator:test');
                $this->fail('サービスでない注文を済ませた');
            } catch (TransactionException) {
                $this->addToAssertionCount(1);
            }
        });
    }

    public function test_a_shop_without_the_app_does_not_sell_services(): void
    {
        $this->inside('svc', fn () => app(PluginStates::class)->set('services', false, 'test'));

        $this->shop('POST', '/cart/items', ['listing_id' => $this->setup, 'quantity' => '1']);
        $this->inside('svc', fn () => $this->assertSame(0, (int) DB::table('cart_lines')->sum('quantity')));
    }

    /**
     * 管理画面の API へ送る（運営は店の操作の後に入る。先に入ると、店への送信の後のセッションが通らない）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function api(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->operator === null) {
            [$this->operator] = $this->enrolledOperator();
        }

        return $this->send($method, $path, $data);
    }

    /**
     * @return list<string>
     */
    private function addons(): array
    {
        return $this->inside('svc', fn (): array => ListingAddon::query()->where('listing_id', $this->setup)->orderBy('position')->pluck('id')->all());
    }

    private function pay(Transaction $order): void
    {
        $this->inside('svc', function () use ($order): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $order->id)->sole();
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$order->id, 'checkout.session.completed', $attempt->provider_ref, $order->id, (int) $order->total_amount, 'jpy', 'pi_'.$order->id));
        });
        $this->inside('svc', fn () => event(new PaymentConfirmed($order->id)));
    }

    private function toStripe(string $confirm): void
    {
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $response = $this->shop('POST', '/checkout/confirm', ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? '']);
        $response->assertStatus(303);
        $this->assertStringStartsWith('https://checkout.stripe.com/', (string) $response->headers->get('Location'));
    }

    /**
     * @return array<string, string>
     */
    private static function buyer(): array
    {
        return ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''];
    }

    /**
     * ショップへ送る（カートの合い言葉を持ち越す）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $url = "http://svc.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(CartCookie::NAME);

        if ($cookie !== null) {
            $this->token = (string) $cookie->getValue();
        }

        return $response;
    }
}
