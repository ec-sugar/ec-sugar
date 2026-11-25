<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Access\ShopPasswordCookie;
use App\Storefront\Cart\CartCookie;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 準備中のショップの注文（運営の判断）。パスワードを入れた人が注文まで試せるのは Stripe のテストの鍵のときだけ。
 * 本番の鍵なら「公開してから注文できます」と断る（カートに入れるまではよい）。判断はサーバーで、注文の確定と支払いの画面を作る所の両方で。
 * 公開のショップは本番の鍵でもふだんどおり
 */
final class PreparingShopOrderTest extends TestCase
{
    use DatabaseTruncation;

    private const string PASSWORD = 'preview-order-1';

    /** 作った直後の店は準備中のまま確かめる */
    protected bool $openNewShops = false;

    private Tenant $tenant;

    private string $listingId = '';

    private ?string $token = null;

    private ?string $pass = null;

    /** @var array<string, mixed> */
    private array $valid = [
        'buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''],
        'same_as_buyer' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        config(['payment.live_keys_allowed' => true]);
        $this->tenant = Tenant::create(['id' => 'pso', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'pso.localhost']);
        $this->listingId = $this->inside(static function (): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 5);
            app(StorefrontAccessService::class)->setPassword(self::PASSWORD, 'cli:test');

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

    public function test_a_preparing_shop_with_live_keys_refuses_the_order_but_keeps_the_cart(): void
    {
        $this->useKey('live');
        $this->enter();
        $this->send('POST', '/cart/items', ['listing_id' => $this->listingId, 'quantity' => '1'])->assertRedirect()->assertSessionMissing('cart_error');

        $this->send('POST', '/checkout/confirm', $this->confirm())->assertRedirect('http://pso.localhost/checkout/confirm')
            ->assertSessionHas('cart_error', PaymentService::NOT_OPEN);
        $this->inside(function (): void {
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, PaymentAttempt::query()->count());
            $this->assertTrue(app(PaymentService::class)->liveBeforeOpening());
        });
    }

    public function test_a_preparing_shop_with_test_keys_lets_the_people_with_the_password_order(): void
    {
        $this->useKey('test');
        $this->enter();
        $this->send('POST', '/cart/items', ['listing_id' => $this->listingId, 'quantity' => '1'])->assertRedirect();

        $this->assertToStripe($this->send('POST', '/checkout/confirm', $this->confirm()));
        $this->inside(fn () => $this->assertSame(1, Transaction::query()->count()));
    }

    public function test_an_open_shop_takes_orders_with_live_keys(): void
    {
        $this->useKey('live');
        $this->inside(static fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Open, 'cli:test'));
        $this->send('POST', '/cart/items', ['listing_id' => $this->listingId, 'quantity' => '1'])->assertRedirect();

        $this->assertToStripe($this->send('POST', '/checkout/confirm', $this->confirm()));
        $this->inside(fn () => $this->assertFalse(app(PaymentService::class)->liveBeforeOpening()));
    }

    public function test_the_payment_page_is_refused_where_the_payment_starts(): void
    {
        // 公開中に本番の鍵で受けた注文を、準備中に戻してから「お支払いへ進む」: 支払いの画面を作らずに断る（核の startCheckout）
        $this->useKey('live');
        $this->inside(static fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Open, 'cli:test'));
        $this->send('POST', '/cart/items', ['listing_id' => $this->listingId, 'quantity' => '1'])->assertRedirect();
        $this->assertToStripe($this->send('POST', '/checkout/confirm', $this->confirm()));
        $attempts = $this->inside(static fn (): int => PaymentAttempt::query()->count());

        $this->inside(static fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'cli:test'));
        $this->enter();
        $this->send('POST', '/checkout/pay')->assertRedirect('http://pso.localhost/checkout/complete')->assertSessionHas('cart_error', PaymentService::NOT_OPEN);
        $this->inside(fn () => $this->assertSame($attempts, PaymentAttempt::query()->count()));

        // テストの鍵に替えれば進める
        $this->useKey('test');
        $this->assertToStripe($this->send('POST', '/checkout/pay'));
    }

    private function useKey(string $mode): void
    {
        $this->inside(static fn () => app(PaymentGatewayService::class)->configureStripe([
            'secret_key' => implode('_', ['sk', $mode, 'FakeForTestsOnly0001234']),
            'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234']),
        ], 'cli:test'));
    }

    private function enter(): void
    {
        $this->travel(5)->seconds();
        $cookie = $this->send('POST', '/password', ['password' => self::PASSWORD])->getCookie(ShopPasswordCookie::NAME);
        $this->assertNotNull($cookie);
        $this->pass = (string) $cookie->getValue();
    }

    /**
     * @return array<string, string>
     */
    private function confirm(): array
    {
        $this->send('POST', '/checkout', $this->valid)->assertRedirect('http://pso.localhost/checkout/confirm');
        $html = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $html, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);
        $this->assertNotEmpty($key[1] ?? null);

        return ['order_key' => $key[1], 'fingerprint' => $fingerprint[1] ?? ''];
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertToStripe(TestResponse $response): void
    {
        $response->assertStatus(303);
        $this->assertStringStartsWith('https://checkout.stripe.com/c/pay/cs_test_', (string) $response->headers->get('Location'));
    }

    /**
     * カートの合い言葉と、入った印の Cookie を持ち越して送る（セッションは続く）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        if ($this->pass !== null) {
            $this->withCookie(ShopPasswordCookie::NAME, $this->pass);
        }

        $url = "http://pso.localhost{$path}";
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
