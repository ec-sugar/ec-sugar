<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\VariantStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\Contracts\StockComponentsProvider;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Catalog\ListingCard;
use App\Storefront\Checkout\CheckoutOrder;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 自分の在庫を持たない商品（セット商品）を、ショップで本当に買えるか。
 *
 * 核の直しの要は 2 つ。**セットそのものに在庫を入れなくても買える**（買えるかの判定が構成する品を見る）ことと、
 * **構成する品が揃っている拠点から出す**（拠点ごとに数えるので、拠点をまたいで寄せ集めない）こと。
 * 在庫を押さえる所（LineStock）だけ直しても、ショップが「売り切れ」と出して注文を断ってしまうため
 */
final class BundleCheckoutTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private ?string $token = null;

    /** @var array<string, string> 品の名前 => 種類の ID */
    private array $parts = [];

    private string $bundle;

    private Location $tokyo;

    private Location $osaka;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'bdl', 'name' => 'セットの見本の店']);
        $this->tenant->domains()->create(['domain' => 'bdl.localhost']);
        $this->inside(function (): void {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(ShopSettingsService::class)->setShipping(['fee' => 0, 'free_over' => null], 'test');
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

    public function test_a_bundle_sells_without_any_stock_of_its_own(): void
    {
        $this->build(['coffee' => [$this->tokyoName() => 10], 'cup' => [$this->tokyoName() => 4]]);
        $this->useBundle(['coffee' => 2, 'cup' => 1]);

        // ショップに出ていて、作れる数（カップ 4 ÷ 1 = 4、コーヒー 10 ÷ 2 = 5 の小さい方）で「在庫あり」
        $this->inside(function (): void {
            $view = app(Catalog::class)->product($this->bundle);
            $this->assertNotNull($view);
            $this->assertSame(ListingCard::IN_STOCK, $view->card->availability, 'セットそのものに在庫が無いと売り切れになっている');
            $this->assertSame(4, $view->maxQuantity, '作れるセットの数が入れられる数になっていない');
            // セットそのものの在庫の行は無い（店の人は意味のない在庫を入れなくてよい）
            $this->assertFalse(InventoryStock::query()->where('listing_id', $this->bundle)->exists());
        });

        // 2 セット買うと、構成する品から引かれる（コーヒー 4・カップ 2）
        $this->place(quantity: 2);

        $this->inside(function (): void {
            $this->assertSame(4, $this->reserved('coffee'));
            $this->assertSame(2, $this->reserved('cup'));
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $this->bundle)->sum('reserved'));
        });
    }

    public function test_parts_in_different_locations_are_not_combined_into_one_set(): void
    {
        // コーヒーは東京だけ、カップは大阪だけ。どちらの拠点でも 1 セットも作れない
        $this->build(['coffee' => [$this->tokyoName() => 10], 'cup' => [$this->osakaName() => 10]]);
        $this->useBundle(['coffee' => 1, 'cup' => 1]);

        $this->inside(function (): void {
            // 一覧でも商品の画面でも売り切れ（一覧から消すかどうかは App の見せ方の口の仕事。ここは核の判定）
            $this->assertSame(ListingCard::SOLD_OUT, $this->cardOf($this->bundle)->availability, '拠点をまたいだ寄せ集めで売っている');
            $view = app(Catalog::class)->product($this->bundle);
            $this->assertNotNull($view);
            $this->assertSame(ListingCard::SOLD_OUT, $view->card->availability);
            $this->assertSame(0, $view->maxQuantity);
        });

        // 片方の拠点に両方が揃えば売れる（在庫の数は変えず、置き場所だけ揃える）
        $this->inside(fn () => app(StockService::class)->receive(Listing::query()->findOrFail($this->listings['cup']), $this->tokyo, 3));
        $this->inside(function (): void {
            $view = app(Catalog::class)->product($this->bundle);
            $this->assertNotNull($view);
            $this->assertSame(3, $view->maxQuantity, '揃っている拠点の数で売っていない');
        });

        $this->place(quantity: 3);

        // 引かれたのは東京の分だけ（大阪のカップは触らない）
        $this->inside(function (): void {
            $this->assertSame(3, (int) InventoryStock::query()->where('variant_id', $this->parts['cup'])->where('location_id', $this->tokyo->id)->sum('reserved'));
            $this->assertSame(0, (int) InventoryStock::query()->where('variant_id', $this->parts['cup'])->where('location_id', $this->osaka->id)->sum('reserved'));
        });
    }

    public function test_a_part_that_the_shop_stopped_selling_makes_the_bundle_sold_out(): void
    {
        $this->build(['coffee' => [$this->tokyoName() => 10], 'cup' => [$this->tokyoName() => 10]]);
        $this->useBundle(['coffee' => 1, 'cup' => 1]);

        // 構成する品の種類を止める（在庫はあるが、店が売らないと決めた品）
        $this->inside(function (): void {
            ListingVariant::query()->whereKey($this->parts['cup'])->update(['status' => VariantStatus::Stopped]);
            $this->assertSame(ListingCard::SOLD_OUT, $this->cardOf($this->bundle)->availability, '店が止めた品をセットの形で売っている');
        });

        // 品を下書きに戻した場合も同じ
        $this->inside(function (): void {
            ListingVariant::query()->whereKey($this->parts['cup'])->update(['status' => VariantStatus::Active]);
            Listing::query()->whereKey($this->listings['cup'])->update(['status' => 'draft']);
            $this->assertSame(ListingCard::SOLD_OUT, $this->cardOf($this->bundle)->availability, '下書きの品をセットの形で売っている');
        });
    }

    public function test_the_cart_refuses_more_than_the_parts_can_build(): void
    {
        $this->build(['coffee' => [$this->tokyoName() => 10], 'cup' => [$this->tokyoName() => 2]]);
        $this->useBundle(['coffee' => 1, 'cup' => 2]);

        // カップ 2 個・1 セットに 2 個 → 1 セットだけ
        $this->inside(function (): void {
            $view = app(Catalog::class)->product($this->bundle);
            $this->assertNotNull($view);
            $this->assertSame(1, $view->maxQuantity);
        });

        $this->send('POST', '/cart/items', ['listing_id' => $this->bundle, 'quantity' => '2']);
        $this->inside(function (): void {
            $this->assertSame(0, $this->reserved('cup'), 'カートに入れた時点で在庫を押さえている');
        });

        // 2 セットは買えない（カートが 1 に丸めるか、確定で断る。どちらでも在庫は 1 セット分までしか動かない）
        $this->send('POST', '/checkout', ['buyer' => $this->buyer(), 'same_as_buyer' => '1']);
        $confirm = (string) $this->send('GET', '/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? '']);

        $this->inside(function (): void {
            $this->assertLessThanOrEqual(2, $this->reserved('cup'), 'カップを 2 個より多く押さえている');
            $this->assertSame(0, (int) InventoryStock::query()->where('variant_id', $this->parts['cup'])->where('location_id', $this->tokyo->id)->whereRaw('reserved > quantity')->count(), '在庫を超えて押さえている');
        });
    }

    public function test_a_bundle_and_the_same_part_in_one_cart_cannot_take_more_than_the_stock(): void
    {
        // カップは 3 個だけ。セット（カップ 1 個）を 2 つと、カップそのものを 2 個 → 合わせて 4 個で足りない
        $this->build(['coffee' => [$this->tokyoName() => 10], 'cup' => [$this->tokyoName() => 3]]);
        $this->useBundle(['coffee' => 1, 'cup' => 1]);

        $this->send('POST', '/cart/items', ['listing_id' => $this->bundle, 'quantity' => '2']);
        $this->send('POST', '/cart/items', ['listing_id' => $this->listings['cup'], 'quantity' => '2']);
        $this->send('POST', '/checkout', ['buyer' => $this->buyer(), 'same_as_buyer' => '1']);
        $confirm = (string) $this->send('GET', '/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''])
            ->assertRedirect('http://bdl.localhost/cart');

        // 在庫を超えて押さえない（セットの分と単体の分を別々に数えていたら 4 個押さえてしまう）
        $this->inside(function (): void {
            $this->assertLessThanOrEqual(3, $this->reserved('cup'), 'カップの在庫を超えて押さえている');
            $this->assertSame(0, InventoryStock::query()->whereRaw('reserved > quantity')->count(), '確保が在庫を超えている');
        });

    }

    public function test_a_bundle_and_the_same_part_together_fit_exactly_in_the_stock(): void
    {
        // カップ 3 個に、セット（カップ 1 個）1 つと単体 2 個。ちょうど足りるので通る
        // （もう割り当てた分を引きすぎていたら、ここが売り切れになる）
        $this->build(['coffee' => [$this->tokyoName() => 10], 'cup' => [$this->tokyoName() => 3]]);
        $this->useBundle(['coffee' => 1, 'cup' => 1]);

        $this->send('POST', '/cart/items', ['listing_id' => $this->bundle, 'quantity' => '1']);
        $this->send('POST', '/cart/items', ['listing_id' => $this->listings['cup'], 'quantity' => '2']);
        $this->send('POST', '/checkout', ['buyer' => $this->buyer(), 'same_as_buyer' => '1']);
        $confirm = (string) $this->send('GET', '/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''])->assertStatus(303);

        $this->inside(function (): void {
            $this->assertSame(3, $this->reserved('cup'), 'ちょうど足りる注文が通っていない');
            $this->assertSame(1, $this->reserved('coffee'));
        });
    }

    public function test_a_broken_app_takes_the_bundle_off_the_shop_but_keeps_the_rest_selling(): void
    {
        $this->build(['coffee' => [$this->tokyoName() => 10], 'cup' => [$this->tokyoName() => 10]]);

        // 在庫の構成を答えられない App（核は取引を止める。ショップは落とさない）
        $this->inside(function (): void {
            app(PluginRegistry::class)->register(new PluginDefinition(
                name: 'broken-bundle', title: '見本', description: '', pluginApi: PluginRegistry::API_VERSION,
                stockComponents: new class implements StockComponentsProvider
                {
                    public function stockComponents(array $listingIds): array
                    {
                        throw new RuntimeException('壊れた');
                    }
                },
            ));
            app(PluginStates::class)->set('broken-bundle', true, 'test');
        });

        // ふつうの商品はそのまま売れる（一覧が 500 にならない）
        $this->send('GET', '/')->assertOk();
        $this->inside(function (): void {
            $cards = app(Catalog::class)->featured(20);
            $titles = array_map(static fn (ListingCard $card): string => $card->title, $cards);
            $this->assertContains('コーヒー', $titles, '壊れた App でふつうの商品まで消えている');
            $coffee = array_values(array_filter($cards, static fn (ListingCard $card): bool => $card->title === 'コーヒー'))[0];
            $this->assertSame(ListingCard::IN_STOCK, $coffee->availability, '壊れた App でふつうの商品まで売り切れになっている');
        });

        // セットは自分の在庫を持たないので、売り切れとして出る（在庫を引かずに売ってしまわない）
        $this->inside(function (): void {
            $bundle = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'お得セット', 'unit_amount' => 2500, 'status' => 'active']);
            $view = app(Catalog::class)->product($bundle->id);
            $this->assertNotNull($view);
            $this->assertSame(ListingCard::SOLD_OUT, $view->card->availability);
        });
    }

    /**
     * 品を作って入庫する
     *
     * @param  array<string, array<string, int>>  $parts  品の名前 => 拠点の名前 => 入庫する数
     */
    private function build(array $parts): void
    {
        $this->inside(function () use ($parts): void {
            $seller = Seller::query()->firstOrFail();
            $this->tokyo = Location::query()->orderBy('created_at')->firstOrFail();
            $this->osaka = Location::create(['seller_id' => $this->tokyo->seller_id, 'name' => '大阪の倉庫', 'kind' => $this->tokyo->kind]);
            $locations = [$this->tokyoName() => $this->tokyo, $this->osakaName() => $this->osaka];

            foreach ($parts as $name => $byLocation) {
                $title = $name === 'coffee' ? 'コーヒー' : 'カップ';
                $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => $title, 'unit_amount' => 1000, 'status' => 'active']);

                foreach ($byLocation as $locationName => $quantity) {
                    app(StockService::class)->receive($listing, $locations[$locationName], $quantity);
                }

                $this->parts[$name] = (string) ListingVariant::query()->where('listing_id', $listing->id)->value('id');
                $this->listings[$name] = $listing->id;
            }

            $bundle = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'お得セット', 'unit_amount' => 2500, 'status' => 'active']);
            $this->bundle = $bundle->id;
        });
    }

    /** @var array<string, string> */
    private array $listings = [];

    private function tokyoName(): string
    {
        return 'tokyo';
    }

    private function osakaName(): string
    {
        return 'osaka';
    }

    /**
     * セットの App を登録して有効にする
     *
     * @param  array<string, int>  $parts  品の名前 => 1 セットあたりの数
     */
    private function useBundle(array $parts): void
    {
        $map = [];

        foreach ($parts as $name => $each) {
            $map[$this->parts[$name]] = $each;
        }

        $bundle = $this->bundle;
        $this->inside(function () use ($bundle, $map): void {
            app(PluginRegistry::class)->register(new PluginDefinition(
                name: 'bundle-sample', title: '見本', description: '', pluginApi: PluginRegistry::API_VERSION,
                stockComponents: new class($bundle, $map) implements StockComponentsProvider
                {
                    /** @param array<string, int> $map */
                    public function __construct(private readonly string $bundle, private readonly array $map) {}

                    public function stockComponents(array $listingIds): array
                    {
                        return in_array($this->bundle, $listingIds, true) ? [$this->bundle => $this->map] : [];
                    }
                },
            ));
            app(PluginStates::class)->set('bundle-sample', true, 'test');
        });
    }

    /**
     * ショップでセットを買う（支払いは Stripe へ進むところまで）
     */
    private function place(int $quantity): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->bundle, 'quantity' => (string) $quantity]);
        $this->send('POST', '/checkout', ['buyer' => $this->buyer(), 'same_as_buyer' => '1']);
        $confirm = (string) $this->send('GET', '/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''])->assertStatus(303);
        $this->inside(fn () => $this->assertNotNull(CheckoutOrder::query()->latest('created_at')->value('transaction_id'), '注文ができていない'));
    }

    /**
     * @return array<string, string>
     */
    private function buyer(): array
    {
        return ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => ''];
    }

    /**
     * 一覧に出る札（見つからなければ失敗させる）
     */
    private function cardOf(string $listingId): ListingCard
    {
        foreach (app(Catalog::class)->featured(50) as $card) {
            if ($card->id === $listingId) {
                return $card;
            }
        }

        $this->fail('一覧に札が無い');
    }

    private function reserved(string $name): int
    {
        return (int) InventoryStock::query()->where('variant_id', $this->parts[$name])->sum('reserved');
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

        $response = $method === 'GET' ? $this->get("http://bdl.localhost{$path}") : $this->post("http://bdl.localhost{$path}", $data);
        tenancy()->end();
        $this->token = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->token;

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
