<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Catalog\Models\Category;
use App\Core\Discount\Models\Coupon;
use App\Core\Discount\Models\CouponRedemption;
use App\Core\Discount\Services\CouponService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Money\Allocation;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentService;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartException;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Orders\OrderReader;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * クーポン（ADR 0054）。値引きはサーバーで決め、注文には調整の行（discount）で残す。
 * 使った回数は注文を作る錠の中で数え直し、払われずに消えた注文では戻す。確かめた後に条件が変われば確定しない
 */
final class CouponTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private string $knit;

    private string $cap;

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'cpn', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'cpn.localhost']);
        [$this->knit, $this->cap] = $this->inside(function (): array {
            $seller = Seller::query()->value('id');
            $knit = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            $cap = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'キャップ', 'unit_amount' => 3300, 'status' => 'active']);
            $location = Location::query()->orderBy('id')->firstOrFail();
            app(StockService::class)->receive($knit, $location, 20);
            app(StockService::class)->receive($cap, $location, 20);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 10000]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);

            return [$knit->id, $cap->id];
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

    public function test_a_fixed_coupon_becomes_a_discount_line_and_a_reserved_redemption(): void
    {
        $this->coupon(['code' => 'welcome-500', 'kind' => 'fixed', 'value' => 500]);

        $sale = $this->inside(function (): Transaction {
            $cart = $this->cart([$this->knit => 1, $this->cap => 1]);
            // 全角・小文字・前後の空白でも同じコード
            app(CartService::class)->applyCoupon($cart, ' ｗｅｌｃｏｍｅ－５００ ');
            $view = app(CartService::class)->view($cart);
            $this->assertSame(500, $view->discount);
            $this->assertSame('−¥500', $view->discountLabel());
            // 4,400 + 3,300 − 500 = 7,200（10,000 未満なので送料 550）
            $this->assertSame(7750, $view->total());

            return $this->place($cart);
        });

        $this->inside(function () use ($sale): void {
            $this->assertSame(7750, $sale->total_amount);
            $line = $sale->lines()->where('kind', 'discount')->sole();
            $this->assertSame(-500, $line->amount);
            $this->assertSame('クーポン（WELCOME-500）', $line->description);
            // 値引きは送料の前に並ぶ
            $this->assertLessThan($sale->lines()->where('kind', 'shipping')->value('position'), $line->position);

            $redemption = CouponRedemption::query()->sole();
            $this->assertSame('reserved', $redemption->status);
            $this->assertSame(500, $redemption->amount);
            // 割り振りは商品の行の金額の割合（4,400 : 3,300 → 286 : 214）、合計は値引きの額
            $this->assertSame(500, array_sum($redemption->allocations));
            $this->assertSame([286, 214], array_values($redemption->allocations));
            $this->assertNotNull($redemption->customer_account_id);
            $this->assertSame(1, DomainEvent::query()->where('type', 'coupon.redeemed')->count());
            // 注文の後はカートのコードも消える
            $this->assertNull(Cart::query()->value('coupon_code'));

            // 注文の様子の画面とメールにも値引きの行
            $order = app(OrderReader::class)->read($sale);
            $this->assertSame([['title' => 'クーポン（WELCOME-500）', 'amountLabel' => '−¥500']], $order->discounts);
        });
    }

    public function test_a_percent_coupon_only_discounts_its_category_rounds_down_and_respects_the_cap(): void
    {
        $category = $this->inside(function (): string {
            $category = Category::create(['name' => '帽子', 'slug' => 'caps', 'position' => 0]);
            Listing::query()->findOrFail($this->cap)->categories()->attach($category->id);

            return $category->id;
        });
        $this->coupon(['code' => 'CAP15', 'kind' => 'percent', 'value' => 15, 'target' => 'categories', 'target_ids' => [$category]]);

        $this->inside(function (): void {
            $cart = $this->cart([$this->knit => 1, $this->cap => 1]);
            app(CartService::class)->applyCoupon($cart, 'cap15');
            // キャップ 3,300 の 15% = 495
            $this->assertSame(495, app(CartService::class)->view($cart)->discount);
        });

        // 上限の額
        $this->inside(fn () => Coupon::query()->where('code', 'CAP15')->update(['max_discount' => 300]));
        $this->inside(function (): void {
            $cart = $this->cart([$this->cap => 1]);
            app(CartService::class)->applyCoupon($cart, 'CAP15');
            $this->assertSame(300, app(CartService::class)->view($cart)->discount);

            // 対象の商品が無いカート
            $other = $this->cart([$this->knit => 1]);
            app(CartService::class)->applyCoupon($other, 'CAP15');
            $view = app(CartService::class)->view($other);
            $this->assertSame(0, $view->discount);
            $this->assertSame('このクーポンの対象の商品が、カートにありません。', $view->couponNotice);
        });
    }

    public function test_free_shipping_waives_the_fee_and_the_threshold_uses_the_discounted_subtotal(): void
    {
        $this->coupon(['code' => 'SHIPFREE', 'kind' => 'free_shipping', 'value' => 0]);
        $this->coupon(['code' => 'OFF1000', 'kind' => 'fixed', 'value' => 1000]);

        $this->inside(function (): void {
            $cart = $this->cart([$this->knit => 1]);
            app(CartService::class)->applyCoupon($cart, 'SHIPFREE');
            $view = app(CartService::class)->view($cart);
            $this->assertSame(0, $view->shipping);
            $this->assertSame('無料（クーポン）', $view->shippingLabel());
            $sale = $this->place($cart);
            $this->assertSame(4400, $sale->total_amount);
            $this->assertFalse($sale->lines()->where('kind', 'shipping')->exists());
            // 使った額は送料の分
            $this->assertSame(550, CouponRedemption::query()->sole()->amount);

            // 4,400 × 3 = 13,200 − 1,000 = 12,200 は 10,000 以上で送料無料。× 2 = 8,800 − 1,000 は送料あり
            $big = $this->cart([$this->knit => 3]);
            app(CartService::class)->applyCoupon($big, 'OFF1000');
            $this->assertSame(0, app(CartService::class)->view($big)->shipping);
            $edge = $this->cart([$this->knit => 1, $this->cap => 1]);
            // 7,700 → 6,700。値引きの前でなく後で決める（前なら同じく送料あり。下の境で見る）
            app(CartService::class)->applyCoupon($edge, 'OFF1000');
            $this->assertSame(550, app(CartService::class)->view($edge)->shipping);
        });

        // 値引きの前なら無料、後なら送料あり（10,400 − 1,000 = 9,400）
        $this->inside(function (): void {
            Listing::query()->whereKey($this->cap)->update(['unit_amount' => 6000]);
            ListingVariant::query()->where('listing_id', $this->cap)->update(['unit_amount' => 6000]);
            $cart = $this->cart([$this->knit => 1, $this->cap => 1]);
            $this->assertSame(0, app(CartService::class)->view($cart)->shipping);
            app(CartService::class)->applyCoupon($cart, 'OFF1000');
            $this->assertSame(550, app(CartService::class)->view($cart)->shipping);
        });
    }

    public function test_a_coupon_that_brings_the_total_to_zero_places_the_order_without_payment(): void
    {
        // 送料 0 円の店で 100% 引き（「0 円の品を売る」は切のまま）。値段のある品なので、支払いを飛ばして確定する（ADR 0054）
        $this->inside(fn () => app(ShopSettingsService::class)->setShipping(['fee' => 0, 'free_over' => null]));
        $this->coupon(['code' => 'ALLFREE', 'kind' => 'percent', 'value' => 100]);

        $this->inside(function (): void {
            $cart = $this->cart([$this->cap => 1]);
            app(CartService::class)->applyCoupon($cart, 'ALLFREE');
            $this->assertSame(0, app(CartService::class)->view($cart)->total());

            $sale = $this->place($cart);
            $this->assertSame(0, $sale->total_amount);
            $this->assertSame(Phase::Payment, $sale->refresh()->phase);
            $this->assertSame(1, DomainEvent::query()->where('type', 'payment.not_required')->count());
            $this->assertSame(3300, CouponRedemption::query()->sole()->amount);
        });
    }

    public function test_coupons_that_cannot_be_used_say_why(): void
    {
        $this->coupon(['code' => 'PAUSED', 'kind' => 'fixed', 'value' => 100, 'status' => 'paused']);
        $this->coupon(['code' => 'OVER5000', 'kind' => 'fixed', 'value' => 100, 'min_subtotal' => 5000]);
        $this->coupon(['code' => 'ENDED', 'kind' => 'fixed', 'value' => 100, 'ends_at' => now()->subMinute()]);
        $this->coupon(['code' => 'MEMBERS', 'kind' => 'fixed', 'value' => 100, 'members_only' => true]);

        $this->inside(function (): void {
            $carts = app(CartService::class);
            $cart = $this->cart([$this->cap => 1]);

            foreach (['NOPE-123', 'PAUSED', 'x', str_repeat('A', 40)] as $code) {
                try {
                    $carts->applyCoupon($cart, $code);
                    $this->fail("{$code} が入った");
                } catch (CartException $e) {
                    $this->assertSame(CouponService::UNKNOWN, $e->getMessage());
                }
            }

            foreach (['OVER5000' => 'このクーポンは、商品の合計が ¥5,000 以上で使えます。', 'ENDED' => 'このクーポンは、利用期間が終了しました。', 'MEMBERS' => 'このクーポンは会員の方だけが使えます。ログインしてからお使いください。'] as $code => $reason) {
                $carts->applyCoupon($cart, $code);
                $view = $carts->view($cart);
                $this->assertSame($reason, $view->couponNotice);
                $this->assertSame(0, $view->discount);
                $this->assertFalse($view->couponApplied());
            }
        });
    }

    public function test_the_usage_limit_is_counted_under_the_lock_and_released_when_the_order_is_never_paid(): void
    {
        $this->coupon(['code' => 'ONCE', 'kind' => 'fixed', 'value' => 300, 'usage_limit' => 1, 'per_customer_limit' => null]);

        $first = $this->inside(function (): Transaction {
            // 2 つのカートで同時に確かめの画面まで進む（どちらも使える）
            $a = $this->cart([$this->cap => 1]);
            $b = $this->cart([$this->cap => 1]);
            app(CartService::class)->applyCoupon($a, 'ONCE');
            app(CartService::class)->applyCoupon($b, 'ONCE');
            $fingerprintB = CheckoutSummary::fingerprintOf(app(CartService::class)->view($b));
            $sale = $this->place($a);

            // 先の注文が最後の 1 回を使った。後のカートは、確かめた指紋のままでも錠の中で数え直して断り、理由はクーポンの文で
            try {
                app(PlaceOrder::class)->place($b, $this->input('jiro@example.com'), $this->key(), $fingerprintB);
                $this->fail('上限を超えて使えた');
            } catch (OrderException $e) {
                $this->assertStringStartsWith(CouponService::USED_UP, $e->getMessage());
                $this->assertTrue($e->changed);
            }
            $this->assertSame(1, Transaction::query()->count());

            return $sale;
        });

        // 払われずに取り消された注文は、数を戻す
        $this->inside(function () use ($first): void {
            app(PaymentService::class)->startCheckout($first, 'x', 'y');
            $this->assertTrue(app(SaleOrderService::class)->cancelIfUnpaid($first, 'test'));
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($first->id)->status);
            $this->assertSame('released', CouponRedemption::query()->sole()->status);
            $this->assertSame(1, DomainEvent::query()->where('type', 'coupon.released')->count());

            $again = $this->cart([$this->cap => 1]);
            app(CartService::class)->applyCoupon($again, 'ONCE');
            $this->assertSame(300, app(CartService::class)->view($again)->discount);
        });
    }

    public function test_the_per_customer_limit_follows_the_email_even_for_guests(): void
    {
        $this->coupon(['code' => 'FIRST', 'kind' => 'fixed', 'value' => 300, 'per_customer_limit' => 1]);

        $this->inside(function (): void {
            $first = $this->cart([$this->cap => 1]);
            app(CartService::class)->applyCoupon($first, 'FIRST');
            $this->place($first, 'hanako@example.com');

            // 同じメール（大文字でも同じ台帳）: 確定で断り、注文を作らない
            $second = $this->cart([$this->cap => 1]);
            app(CartService::class)->applyCoupon($second, 'FIRST');

            try {
                $this->place($second, 'HANAKO@example.com');
                $this->fail('同じお客さんが 2 回使えた');
            } catch (OrderException $e) {
                $this->assertStringStartsWith(CouponService::USED_BY_YOU, $e->getMessage());
            }
            $this->assertSame(1, Transaction::query()->count());

            // ほかのお客さんは使える
            // 3,300 − 300 + 送料 550
            $this->assertSame(3550, $this->place($second, 'jiro@example.com')->total_amount);
        });
    }

    public function test_an_order_is_not_placed_when_the_coupon_changed_after_the_confirmation(): void
    {
        $this->coupon(['code' => 'SALE', 'kind' => 'fixed', 'value' => 300]);

        $this->inside(function (): void {
            $cart = $this->cart([$this->cap => 1]);
            app(CartService::class)->applyCoupon($cart, 'SALE');
            $fingerprint = CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart));
            // 確かめた後に店が止めた: 値引きが消えて額が変わるので確定しない
            Coupon::query()->where('code', 'SALE')->update(['status' => 'paused']);

            try {
                app(PlaceOrder::class)->place($cart, $this->input(), $this->key(), $fingerprint);
                $this->fail('確認した額と違う注文ができた');
            } catch (OrderException $e) {
                $this->assertTrue($e->changed);
            }
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, CouponRedemption::query()->count());
        });
    }

    public function test_the_cart_page_has_the_form_and_guessing_codes_is_rate_limited(): void
    {
        $this->coupon(['code' => 'HELLO', 'kind' => 'fixed', 'value' => 200]);
        $this->send('POST', '/cart/items', ['listing_id' => $this->cap, 'quantity' => '1'])->assertRedirect();

        $page = (string) $this->send('GET', '/cart')->assertOk()->getContent();
        $this->assertStringContainsString('data-test="coupon-form"', $page);

        $this->send('POST', '/cart/coupon', ['code' => 'nope-999'])->assertRedirect()->assertSessionHas('cart_error', CouponService::UNKNOWN);
        $this->send('POST', '/cart/coupon', ['code' => 'hello'])->assertRedirect()->assertSessionHas('cart_message', 'クーポンを適用しました。');
        $page = (string) $this->send('GET', '/cart')->assertOk()->getContent();
        $this->assertStringContainsString('−¥200', $page);
        $this->assertStringContainsString('data-test="coupon-code">HELLO<', $page);
        // JSON の答えにも値引き
        $this->assertSame('−¥200', $this->withHeader('Accept', 'application/json')->send('GET', '/cart')->json('cart.discount_label'));
        $this->flushHeaders();

        $this->send('POST', '/cart/coupon/remove')->assertRedirect();
        $this->assertStringNotContainsString('−¥200', (string) $this->send('GET', '/cart')->getContent());

        // 10 分に 10 回まで（入れ直しも 1 回に数える）
        for ($i = 0; $i < 8; $i++) {
            $this->send('POST', '/cart/coupon', ['code' => "guess-{$i}0"]);
        }
        $this->send('POST', '/cart/coupon', ['code' => 'HELLO'])->assertStatus(429);
    }

    public function test_allocation_always_adds_up_and_hands_out_remainders_by_size(): void
    {
        $this->assertSame([1 => 34, 2 => 33, 3 => 33], Allocation::byWeight(100, [1 => 1000, 2 => 1000, 3 => 1000]));
        $this->assertSame([5 => 0, 7 => 1], Allocation::byWeight(1, [5 => 100, 7 => 300]));
        $this->assertSame([1 => 0], Allocation::byWeight(0, [1 => 500]));
        $this->expectException(\LogicException::class);
        Allocation::byWeight(501, [1 => 500]);
    }

    public function test_codes_are_normalized_and_bad_shapes_are_refused(): void
    {
        $this->assertSame('SUMMER-10', CouponService::normalize(' summer-10 '));
        $this->assertSame('ABC', CouponService::normalize('ａｂｃ'));
        $this->assertNull(CouponService::normalize('ab'));
        $this->assertNull(CouponService::normalize('a b c'));
        $this->assertNull(CouponService::normalize('-ABC'));
        $this->assertNull(CouponService::normalize('<script>'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function coupon(array $values): void
    {
        $this->inside(fn () => app(CouponService::class)->create($values + ['name' => 'テスト'], 'test'));
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

    private function place(Cart $cart, string $email = 'hanako@example.com'): Transaction
    {
        return app(PlaceOrder::class)->place($cart, $this->input($email), $this->key(), CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart)));
    }

    private function input(string $email = 'hanako@example.com'): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => $email, 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null]);
        $this->assertNotNull($input);

        return $input;
    }

    private function key(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, string>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $url = "http://cpn.localhost{$path}";
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
