<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Discount\Services\CouponService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Models\OrderShipping;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Shop\Services\ShippingMethods;
use App\Core\Shop\Shipping\Regions;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Orders\OrderReader;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 送料の表（ADR 0064）をショップの注文の流れで: 都道府県ごとの額・カートの目安・方法の違う品は分けて便ごとの送料・送料無料の品・
 * 方法ごとの「この金額以上で無料」（クーポンの値引きの後）・送料無料のクーポン・確かめの後に表が変わったら確定しない
 */
final class ShippingRatesTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private ?string $token = null;

    /** @var array<string, string> */
    private array $items = [];

    /** @var array<string, string> */
    private array $methods = [];

    private const array TOKYO = ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''];

    private const array SAPPORO = ['name' => '鈴木 一郎', 'phone' => '0111234567', 'postal' => '0600001', 'prefecture' => '北海道', 'city' => '札幌市中央区', 'line1' => '北一条西 2-1', 'line2' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'shr', 'name' => '送料の店']);
        $this->tenant->domains()->create(['domain' => 'shr.localhost']);
        [$this->methods, $this->items] = $this->inside(function (): array {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            $table = app(ShippingMethods::class);
            $normal = [...Regions::flat(800), '北海道' => 1500, '沖縄県' => 2000];
            $cool = [...Regions::flat(1200), '北海道' => 2000, '沖縄県' => 2500];
            $saved = $table->replace(['methods' => [
                ['id' => $table->all()[0]['id'], 'name' => '通常便', 'free_over' => 10000, 'rates' => $normal],
                ['id' => null, 'name' => 'クール便', 'free_over' => null, 'rates' => $cool],
            ]], 'test');
            $methods = ['normal' => $saved[0]['id'], 'cool' => $saved[1]['id']];
            $seller = Seller::query()->value('id');
            $location = Location::query()->firstOrFail();
            $items = [];
            foreach ([
                'knit' => ['ニット', 4000, null, false],
                'cake' => ['冷凍のケーキ', 3000, $methods['cool'], false],
                'towel' => ['タオル（送料無料）', 1000, null, true],
            ] as $key => [$title, $amount, $method, $free]) {
                $listing = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => 'active', 'shipping_method_id' => $method, 'free_shipping' => $free]);
                app(StockService::class)->receive($listing, $location, 10);
                $items[$key] = $listing->id;
            }

            return [$methods, $items];
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

    public function test_the_cart_shows_a_range_and_the_confirmation_uses_the_prefecture(): void
    {
        $this->add('knit', 1);
        // カートは住所を知らないので目安（いちばん安い地域〜高い地域）
        $cart = (string) $this->send('GET', '/cart')->assertOk()->getContent();
        $this->assertStringContainsString('¥800〜¥2,000', $cart);
        $this->assertStringContainsString('¥4,800〜¥6,000', $cart);
        $this->assertStringContainsString('送料はお届け先の都道府県で決まります', $cart);
        $this->assertStringContainsString('あと ¥6,000 で通常便の送料無料', $cart);

        // 東京へは ¥800、北海道へは ¥1,500（確かめの画面で確定の額）
        $this->assertStringContainsString('¥4,800', $this->confirmPage(null));
        $page = $this->confirmPage(self::SAPPORO);
        $this->assertStringContainsString('¥1,500（通常便）', $page);
        $this->assertStringContainsString('¥5,500', $page);

        $this->send('POST', '/checkout/confirm', $this->formOf($page))->assertStatus(303);
        $this->inside(function (): void {
            $transaction = Transaction::query()->sole();
            $this->assertSame(5500, (int) $transaction->total_amount);
            $shipping = $transaction->lines()->where('kind', 'shipping')->sole();
            $this->assertSame([1500, '送料（通常便）'], [(int) $shipping->amount, $shipping->description]);
            // 便の決め方を残す（都道府県は残さない）
            $row = OrderShipping::query()->sole();
            $this->assertSame(['通常便', 1500, 'rate', null], [$row->method_name, $row->fee, $row->basis, $row->rates]);
            $payload = DomainEvent::query()->where('type', 'order.shipping_quoted')->sole()->payload;
            $this->assertEquals([['method_id' => $this->methods['normal'], 'method' => '通常便', 'fee' => 1500, 'basis' => 'rate']], $payload['groups']);
            $this->assertStringNotContainsString('北海道', (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
            $this->assertSame('通常便', Fulfillment::query()->sole()->shipping_method);
        });
    }

    public function test_items_with_different_methods_ship_separately_and_a_free_item_frees_its_parcel(): void
    {
        $this->add('knit', 1);
        $this->add('cake', 1);
        $page = $this->confirmPage(null);
        $this->assertStringContainsString('¥2,000（通常便 ¥800・クール便 ¥1,200）', $page);
        $this->assertStringContainsString('配送方法の異なる商品は別々にお送りする', $page);
        $this->send('POST', '/checkout/confirm', $this->formOf($page))->assertStatus(303);

        $this->inside(function (): void {
            $transaction = Transaction::query()->sole();
            $this->assertSame(4000 + 3000 + 800 + 1200, (int) $transaction->total_amount);
            $this->assertSame(['送料（通常便）' => 800, '送料（クール便）' => 1200], $transaction->lines()->where('kind', 'shipping')->orderBy('position')->get()->mapWithKeys(static fn ($l): array => [$l->description => (int) $l->amount])->all());
            // 受渡は便ごと（同じ拠点でも分ける）
            $parcels = Fulfillment::query()->where('transaction_id', $transaction->id)->with('items')->get();
            $this->assertEqualsCanonicalizing(['通常便', 'クール便'], $parcels->pluck('shipping_method')->all());
            $this->assertSame([1, 1], $parcels->map(static fn (Fulfillment $f): int => $f->items->count())->all());
            $this->assertSame('¥2,000（通常便 ¥800・クール便 ¥1,200）', app(OrderReader::class)->read($transaction)->shippingLabel);
        });

        // 送料無料の品が入った便は無料（同梱の品も）。別の便には効かない
        $this->add('knit', 1);
        $this->add('towel', 1);
        $this->add('cake', 1);
        $page = $this->confirmPage(null);
        $this->assertStringContainsString('¥1,200（通常便 無料・クール便 ¥1,200）', $page);
        $this->assertStringContainsString('¥9,200', $page);
    }

    public function test_free_over_is_per_method_and_after_the_coupon_and_the_free_shipping_coupon_frees_every_parcel(): void
    {
        // 通常便の品 ¥12,000 は無料の線（¥10,000）を超える。クール便は無料の線が無い
        $this->add('knit', 3);
        $this->add('cake', 1);
        $this->assertStringContainsString('¥1,200（通常便 無料・クール便 ¥1,200）', $this->confirmPage(null));

        // ¥3,000 引き（値引きは対象の品へ割合で配る）: 通常便の品は ¥12,000 − ¥2,400 = ¥9,600 で線を下回る
        $this->inside(fn () => app(CouponService::class)->create(['code' => 'OFF3000', 'kind' => 'fixed', 'value' => 3000, 'name' => 'テスト'], 'test'));
        $this->send('POST', '/cart/coupon', ['code' => 'off3000'])->assertRedirect();
        $page = $this->confirmPage(null);
        $this->assertStringContainsString('¥2,000（通常便 ¥800・クール便 ¥1,200）', $page);
        $this->assertStringContainsString('¥14,000', $page);

        // 送料無料のクーポンは、すべての便を無料に
        $this->inside(fn () => app(CouponService::class)->create(['code' => 'SHIPFREE', 'kind' => 'free_shipping', 'value' => 0, 'name' => 'テスト'], 'test'));
        $this->send('POST', '/cart/coupon', ['code' => 'shipfree'])->assertRedirect();
        $page = $this->confirmPage(self::SAPPORO);
        $this->assertStringContainsString('無料（クーポン）', $page);
        $this->send('POST', '/checkout/confirm', $this->formOf($page))->assertStatus(303);
        $this->inside(function (): void {
            $transaction = Transaction::query()->sole();
            $this->assertSame(15000, (int) $transaction->total_amount);
            $this->assertSame(0, $transaction->lines()->where('kind', 'shipping')->count());
            // 通常便はクーポンを入れ替えた後は ¥12,000 で無料の線を超える（クーポンは 1 つだけ）。クール便はクーポンで無料
            $this->assertSame(['free_over', 'coupon'], OrderShipping::query()->orderBy('position')->pluck('basis')->all());
        });
    }

    public function test_a_changed_table_after_confirming_does_not_place_the_order(): void
    {
        $this->add('knit', 1);
        $form = $this->formOf($this->confirmPage(self::SAPPORO));

        // 確かめた後に北海道の額が変わった
        $this->inside(function (): void {
            $table = app(ShippingMethods::class);
            $methods = $table->all();
            $methods[0]['rates']['北海道'] = 1800;
            $table->replace(['methods' => array_map(static fn (array $m): array => ['id' => $m['id'], 'name' => $m['name'], 'free_over' => $m['free_over'], 'rates' => $m['rates']], $methods)], 'test');
        });
        $this->send('POST', '/checkout/confirm', $form)->assertRedirect('http://shr.localhost/checkout/confirm')->assertSessionHas('cart_error');
        // 商品の方法が変わっても同じ
        $this->inside(fn () => Listing::query()->whereKey($this->items['knit'])->update(['shipping_method_id' => $this->methods['cool']]));
        $form = $this->formOf($this->confirmPage(self::SAPPORO));
        $this->inside(fn () => Listing::query()->whereKey($this->items['knit'])->update(['shipping_method_id' => null]));
        $this->send('POST', '/checkout/confirm', $form)->assertRedirect('http://shr.localhost/checkout/confirm')->assertSessionHas('cart_error');
        $this->inside(fn () => $this->assertSame(0, Transaction::query()->count()));

        // 確かめ直せば通る（北海道 ¥1,800）
        $this->send('POST', '/checkout/confirm', $this->formOf($this->confirmPage(self::SAPPORO)))->assertStatus(303);
        $this->inside(fn () => $this->assertSame(5800, (int) Transaction::query()->sole()->total_amount));

        // 額が同じでも、便の方法が変われば確定しない（クール便の東京を通常便と同じ ¥800 にして、確かめの後に商品をクール便へ）
        $this->inside(function (): void {
            $table = app(ShippingMethods::class);
            $methods = $table->all();
            $methods[1]['rates']['東京都'] = 800;
            $table->replace(['methods' => array_map(static fn (array $m): array => ['id' => $m['id'], 'name' => $m['name'], 'free_over' => $m['free_over'], 'rates' => $m['rates']], $methods)], 'test');
        });
        $this->add('knit', 1);
        $page = $this->confirmPage(null);
        $this->assertStringContainsString('¥4,800', $page);
        $this->inside(fn () => Listing::query()->whereKey($this->items['knit'])->update(['shipping_method_id' => $this->methods['cool']]));
        $this->send('POST', '/checkout/confirm', $this->formOf($page))->assertRedirect('http://shr.localhost/checkout/confirm')->assertSessionHas('cart_error');
        $this->inside(fn () => $this->assertSame(1, Transaction::query()->count()));
    }

    /**
     * 注文の入力を送り、確かめの画面を開く（お届け先が null ならご依頼主〔東京〕へ）
     *
     * @param  array<string, string>|null  $recipient
     */
    private function confirmPage(?array $recipient): string
    {
        $this->send('POST', '/checkout', ['buyer' => self::TOKYO, 'same_as_buyer' => $recipient === null ? '1' : '0', 'recipient' => $recipient])->assertRedirect('http://shr.localhost/checkout/confirm');

        return (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
    }

    /**
     * @return array<string, string>
     */
    private function formOf(string $html): array
    {
        preg_match('/name="order_key" value="([^"]+)"/', $html, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);

        return ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''];
    }

    private function add(string $item, int $quantity): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->items[$item], 'quantity' => (string) $quantity])->assertRedirect();
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

        $url = "http://shr.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(CartCookie::NAME);

        if ($cookie !== null) {
            $this->token = (string) $cookie->getValue();
        }

        return $response;
    }

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
