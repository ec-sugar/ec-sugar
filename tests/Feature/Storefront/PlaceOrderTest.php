<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Crypto\TenantCipher;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Models\Party;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartLine;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 注文の確定。取引（sale）を合意で開いて在庫を押さえ、お客さんとお届け先は暗号化して残す。
 * 二重送信は 1 件に、確認の画面から内容が変わったら確定しない。受け付けた画面は確定した本人だけ。
 */
final class PlaceOrderTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private ?string $token = null;

    private string $knit;

    private string $cap;

    private string $lastKey = '';

    private FakeStripe $stripe;

    /** @var array<string, mixed> */
    private array $valid = [
        'buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''],
        'same_as_buyer' => '0',
        'recipient' => ['name' => '鈴木 一郎', 'phone' => '03-1234-5678', 'postal' => '060-0000', 'prefecture' => '北海道', 'city' => '札幌市中央区', 'line1' => '北一条 1', 'line2' => '2 階'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->stripe = FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'pla', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'pla.localhost']);
        [$this->knit, $this->cap] = $this->inside(function (): array {
            $seller = Seller::query()->value('id');
            $knit = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            $cap = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'キャップ', 'unit_amount' => 3300, 'status' => 'active']);
            // ニットは 2 つの拠点に 1 点ずつ（数は拠点をまたいで割り当てる）
            $second = Location::create(['seller_id' => $seller, 'name' => '第二倉庫', 'kind' => 'warehouse']);
            app(StockService::class)->receive($knit, Location::query()->orderBy('id')->firstOrFail(), 1);
            app(StockService::class)->receive($knit, $second, 1);
            app(StockService::class)->receive($cap, Location::query()->orderBy('id')->firstOrFail(), 5);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 20000]);
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

    public function test_placing_an_order_opens_a_sale_reserves_stock_and_empties_the_cart(): void
    {
        $this->addToCart($this->knit, 2);
        $this->addToCart($this->cap, 1);
        $form = $this->confirm();

        $this->assertToStripe($this->send('POST', '/checkout/confirm', $form));
        $complete = (string) $this->send('GET', '/checkout/complete')->assertOk()->getContent();
        // 4,400 × 2 + 3,300 + 送料 550
        $this->assertStringContainsString('¥12,650', $complete);

        $this->inside(function () use ($complete): void {
            $transaction = Transaction::query()->sole();
            $this->assertSame('sale', $transaction->kind);
            $this->assertSame(Phase::Agreement, $transaction->phase);
            $this->assertSame(12650, $transaction->total_amount);
            $this->assertStringContainsString(strtoupper($transaction->id), $complete);
            // 明細: ニットは拠点ごとに 1 点ずつ、キャップ、送料（調整の行）
            $amounts = $transaction->lines()->pluck('amount')->map(fn ($n) => (int) $n)->sortDesc()->values()->all();
            $this->assertSame([4400, 4400, 3300, 550], $amounts);

            // 在庫を押さえた（数は減らず、確保が増える）
            $this->assertSame([1, 1], InventoryStock::query()->where('listing_id', $this->knit)->orderBy('location_id')->pluck('reserved')->map(fn ($n) => (int) $n)->all());
            $this->assertSame(1, (int) InventoryStock::query()->where('listing_id', $this->cap)->value('reserved'));

            // 受渡は拠点ごと、お届け先は暗号化して（列は読めない、モデルを通すと読める）
            $fulfillments = Fulfillment::query()->where('transaction_id', $transaction->id)->get();
            $this->assertCount(2, $fulfillments);
            foreach ($fulfillments as $fulfillment) {
                $this->assertSame('鈴木 一郎', $fulfillment->address['name']);
                $this->assertSame('0600000', $fulfillment->address['zip']);
                $this->assertSame('札幌市中央区', $fulfillment->address['city']);
            }
            // （平文の JSON は日本語を \uXXXX で書くので、文字が無いことだけでは確かめにならない）
            foreach (DB::table('fulfillments')->where('transaction_id', $transaction->id)->pluck('address') as $raw) {
                $this->assertStringStartsWith(TenantCipher::PREFIX, (string) $raw);
            }

            // お客さんはご依頼主。列は暗号化
            $customer = Party::query()->findOrFail($transaction->customer_id);
            $this->assertSame('山田 花子', $customer->name);
            $this->assertSame('hanako@example.com', $customer->email);
            $raw = DB::table('parties')->where('id', $customer->id)->first(['name', 'email', 'phone', 'address']);
            foreach (['name', 'email', 'phone', 'address'] as $column) {
                $this->assertStringStartsWith(TenantCipher::PREFIX, (string) $raw?->{$column}, $column);
            }

            // 台帳のイベントに個人情報を入れない
            $payloads = DomainEvent::query()->pluck('payload')->map(fn ($p) => json_encode($p, JSON_UNESCAPED_UNICODE))->implode("\n");
            foreach (['山田', '鈴木', 'hanako', '09012345678', '渋谷', '札幌', '0600000'] as $pii) {
                $this->assertStringNotContainsString($pii, $payloads, $pii);
            }

            // カートは空に。合い言葉は sha256 だけ残す
            $this->assertSame(0, CartLine::query()->count());
            $this->assertSame(hash('sha256', $this->lastKey), CheckoutOrder::query()->sole()->key_hash);
        });

        // 確定した後は、確認の画面に戻れない（入力も消える）
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://pla.localhost/cart');
    }

    public function test_a_double_submit_makes_one_order(): void
    {
        $this->addToCart($this->cap, 2);
        $form = $this->confirm();

        $this->assertToStripe($this->send('POST', '/checkout/confirm', $form));
        // 二回目（戻るボタン・二度押し）は、同じ注文の受け付けた画面へ
        $this->send('POST', '/checkout/confirm', $form)->assertRedirect('http://pla.localhost/checkout/complete');

        // 同じ合い言葉で処理まで届いても（同時に押して、ロックの後ろで待っていた方）、最初の取引を返す
        $this->inside(function () use ($form): void {
            $again = app(PlaceOrder::class)->place(Cart::query()->sole(), $this->input(), $form['order_key'], 'anything');
            $this->assertSame(Transaction::query()->sole()->id, $again->id);
            $this->assertSame(1, CheckoutOrder::query()->count());
            $this->assertSame(1, Party::query()->count());
            $this->assertSame(2, (int) InventoryStock::query()->where('listing_id', $this->cap)->value('reserved'));
        });
    }

    public function test_an_order_is_refused_when_it_changed_since_the_confirmation(): void
    {
        $this->addToCart($this->cap, 1);
        $form = $this->confirm();

        // 確認の画面を見た後に値上げ
        $this->inside(fn () => Listing::query()->findOrFail($this->cap)->update(['unit_amount' => 3500]));
        $this->send('POST', '/checkout/confirm', $form)->assertRedirect('http://pla.localhost/checkout/confirm');
        $html = (string) $this->send('GET', '/checkout/confirm')->getContent();
        $this->assertStringContainsString('ご注文の内容が変わりました', $html);
        $this->assertStringContainsString('¥3,500', $html);
        $this->assertNoOrder();

        // 画面を見直してから押すと通る（新しい指紋）
        $this->assertToStripe($this->send('POST', '/checkout/confirm', $this->formOf($html)));
        $this->inside(fn () => $this->assertSame(4050, Transaction::query()->sole()->total_amount));
    }

    public function test_tampered_or_foreign_keys_and_missing_stock_are_refused(): void
    {
        $this->addToCart($this->cap, 1);
        $form = $this->confirm();

        // 合い言葉が無い・違う・形が違う・配列。指紋が無い・違う
        foreach ([
            [...$form, 'order_key' => ''],
            [...$form, 'order_key' => str_repeat('A', 43)],
            [...$form, 'order_key' => [$form['order_key']]],
            [...$form, 'fingerprint' => ''],
            [...$form, 'fingerprint' => str_repeat('0', 64)],
            [...$form, 'fingerprint' => ['x']],
            ['_token' => $form['_token'] ?? null],
        ] as $tampered) {
            $this->send('POST', '/checkout/confirm', $tampered)->assertRedirect('http://pla.localhost/checkout/confirm');
        }
        $this->assertNoOrder();

        // 別のお客さん（別のセッション・別のカート）が、見えた合い言葉と指紋を送っても確定しない
        [$mine, $session] = [$this->token, app('session')->all()];
        $this->token = null;
        $this->flushSession();
        $this->addToCart($this->cap, 1);
        $this->send('POST', '/checkout', $this->valid);
        $this->send('POST', '/checkout/confirm', $form)->assertRedirect('http://pla.localhost/checkout/confirm');
        $this->assertNoOrder();

        // 確認の画面の後に売り切れた。カートへ戻して理由を出す
        $this->token = $mine;
        $this->flushSession();
        $this->session($session);
        $this->inside(fn () => app(StockService::class)->adjust(Listing::query()->findOrFail($this->cap), Location::query()->orderBy('id')->firstOrFail(), -5));
        $this->send('POST', '/checkout/confirm', $form)->assertRedirect('http://pla.localhost/cart');
        $this->send('GET', '/cart')->assertSee('現在ご購入いただけない商品があります');
        $this->assertNoOrder();
        // 確認の画面にも進めない
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://pla.localhost/cart');
    }

    public function test_the_complete_page_shows_only_my_order(): void
    {
        // 確定していなければ、店のトップへ
        $this->send('GET', '/checkout/complete')->assertRedirect('http://pla.localhost');

        $this->addToCart($this->cap, 1);
        $this->send('POST', '/checkout/confirm', $this->confirm());

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $html = (string) $this->send('GET', '/checkout/complete')->assertOk()->getContent();
            $this->assertStringContainsString('data-test="order-complete"', $html, $theme);
            $this->assertStringContainsString('¥3,850', $html, $theme);
            // 個人情報は出さない
            $this->assertStringNotContainsString('山田', $html, $theme);
            $this->assertStringNotContainsString('鈴木', $html, $theme);
        }

        // 別のセッションでは見えない（番号を知っていても）
        $this->token = null;
        $this->flushSession();
        $this->send('GET', '/checkout/complete')->assertRedirect('http://pla.localhost');
    }

    public function test_a_shop_without_payment_keys_takes_no_orders(): void
    {
        $this->inside(fn () => app(PaymentGatewayService::class)->removeStripe());
        $this->addToCart($this->cap, 1);
        $this->send('POST', '/checkout', $this->valid);

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $html = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
            $this->assertStringContainsString('data-test="payment-unavailable"', $html, $theme);
            $this->assertStringNotContainsString('data-test="place-order"', $html, $theme);
        }

        // 画面を通さずに（正しい合い言葉と指紋で）送っても、注文は作らない（在庫を押さえたままの払えない注文を作らない）
        $fingerprint = $this->inside(fn (): string => CheckoutSummary::fingerprintOf(app(CartService::class)->view(Cart::query()->sole())));
        $this->send('POST', '/checkout/confirm', ['order_key' => (string) session('checkout_order_key'), 'fingerprint' => $fingerprint])
            ->assertRedirect('http://pla.localhost/checkout/confirm');
        $this->assertNoOrder();
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_the_complete_page_leads_to_payment_and_never_trusts_the_return(): void
    {
        $this->addToCart($this->cap, 1);
        // Stripe に届かない: 注文は残り、受け付けた画面に理由と「お支払いへ進む」
        $this->stripe->respond = static fn (): array => ['{"error":{"type":"api_error","message":"down"}}', 500, []];
        $this->send('POST', '/checkout/confirm', $this->confirm())->assertRedirect('http://pla.localhost/checkout/complete');
        $this->inside(fn () => $this->assertSame(1, Transaction::query()->count()));

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $html = (string) $this->send('GET', '/checkout/complete')->assertOk()->getContent();
            $this->assertStringContainsString('data-test="payment-due"', $html, $theme);
            $this->assertStringContainsString('action="http://pla.localhost/checkout/pay"', $html, $theme);
        }
        $this->stripe->respond = null;

        // 「お支払いへ進む」で Stripe の画面へ。戻ってきた印があっても、Webhook で確かめるまでは「確かめています」
        $this->assertToStripe($this->send('POST', '/checkout/pay'));
        $html = (string) $this->send('GET', '/checkout/complete?returned=1')->getContent();
        $this->assertStringContainsString('data-test="payment-pending"', $html);
        $this->assertStringNotContainsString('data-test="payment-paid"', $html);
        $this->assertStringContainsString('data-test="pay"', $html);

        // 確定した本人のセッションでなければ、払いに進めない
        $this->flushSession();
        $this->send('POST', '/checkout/pay')->assertRedirect('http://pla.localhost');
        $this->assertCount(2, $this->stripe->requests);
    }

    public function test_the_last_item_goes_to_only_one_of_two_customers(): void
    {
        // 最後の 1 点を、二人が同じ時に確認の画面で見て確定する。先に確定した方だけが通り、後の方は在庫の確保で断られる
        $this->inside(function (): void {
            app(StockService::class)->adjust(Listing::query()->findOrFail($this->cap), Location::query()->orderBy('id')->firstOrFail(), -4);
            $carts = app(CartService::class);
            $seen = [];
            foreach (['一人目', '二人目'] as $who) {
                [$cart] = $carts->create();
                $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->cap)->value('id'), 1);
                $seen[$who] = [$cart, CheckoutSummary::fingerprintOf($carts->view($cart))];
            }

            $orders = app(PlaceOrder::class);
            $orders->place($seen['一人目'][0], $this->input(), str_repeat('a', 43), $seen['一人目'][1]);

            try {
                $orders->place($seen['二人目'][0], $this->input(), str_repeat('b', 43), $seen['二人目'][1]);
                $this->fail('売り越した');
            } catch (OrderException $e) {
                $this->assertSame(PlaceOrder::UNAVAILABLE, $e->getMessage());
                $this->assertFalse($e->changed);
            }

            $this->assertSame(1, Transaction::query()->count());
            // 断られた方は何も残さない（お客さんの行も、合い言葉も）。カートはそのまま
            $this->assertSame(1, Party::query()->count());
            $this->assertSame(1, CheckoutOrder::query()->count());
            $this->assertSame(1, CartLine::query()->where('cart_id', $seen['二人目'][0]->id)->count());
            $stock = InventoryStock::query()->where('listing_id', $this->cap)->sole();
            $this->assertSame([1, 1], [(int) $stock->quantity, (int) $stock->reserved]);

            // 買えない行（在庫より多い数）を黙って外して確定しない。その時の内容の指紋を添えても断る
            [$cart] = $carts->create();
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->knit)->value('id'), 2);
            app(StockService::class)->adjust(Listing::query()->findOrFail($this->knit), Location::query()->orderBy('id')->firstOrFail(), -1);
            app(StockService::class)->receive(Listing::query()->findOrFail($this->cap), Location::query()->orderBy('id')->firstOrFail(), 1);
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->cap)->value('id'), 1);
            $this->assertTrue($carts->view($cart)->hasUnavailable());

            try {
                $orders->place($cart, $this->input(), str_repeat('c', 43), CheckoutSummary::fingerprintOf($carts->view($cart)));
                $this->fail('買えない行を外して確定した');
            } catch (OrderException $e) {
                $this->assertSame(PlaceOrder::UNAVAILABLE, $e->getMessage());
            }
            $this->assertSame(1, Transaction::query()->count());
        });
    }

    private function input(): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => null], 'recipient' => null]);
        $this->assertNotNull($input);

        return $input;
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertToStripe(TestResponse $response): void
    {
        $response->assertStatus(303);
        $this->assertStringStartsWith('https://checkout.stripe.com/c/pay/cs_test_', (string) $response->headers->get('Location'));
    }

    private function addToCart(string $listingId, int $quantity): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $listingId, 'quantity' => (string) $quantity])->assertRedirect();
    }

    /**
     * 入力して確認の画面を開き、確定のフォームの値を取り出す
     *
     * @return array<string, string>
     */
    private function confirm(): array
    {
        $this->send('POST', '/checkout', $this->valid)->assertRedirect('http://pla.localhost/checkout/confirm');

        return $this->formOf((string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent());
    }

    /**
     * @return array<string, string>
     */
    private function formOf(string $html): array
    {
        preg_match('/name="order_key" value="([^"]+)"/', $html, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);
        $this->assertNotEmpty($key[1] ?? null, '確定のフォームにトークンが無い');
        $this->assertSame(64, strlen($fingerprint[1] ?? ''));
        $this->lastKey = $key[1];

        return ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]];
    }

    private function assertNoOrder(): void
    {
        $this->inside(function (): void {
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, CheckoutOrder::query()->count());
            $this->assertSame(0, Party::query()->count());
            $this->assertSame(0, (int) InventoryStock::query()->sum('reserved'));
        });
    }

    /**
     * Cookie（カートの合い言葉とセッション）を持ち越して送る
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $url = "http://pla.localhost{$path}";
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
