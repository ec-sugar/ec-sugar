<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingOption;
use App\Core\Listing\Models\ListingOptionValue;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\Contracts\PurchaseLimitProvider;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Plugin\Services\PurchaseLimits;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Checkout\PlaceSingleOrder;
use ArrayObject;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 1 回のご注文の決まり（ADR 0109。PurchaseLimitProvider）を、核のカートと注文の確定が守ること。
 * App の実物（plugin-purchase-limits）によらず、見本の口で確かめる: 商品ごとの上限・商品の合計の下限・
 * **決まりを読めない時は売らない**（落ちた App のせいで「1 回 1 点まで」の品をいくつでも売らない）
 */
final class PurchaseLimitsCartTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private string $knit;

    private string $cap;

    /** @var ArrayObject<string, mixed> 見本の口の答え（テストの途中で変える） */
    private ArrayObject $answer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        $this->answer = new ArrayObject(['per' => [], 'minimum' => 0, 'throw' => false]);
        $this->tenant = Tenant::create(['id' => 'plc', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'plc.localhost']);
        [$this->knit, $this->cap] = $this->inside(function (): array {
            $knit = $this->listing('ニット', 4400);
            $cap = $this->listing('キャップ', 1100);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 100000]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);

            return [$knit, $cap];
        });
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_nothing_changes_while_no_app_answers(): void
    {
        $this->inside(function (): void {
            // 口を持つ App が登録されていても、この店で使っていなければ聞かない
            $this->answer['per'] = [$this->knit => 1];
            $this->answer['minimum'] = 99999;
            $this->register(enabled: false);

            $cart = $this->cart([$this->knit => 3]);
            $view = app(CartService::class)->view($cart);
            $this->assertFalse($view->hasUnavailable());
            $this->assertFalse($view->belowMinimum());
            $this->assertNull($view->minimumNotice());
            $this->assertSame(CartService::MAX_QUANTITY, $view->lines[0]['maxQuantity']);
            $this->assertInstanceOf(Transaction::class, $this->place($cart));
        });
    }

    public function test_a_line_over_the_limit_of_its_product_stops_the_order(): void
    {
        $this->inside(function (): void {
            $this->answer['per'] = [$this->knit => 2];
            $this->register();
            $carts = app(CartService::class);

            $cart = $this->cart([$this->knit => 3, $this->cap => 4]);
            $view = $carts->view($cart);
            $knit = $this->lineOf($view->lines, $this->knit);
            $this->assertFalse($knit['purchasable']);
            $this->assertSame('この商品は 1 回のご注文で 2 点までです。数量を減らしてください。', $knit['notice']);
            // 数の入れ物の上限も決まりに合わせる（テーマは maxQuantity をそのまま使う）
            $this->assertSame(2, $knit['maxQuantity']);
            // 決まりの無い商品はそのまま買える。止まった行は小計に数えない
            $this->assertTrue($this->lineOf($view->lines, $this->cap)['purchasable']);
            $this->assertSame(4400, $view->subtotal);
            $this->assertTrue($view->hasUnavailable());

            try {
                $this->place($cart);
                $this->fail('上限を超えたカートの注文が通った');
            } catch (OrderException $e) {
                $this->assertSame(PlaceOrder::UNAVAILABLE, $e->getMessage());
            }

            $this->assertSame(0, Transaction::query()->count());

            // ちょうど上限なら通る
            $carts->setQuantity($cart, $knit['lineId'], 2);
            $view = $carts->view($cart);
            $this->assertFalse($view->hasUnavailable());
            $this->assertNull($this->lineOf($view->lines, $this->knit)['notice']);
            $this->assertInstanceOf(Transaction::class, $this->place($cart));
        });
    }

    public function test_the_limit_counts_every_line_of_the_same_product(): void
    {
        $this->inside(function (): void {
            // 同じ商品の種類違いを 2 行に分けても、合わせて上限まで（行ごとに数えると、種類を変えるだけで抜けられる）
            $shirt = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'シャツ', 'unit_amount' => 3300, 'status' => 'active', 'tax_class' => 'standard']);
            $shirt->variants()->delete();
            $color = ListingOption::create(['listing_id' => $shirt->id, 'position' => 1, 'name' => '色']);
            $location = Location::query()->orderBy('id')->firstOrFail();
            $variants = [];

            foreach (['赤', '青'] as $n => $label) {
                $value = ListingOptionValue::create(['option_id' => $color->id, 'position' => $n + 1, 'label' => $label]);
                $variants[] = ListingVariant::create(['listing_id' => $shirt->id, 'position' => $n + 1, 'value_1' => $value->id, 'unit_amount' => 3300]);
                app(StockService::class)->receive($variants[$n], $location, 30);
            }

            $this->answer['per'] = [$shirt->id => 3];
            $this->register();
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, $variants[0]->id, 2);
            $carts->add($cart, $variants[1]->id, 2);

            $lines = $carts->view($cart)->lines;
            $this->assertTrue($lines[0]['purchasable']);
            $this->assertSame(3, $lines[0]['maxQuantity']);
            $this->assertFalse($lines[1]['purchasable']);
            $this->assertStringContainsString('3 点まで', (string) $lines[1]['notice']);
            // 2 行目に残っているのは 1 点ぶん
            $this->assertSame(1, $lines[1]['maxQuantity']);
        });
    }

    public function test_an_order_below_the_minimum_is_refused_until_it_reaches_it(): void
    {
        $this->inside(function (): void {
            $this->answer['minimum'] = 5500;
            $this->register();
            $carts = app(CartService::class);

            $cart = $this->cart([$this->knit => 1]);
            $view = $carts->view($cart);
            $this->assertTrue($view->belowMinimum());
            $this->assertSame('ご注文は商品の合計 ¥5,500 から承ります（あと ¥1,100）。', $view->minimumNotice());
            $this->assertSame($view->minimumNotice(), $view->toArray()['minimum_notice']);
            // 行そのものは買える（直すのは数で、行ではない）
            $this->assertFalse($view->hasUnavailable());

            try {
                $this->place($cart);
                $this->fail('下限に届かない注文が通った');
            } catch (OrderException $e) {
                $this->assertSame($view->minimumNotice(), $e->getMessage());
            }

            $this->assertSame(0, Transaction::query()->count());

            // ちょうど下限（4,400 + 1,100）で通る。送料は下限に数えない（商品の合計だけ）
            $carts->add($cart, $this->variant($this->cap), 1);
            $view = $carts->view($cart);
            $this->assertSame(5500, $view->subtotal);
            $this->assertFalse($view->belowMinimum());
            $this->assertNull($view->toArray()['minimum_notice']);
            $this->assertInstanceOf(Transaction::class, $this->place($cart));
        });
    }

    public function test_an_empty_cart_is_never_below_the_minimum(): void
    {
        $this->inside(function (): void {
            $this->answer['minimum'] = 5500;
            $this->register();
            [$cart] = app(CartService::class)->create();

            // 空のカートに「あと ¥5,500」を出さない
            $this->assertNull(app(CartService::class)->view($cart)->minimumNotice());
        });
    }

    public function test_nothing_is_sold_while_the_rules_cannot_be_read(): void
    {
        $this->inside(function (): void {
            $this->register();
            $cart = $this->cart([$this->knit => 1]);
            $this->assertFalse(app(CartService::class)->view($cart)->hasUnavailable());

            // 整数でない上限（文字列・真偽・小数）も、比べ方しだいで「上限なし」に化けるので読めない扱い
            foreach ([['throw' => true], ['per' => [$this->knit => 0]], ['minimum' => -1], ['per' => [$this->knit => 'abc']], ['per' => [$this->knit => true]], ['per' => [$this->knit => 2.5]]] as $broken) {
                $this->answer->exchangeArray($broken + ['per' => [], 'minimum' => 0, 'throw' => false]);
                $view = app(CartService::class)->view($cart);
                $this->assertFalse($view->lines[0]['purchasable'], json_encode(array_keys($broken)).' で売ってしまった');
                $this->assertSame(CartService::LIMITS_UNREADABLE, $view->lines[0]['notice']);

                try {
                    $this->place($cart);
                    $this->fail('決まりを読めないのに注文が通った');
                } catch (OrderException $e) {
                    $this->assertSame(PlaceOrder::UNAVAILABLE, $e->getMessage());
                    $this->assertSame(0, Transaction::query()->count());
                }
            }
        });
    }

    public function test_a_line_that_cannot_be_bought_does_not_use_up_the_limit(): void
    {
        $this->inside(function (): void {
            // 上限 2。在庫より多い 1 行目（買えない）が枠を使うと、買える 2 行目にまで「数を減らしてください」が出る
            $shirt = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'シャツ', 'unit_amount' => 3300, 'status' => 'active', 'tax_class' => 'standard']);
            $shirt->variants()->delete();
            $color = ListingOption::create(['listing_id' => $shirt->id, 'position' => 1, 'name' => '色']);
            $location = Location::query()->orderBy('id')->firstOrFail();
            $variants = [];

            foreach (['赤' => 2, '青' => 30] as $label => $stock) {
                $value = ListingOptionValue::create(['option_id' => $color->id, 'position' => count($variants) + 1, 'label' => $label]);
                $variants[] = $variant = ListingVariant::create(['listing_id' => $shirt->id, 'position' => count($variants) + 1, 'value_1' => $value->id, 'unit_amount' => 3300]);
                app(StockService::class)->receive($variant, $location, $stock);
            }

            $this->answer['per'] = [$shirt->id => 2];
            $this->register();
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, $variants[0]->id, 2);
            $carts->add($cart, $variants[1]->id, 2);
            // 赤が売れて残り 1 に（カートの 2 点は在庫より多い＝買えない行）
            app(StockService::class)->adjust($variants[0], $location, -1);

            $lines = app(CartService::class)->view($cart)->lines;
            $this->assertFalse($lines[0]['purchasable']);
            $this->assertStringContainsString('在庫が少なくなりました', (string) $lines[0]['notice']);
            $this->assertTrue($lines[1]['purchasable'], '買えない行が上限の枠を使った');
            $this->assertNull($lines[1]['notice']);
        });
    }

    public function test_an_order_of_one_product_outside_the_cart_keeps_the_limit_too(): void
    {
        $this->inside(function (): void {
            // App の申し込みの画面から作る 1 品の注文（定期便の 1 回目など）。カートを通らないので、ここで守らないと素通りする
            $this->answer['per'] = [$this->knit => 2];
            $this->register();
            $knit = Listing::query()->findOrFail($this->knit);
            $variant = ListingVariant::query()->findOrFail($this->variant($this->knit));
            $account = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'storefront');
            $place = fn (int $quantity, string $actor): Transaction => app(PlaceSingleOrder::class)->place($knit, $variant, $quantity, 0, '', $this->input(), $account, $this->key(), $actor);

            try {
                $place(3, 'storefront');
                $this->fail('上限を超えた 1 品の注文が通った');
            } catch (OrderException $e) {
                $this->assertSame('この商品は 1 回のご注文で 2 点までです。数量を減らしてください。', $e->getMessage());
            }

            $this->assertSame(0, Transaction::query()->count());
            // ちょうど上限は通る
            $this->assertInstanceOf(Transaction::class, $place(2, 'storefront'));
            // 仕組みが作る注文（定期便の 2 回目からなど）は、申し込みの時の約束なので断らない
            $this->assertInstanceOf(Transaction::class, $place(3, 'system:subscriptions'));

            // 決まりを読めない時は、お客さんの申し込みを受けない（仕組みが作る注文は続く）
            $this->answer['throw'] = true;

            try {
                $place(1, 'storefront');
                $this->fail('決まりを読めないのに 1 品の注文が通った');
            } catch (OrderException $e) {
                $this->assertSame(CartService::LIMITS_UNREADABLE, $e->getMessage());
            }

            $this->assertInstanceOf(Transaction::class, $place(1, 'system:subscriptions'));
        });
    }

    public function test_the_core_does_not_trust_what_an_app_answers(): void
    {
        $this->inside(function (): void {
            // 頼んでいない商品の答えは捨て、けた外れの値は絞る
            $this->answer['per'] = [$this->knit => 5_000_000, $this->cap => 3];
            $this->answer['minimum'] = PHP_INT_MAX;
            $this->register();
            $limits = app(PurchaseLimits::class);

            $this->assertSame([$this->knit => PurchaseLimits::MAX_PER_ORDER], $limits->perOrder([$this->knit, 'not-an-id']));
            $this->assertSame(PurchaseLimits::MAX_MINIMUM, $limits->minimumSubtotal());
        });
    }

    private function register(bool $enabled = true): void
    {
        app(PluginRegistry::class)->register(new PluginDefinition(
            name: 'limit-sample', title: '見本', description: '', pluginApi: PluginRegistry::API_VERSION,
            purchaseLimits: new class($this->answer) implements PurchaseLimitProvider
            {
                /** @param ArrayObject<string, mixed> $answer */
                public function __construct(private readonly ArrayObject $answer) {}

                public function perOrder(array $listingIds): array
                {
                    if ($this->answer['throw']) {
                        throw new RuntimeException('読めない');
                    }

                    return $this->answer['per'];
                }

                public function minimumSubtotal(): int
                {
                    if ($this->answer['throw']) {
                        throw new RuntimeException('読めない');
                    }

                    return $this->answer['minimum'];
                }
            },
        ));
        app(PluginStates::class)->set('limit-sample', $enabled, 'test');
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function lineOf(array $lines, string $listing): array
    {
        foreach ($lines as $line) {
            if ($line['listingId'] === $listing) {
                return $line;
            }
        }

        $this->fail('行が無い');
    }

    private function listing(string $title, int $price): string
    {
        $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $price, 'status' => 'active', 'tax_class' => 'standard']);
        app(StockService::class)->receive($listing, Location::query()->orderBy('id')->firstOrFail(), 30);

        return $listing->id;
    }

    private function variant(string $listing): string
    {
        return (string) ListingVariant::query()->where('listing_id', $listing)->orderBy('position')->value('id');
    }

    /**
     * @param  array<string, int>  $items
     */
    private function cart(array $items): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();

        foreach ($items as $listing => $quantity) {
            $carts->add($cart, $this->variant($listing), $quantity);
        }

        return $cart;
    }

    private function place(Cart $cart): Transaction
    {
        $input = $this->input();
        $fingerprint = CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart, $input->destination(), 'hanako@example.com'));

        return app(PlaceOrder::class)->place($cart, $input, $this->key(), $fingerprint);
    }

    private function input(): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null]);
        $this->assertNotNull($input);

        return $input;
    }

    private function key(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
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
