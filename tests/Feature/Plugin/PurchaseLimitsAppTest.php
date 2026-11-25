<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\VariantService;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\Contracts\PurchaseLimitProvider;
use App\Core\Plugin\Models\PluginSetting;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\Cart\CartView;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use EcSugar\PluginPurchaseLimits\ListingLimits;
use EcSugar\PluginPurchaseLimits\Models\ListingLimit;
use EcSugar\PluginPurchaseLimits\PurchaseLimitsDataPurger;
use EcSugar\PluginPurchaseLimits\PurchaseLimitsProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「購入の制限」。商品ごとの「1 回のご注文の上限」と店の「最低購入金額」が、
 * カートの行・注文の入力の画面・注文の確定でどう効くかを確かめる:
 * 上限を超えた行は買えない（種類違いの行は合わせて数える）、下限に届かないカートは注文へ進めない（ちょうどは通る）、
 * App を使っていない店では何も掛からない、欄と設定の入力の確かめ、閲覧だけの人は変えられない、
 * 決まりを読めない時（口の例外・壊れた設定）はご注文を受けない、読み出しは 1 回、データを消す口はこの店の行だけ。
 *
 * 商品は 1 点 ¥1,000・送料は 0 円（下限は商品の合計と比べるので、額を読みやすくする）
 */
final class PurchaseLimitsAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string APP = 'purchase-limits';

    /** 1 種類だけの商品（¥1,000） */
    private string $listing;

    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        $this->configureAdminHost();
        $this->tenant('pul');
        [$this->listing, $this->variant] = $this->inside('pul', function (): array {
            app(PluginStates::class)->set(self::APP, true, 'test');
            app(ShopSettingsService::class)->setShipping(['fee' => 0, 'free_over' => null], 'test');
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '限定の豆', 'unit_amount' => 1000, 'status' => 'active']);
            app(StockService::class)->receive($listing, self::location(), 30);

            return [$listing->id, (string) ListingVariant::query()->where('listing_id', $listing->id)->value('id')];
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_line_over_the_limit_cannot_be_ordered_and_the_limit_itself_can(): void
    {
        $this->limit($this->listing, 2);

        $this->inside('pul', function (): void {
            // 3 点は上限を超える: 行は買えず、知らせに上限の数が出て、注文の確定も断る
            $over = $this->cart([$this->variant => 3]);
            $view = app(CartService::class)->view($over);
            $this->assertFalse($view->lines[0]['purchasable']);
            $this->assertStringContainsString('2 点まで', (string) $view->lines[0]['notice']);
            $this->assertSame(2, $view->lines[0]['maxQuantity']);
            $this->assertSame(PlaceOrder::UNAVAILABLE, $this->refusal($over));
            $this->assertSame(0, Transaction::query()->count());

            // ちょうど 2 点は通る
            $within = $this->cart([$this->variant => 2]);
            $view = app(CartService::class)->view($within);
            $this->assertTrue($view->lines[0]['purchasable']);
            $this->assertNull($view->lines[0]['notice']);
            $this->assertSame(2000, $this->place($within)->total_amount);
        });
    }

    public function test_lines_of_the_same_listing_in_different_variants_are_counted_together(): void
    {
        [$listing, $red, $blue] = $this->inside('pul', function (): array {
            $listing = app(ListingService::class)->create(Seller::query()->firstOrFail(), 'product', ['title' => '限定のカップ', 'unit_amount' => 1000, 'status' => ListingStatus::Active]);
            $listing = app(VariantService::class)->define($listing, $listing->version, [['name' => '色', 'values' => ['赤', '青']]]);
            $variants = $listing->variants()->get();
            $this->assertCount(2, $variants);

            foreach ($variants as $variant) {
                app(StockService::class)->receive($variant, self::location(), 10);
            }

            return [$listing->id, $variants[0]->id, $variants[1]->id];
        });
        $this->limit($listing, 2);

        $this->inside('pul', function () use ($red, $blue): void {
            // 赤 1 点 ＋ 青 2 点 = 3 点。行ごとには上限の中でも、合わせて超えるので止まる
            $over = $this->cart([$red => 1, $blue => 2]);
            $view = app(CartService::class)->view($over);
            $this->assertTrue($view->hasUnavailable());
            $this->assertContains(false, array_column($view->lines, 'purchasable'));
            $this->assertStringContainsString('2 点まで', implode('', array_filter(array_column($view->lines, 'notice'))));
            $this->assertSame(PlaceOrder::UNAVAILABLE, $this->refusal($over));

            // 赤 1 点 ＋ 青 1 点 = 2 点は通る
            $within = $this->cart([$red => 1, $blue => 1]);
            $this->assertFalse(app(CartService::class)->view($within)->hasUnavailable());
            $this->assertSame(2000, $this->place($within)->total_amount);
        });
    }

    public function test_a_cart_below_the_minimum_cannot_go_to_checkout_and_the_exact_minimum_can(): void
    {
        $this->minimum('3000');

        // 小計 ¥2,000 は下限 ¥3,000 に届かない
        [$below, $token] = $this->inside('pul', function (): array {
            $carts = app(CartService::class);
            [$cart, $token] = $carts->create();
            $carts->add($cart, $this->variant, 2);
            $view = $carts->view($cart);
            $this->assertTrue($view->belowMinimum());
            $this->assertStringContainsString('¥3,000', (string) $view->minimumNotice());
            $this->assertStringContainsString('¥1,000', (string) $view->minimumNotice());
            // 行そのものは買える（止めるのはカート全体）
            $this->assertFalse($view->hasUnavailable());

            return [$cart, $token];
        });

        // 注文の入力の画面は開かず、カートへ戻る
        // （戻る理由が下限であることも確かめる。空のカートや買えない行でも同じ所へ戻るため）
        $this->withCookie(CartCookie::NAME, $token)->get('http://pul.localhost/checkout')->assertRedirect('http://pul.localhost/cart')
            ->assertSessionHas('cart_error', static fn (mixed $error): bool => is_string($error) && str_contains($error, '¥3,000'));
        tenancy()->end();

        $this->inside('pul', function () use ($below): void {
            // 画面を通さない要求でも、注文の確定が錠の中で断る（断りの文は下限の一言）
            $refusal = $this->refusal($below);
            $this->assertStringContainsString('¥3,000', $refusal);
            $this->assertSame(0, Transaction::query()->count());

            // ちょうど ¥3,000 は通る
            $exact = $this->cart([$this->variant => 3]);
            $this->assertFalse(app(CartService::class)->view($exact)->belowMinimum());
            $this->assertSame(3000, $this->place($exact)->total_amount);
        });
    }

    public function test_a_shop_that_does_not_use_the_app_has_no_limits(): void
    {
        $this->limit($this->listing, 2);
        $this->minimum('5000');

        $this->inside('pul', function (): void {
            // 使っている間は、上限（2 点）にも下限（¥5,000）にも掛かる
            $cart = $this->cart([$this->variant => 3]);
            $view = app(CartService::class)->view($cart);
            $this->assertTrue($view->hasUnavailable());

            // 止めると、どちらも掛からない（行と設定は残る）
            app(PluginStates::class)->set(self::APP, false, 'test');
            $view = app(CartService::class)->view($cart);
            $this->assertFalse($view->hasUnavailable());
            $this->assertFalse($view->belowMinimum());
            $this->assertSame(0, $view->minimumSubtotal);
            $this->assertSame(3000, $this->place($cart)->total_amount);
            $this->assertSame(1, ListingLimit::query()->count());
        });

        // 一度も使っていない店でも同じ（表は空のまま）
        $this->tenant('pum');
        $this->inside('pum', function (): void {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ふつうの豆', 'unit_amount' => 1000, 'status' => 'active']);
            app(StockService::class)->receive($listing, self::location(), 30);
            $view = app(CartService::class)->view($this->cart([(string) ListingVariant::query()->where('listing_id', $listing->id)->value('id') => 5]));
            $this->assertFalse($view->hasUnavailable());
            $this->assertFalse($view->belowMinimum());
        });
    }

    public function test_the_panel_and_the_setting_refuse_bad_input_and_a_reader_cannot_change_them(): void
    {
        [$owner, $key] = $this->enrolledOperator();
        // App の設定を変えるのは、直前の再確認の後だけ（核の経路の決まり）
        $this->reconfirm($owner, $key);
        $panel = "/api/tenants/pul/listings/{$this->listing}/apps/".self::APP;
        $settings = '/api/tenants/pul/plugins/'.self::APP.'/settings';

        // 欄: 0・1000・文字・負の数・全角・数の型は、欄の名前つきで断る（行は増えない）
        foreach (['0', '1000', 'abc', '-1', '２', '1.5', '01', 5] as $bad) {
            $this->send('PUT', $panel, ['values' => ['per_order' => $bad]])->assertUnprocessable()->assertJsonValidationErrors(['values.per_order']);
        }

        $this->assertSame(0, $this->inside('pul', fn (): int => ListingLimit::query()->count()));

        // 決めると、欄の案内と値に出る。記録は商品の ID と数だけ
        $this->send('PUT', $panel, ['values' => ['per_order' => '2']])->assertOk()->assertJsonPath('app.note', 'この商品は、1 回のご注文で 2 点までです。');
        $this->send('GET', "/api/tenants/pul/listings/{$this->listing}/apps")->assertOk()
            ->assertJsonFragment(['plugin' => self::APP])
            ->assertJsonFragment(['per_order' => '2']);
        $set = $this->inside('pul', fn (): DomainEvent => DomainEvent::query()->where('type', 'purchase_limits.set')->sole());
        $this->assertSame([$owner->actor(), $this->listing, 2], [$set->actor, $set->payload['listing_id'], $set->payload['per_order']]);
        $this->assertCount(2, $set->payload);

        // 上と下の端（1 と 999）は通り、同じ数の送り直しでは記録が増えない
        $this->send('PUT', $panel, ['values' => ['per_order' => '999']])->assertOk();
        $this->send('PUT', $panel, ['values' => ['per_order' => '1']])->assertOk();
        $this->send('PUT', $panel, ['values' => ['per_order' => '1']])->assertOk();
        $this->assertSame(3, $this->inside('pul', fn (): int => DomainEvent::query()->where('type', 'purchase_limits.set')->count()));

        // 設定: 負の数・上限より上・カンマ・小数は断り、端（100,000,000）と 0 は通る
        foreach (['-1', '100000001', '3,000', '1.5', '999999999'] as $bad) {
            $this->send('PUT', $settings, ['values' => ['minimum_subtotal' => $bad]])->assertUnprocessable();
        }

        $this->send('PUT', $settings, ['values' => ['minimum_subtotal' => '100000000']])->assertOk();
        $this->assertSame(100_000_000, $this->inside('pul', fn (): int => (new PurchaseLimitsProvider)->minimumSubtotal()));
        $this->send('PUT', $settings, ['values' => ['minimum_subtotal' => '0']])->assertOk();
        $this->assertSame(0, $this->inside('pul', fn (): int => (new PurchaseLimitsProvider)->minimumSubtotal()));

        // 閲覧の段階の人は見られるが、上限も設定も変えられない
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        [$staff, $staffKey] = $this->enrolledOperator('staff@example.com', 'staff');
        $this->reconfirm($staff, $staffKey);
        app(OperatorAccess::class)->grant($staff, 'pul', 'read', 'test');
        $this->send('GET', "/api/tenants/pul/listings/{$this->listing}/apps")->assertOk();
        $this->send('PUT', $panel, ['values' => ['per_order' => '5']])->assertForbidden();
        $this->send('PUT', $panel, ['values' => ['per_order' => null]])->assertForbidden();
        $this->send('PUT', $settings, ['values' => ['minimum_subtotal' => '1']])->assertForbidden();
        $this->assertSame(1, $this->inside('pul', fn (): int => ListingLimit::query()->findOrFail($this->listing)->per_order));
        $this->assertSame(0, $this->inside('pul', fn (): int => (new PurchaseLimitsProvider)->minimumSubtotal()));
    }

    public function test_clearing_the_field_removes_the_limit_and_the_database_keeps_the_range(): void
    {
        $this->limit($this->listing, 2);

        $this->inside('pul', function (): void {
            $listing = Listing::query()->findOrFail($this->listing);

            // 空で保存すると行が消え、いくつでも買える
            app(ListingLimits::class)->save($listing, null, 'operator:test');
            $this->assertSame(0, ListingLimit::query()->count());
            $this->assertSame(['purchase_limits.set', 'purchase_limits.cleared'], DomainEvent::query()->where('type', 'like', 'purchase_limits.%')->orderBy('sequence')->pluck('type')->all());
            $this->assertFalse(app(CartService::class)->view($this->cart([$this->variant => 5]))->hasUnavailable());

            // 範囲の外の数は、書く係を通さずに直に入れても DB が断る（0 以下の行があると、核はご注文を受けなくなる）
            foreach ([0, 1000] as $bad) {
                try {
                    DB::transaction(fn () => DB::table('purchase_limits_listings')->insert(['listing_id' => $this->listing, 'per_order' => $bad]));
                    $this->fail("範囲の外の上限（{$bad}）が入った");
                } catch (QueryException) {
                    $this->assertSame(0, ListingLimit::query()->count());
                }
            }
        });
    }

    public function test_orders_are_refused_while_the_rules_cannot_be_read(): void
    {
        // 壊れた設定（形の決まりを通らない値が直に入った）: 0（下限なし）に読み替えず、ご注文を受けない
        $this->minimum('3000');
        $this->inside('pul', function (): void {
            $cart = $this->cart([$this->variant => 5]);
            $this->assertFalse(app(CartService::class)->view($cart)->hasUnavailable());

            PluginSetting::query()->where('plugin', self::APP)->update(['values' => json_encode(['minimum_subtotal' => '-3000'], JSON_THROW_ON_ERROR)]);
            $view = app(CartService::class)->view($cart);
            $this->assertFalse($view->lines[0]['purchasable']);
            $this->assertSame(CartService::LIMITS_UNREADABLE, $view->lines[0]['notice']);
            $this->assertSame(PlaceOrder::UNAVAILABLE, $this->refusal($cart));
            $this->assertSame(0, Transaction::query()->count());

            // 設定を直すと、また受ける
            app(PluginSettings::class)->update(self::APP, ['minimum_subtotal' => '3000'], 'test');
            $this->assertFalse(app(CartService::class)->view($cart)->hasUnavailable());
        });

        // 核の窓口: 口が例外を投げる App が 1 つでもあれば、ご注文を受けない
        $this->inside('pul', function (): void {
            app(PluginRegistry::class)->register(new PluginDefinition(
                name: 'broken-limits', title: '見本', description: '', pluginApi: PluginRegistry::API_VERSION,
                purchaseLimits: new class implements PurchaseLimitProvider
                {
                    public function perOrder(array $listingIds): array
                    {
                        throw new RuntimeException('壊れた');
                    }

                    public function minimumSubtotal(): int
                    {
                        return 0;
                    }
                },
            ));
            app(PluginStates::class)->set('broken-limits', true, 'test');

            $cart = $this->cart([$this->variant => 5]);
            $view = app(CartService::class)->view($cart);
            $this->assertFalse($view->lines[0]['purchasable']);
            $this->assertSame(CartService::LIMITS_UNREADABLE, $view->lines[0]['notice']);
            $this->assertSame(PlaceOrder::UNAVAILABLE, $this->refusal($cart));
            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_the_limits_are_read_in_one_query_and_only_for_the_asked_listings(): void
    {
        $others = $this->inside('pul', function (): array {
            $ids = [];

            foreach (['A', 'B', 'C'] as $name) {
                $ids[] = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => "品 {$name}", 'unit_amount' => 500, 'status' => 'active'])->id;
            }

            return $ids;
        });
        $this->limit($this->listing, 2);
        $this->limit($others[0], 5);
        $this->limit($others[1], 9);

        $this->inside('pul', function () use ($others): void {
            DB::flushQueryLog();
            DB::enableQueryLog();
            // 頼んだ 3 つのうち、上限のある 2 つだけが返る（頼んでいない others[1] は入らない・上限の無い others[2] も入らない）
            $limits = (new PurchaseLimitsProvider)->perOrder([$this->listing, $others[0], $others[2]]);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertSame([$this->listing => 2, $others[0] => 5], [$this->listing => $limits[$this->listing] ?? null, $others[0] => $limits[$others[0]] ?? null]);
            $this->assertCount(2, $limits);
            $this->assertSame(1, $queries, '商品の数だけ問い合わせている');

            // 何も頼まれなければ、DB に聞かない
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertSame([], (new PurchaseLimitsProvider)->perOrder([]));
            $this->assertCount(0, DB::getQueryLog());
            DB::disableQueryLog();
        });
    }

    public function test_purging_removes_only_this_shops_rows(): void
    {
        $this->limit($this->listing, 2);
        $this->tenant('pum');
        $this->inside('pum', function (): void {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '別の店の豆', 'unit_amount' => 1000, 'status' => 'active']);
            app(ListingLimits::class)->save($listing, 3, 'operator:test');
        });

        $this->inside('pul', function (): void {
            $purger = new PurchaseLimitsDataPurger;
            $this->assertNull($purger->refusal());
            $this->assertSame(['purchase_limits_listings' => 1], $purger->purgeRows());
            $this->assertSame(0, $purger->purgeFiles());
            $this->assertSame(0, ListingLimit::query()->count());
            // 商品そのものは消さない
            $this->assertTrue(Listing::query()->whereKey($this->listing)->exists());
        });
        $this->assertSame(1, $this->inside('pum', fn (): int => ListingLimit::query()->count()));
    }

    private function limit(string $listingId, int $perOrder): void
    {
        $this->inside('pul', fn () => app(ListingLimits::class)->save(Listing::query()->findOrFail($listingId), $perOrder, 'operator:test'));
    }

    private function minimum(string $amount): void
    {
        $this->inside('pul', fn () => app(PluginSettings::class)->update(self::APP, ['minimum_subtotal' => $amount], 'test'));
    }

    /**
     * カートを作る（店の中で呼ぶ）
     *
     * @param  array<string, int>  $items  種類の ID => 数
     */
    private function cart(array $items): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();

        foreach ($items as $variant => $quantity) {
            $carts->add($cart, $variant, $quantity);
        }

        return $cart;
    }

    /**
     * 確かめの画面と同じ決め方で注文を確定する（店の中で呼ぶ）
     */
    private function place(Cart $cart): Transaction
    {
        return app(PlaceOrder::class)->place($cart, self::input(), self::key(), CheckoutSummary::fingerprintOf($this->viewFor($cart)));
    }

    /**
     * 注文の確定が断った文（確定してしまったらテストを落とす。店の中で呼ぶ）
     */
    private function refusal(Cart $cart): string
    {
        try {
            $this->place($cart);
        } catch (OrderException $e) {
            return $e->getMessage();
        }

        $this->fail('断られるはずの注文が確定した');
    }

    private function viewFor(Cart $cart): CartView
    {
        return app(CartService::class)->view($cart, self::input()->destination(), 'hanako@example.com');
    }

    private static function input(): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null]);
        self::assertNotNull($input);

        return $input;
    }

    private static function key(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function location(): Location
    {
        return Location::query()->orderBy('created_at')->firstOrFail();
    }
}
