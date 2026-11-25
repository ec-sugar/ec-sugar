<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Catalog\Models\Category;
use App\Core\Discount\Models\CouponRedemption;
use App\Core\Discount\Services\CouponService;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Addons\AddonSelection;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\AddonService;
use App\Core\Listing\Services\ListingService;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Tax\TaxClass;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\TaxBreakdown;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Checkout\PlaceSingleOrder;
use App\Storefront\Orders\OrderMail;
use App\Storefront\Orders\OrderReader;
use Closure;
use Dom\HTMLDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 軽減税率（ADR 0063）。商品の区分から率が決まり、消費税は率ごとに 1 回だけ切り捨てる。
 * オプションは親の商品の率、送料は標準、クーポンの値引きは対象の行の率ごとに分ける。確かめの画面と注文の内訳は 1 円もずれない
 */
final class ReducedTaxTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    /** @var array<string, string> 名前 => 商品の ID */
    private array $ids = [];

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'rtx', 'name' => '菓子店']);
        $this->tenant->domains()->create(['domain' => 'rtx.localhost']);
        $this->ids = $this->inside(function (): array {
            $seller = Seller::query()->value('id');
            $location = Location::query()->orderBy('id')->firstOrFail();
            $ids = [];

            foreach ([
                // 1 個 148 円の焼き菓子 2 つ: 1 行ごとに丸めると 10 + 10 = 20 円、率ごとに丸めると 296 円で 21 円（端数の境）
                'madeleine' => ['マドレーヌ', 148, TaxClass::Reduced],
                'financier' => ['フィナンシェ', 148, TaxClass::Reduced],
                'tin' => ['クッキー缶', 2000, TaxClass::Reduced],
                'tote' => ['トートバッグ', 1100, TaxClass::Standard],
            ] as $key => [$title, $price, $class]) {
                $listing = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => $title, 'unit_amount' => $price, 'status' => 'active', 'tax_class' => $class]);
                app(StockService::class)->receive($listing, $location, 30);
                $ids[$key] = $listing->id;
            }

            app(AddonService::class)->define(Listing::query()->findOrFail($ids['tin']), 1, [
                ['kind' => 'choice', 'name' => 'ギフト包装', 'required' => true, 'choices' => [['label' => 'なし', 'amount' => 0], ['label' => 'あり', 'amount' => 330]]],
            ]);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 10000]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);

            return $ids;
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

    public function test_a_mixed_order_rounds_the_tax_once_per_rate_and_ships_at_the_standard_rate(): void
    {
        $sale = $this->inside(function (): Transaction {
            $cart = $this->cart(['madeleine' => 1, 'financier' => 1, 'tote' => 1]);
            $view = app(CartService::class)->view($cart);
            // 確かめの画面の内訳: 8% は 148 + 148 = 296 円（うち 21 円）、10% は 1,100 + 送料 550 = 1,650 円（うち 150 円）
            $this->assertSame([[800, 296, 21], [1000, 1650, 150]], self::rows($view->taxBreakdown()));
            $this->assertSame('8% 対象 ¥296（うち消費税 ¥21）、10% 対象 ¥1,650（うち消費税 ¥150）', $view->taxBreakdown()->text());

            $sale = $this->place($cart);
            // 確かめの画面と注文の内訳は同じ
            $this->assertSame(self::rows($view->taxBreakdown()), self::rows(TaxBreakdown::ofTransaction($sale)));

            return $sale;
        });

        $this->inside(function () use ($sale): void {
            // 率は商品の区分から、送料は標準
            $this->assertSame([800, 800, 1000, 1000], $sale->lines()->orderBy('position')->pluck('tax_rate_bp')->map(static fn ($r): int => (int) $r)->all());
            $this->assertSame(1000, (int) $sale->lines()->where('kind', 'shipping')->value('tax_rate_bp'));
            // 合計 1,946 円、消費税は率ごとに切り捨てた 21 + 150 = 171 円（1 行ごとに丸めると 170 円になる）
            $this->assertSame([1946, 171, 1775], [$sale->total_amount, $sale->tax_amount, $sale->subtotal_amount]);

            // 仕訳の消費税は率ごとの税額の合計
            $this->assertSame(171, app(LedgerService::class)->balances($sale)['tax']);

            // ご注文の確認: 軽減の商品に印、率ごとの内訳
            $order = app(OrderReader::class)->read($sale);
            $this->assertSame([true, true, false], array_column($order->items, 'reduced'));
            $this->assertNotNull($order->taxes);
            $this->assertTrue($order->taxes->hasReduced());
            $this->assertSame([[800, 296, 21], [1000, 1650, 150]], self::rows($order->taxes));
        });
    }

    public function test_options_follow_the_parent_rate_and_the_mail_shows_the_breakdown(): void
    {
        $sale = $this->inside(function (): Transaction {
            $tin = Listing::query()->findOrFail($this->ids['tin']);
            $gift = (string) ListingAddon::query()->where('listing_id', $tin->id)->value('id');
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $tin->id)->value('id'), 2, AddonSelection::resolve($tin, [$gift => '1']));
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->ids['tote'])->value('id'), 1);

            return $this->place($cart);
        });

        $this->inside(function () use ($sale): void {
            // ギフト包装の行（330 × 2）は親のクッキー缶と同じ 8%
            $addon = $sale->lines()->where('kind', 'addon')->sole();
            $this->assertSame([660, 800], [$addon->amount, $addon->tax_rate_bp]);
            // 8%: 4,000 + 660 = 4,660（うち 345）、10%: 1,100 + 550 = 1,650（うち 150）
            $this->assertSame([[800, 4660, 345], [1000, 1650, 150]], self::rows(TaxBreakdown::ofTransaction($sale)));
            $this->assertSame(345 + 150, $sale->tax_amount);

            $mail = new OrderMail('order_paid', '菓子店', app(OrderReader::class)->read($sale), 'http://rtx.localhost/orders/x');
            $html = $mail->render();
            foreach (['クッキー缶 ※ × 2', '8%※ 対象', '¥4,660（うち消費税 ¥345）', '10% 対象', '¥1,650（うち消費税 ¥150）', '※ は軽減税率（8%）の対象です。'] as $text) {
                $this->assertStringContainsString($text, $html, $text);
            }
            $this->assertStringNotContainsString('トートバッグ ※', $html);

            $text = view('mail.order-text', ['kind' => 'order_paid', 'shopName' => '菓子店', 'order' => app(OrderReader::class)->read($sale), 'link' => 'http://rtx.localhost/orders/x'])->render();
            foreach (['（8%※ 対象 ¥4,660　うち消費税 ¥345）', '（10% 対象 ¥1,650　うち消費税 ¥150）', '※ は軽減税率（8%）の対象です。'] as $line) {
                $this->assertStringContainsString($line, $text, $line);
            }
        });
    }

    public function test_a_paid_wrapping_set_to_the_standard_rate_is_taxed_apart_from_the_food_with_a_coupon(): void
    {
        // 別料金のギフト包装は、食品に付けても標準 10%（国税庁の案内）。店がオプションの税区分を「標準」にする
        $this->inside(function (): void {
            $tin = Listing::query()->findOrFail($this->ids['tin']);
            app(AddonService::class)->define($tin, $tin->version, [
                ['kind' => 'choice', 'name' => 'ギフト包装', 'required' => true, 'tax_class' => 'standard', 'choices' => [['label' => 'なし', 'amount' => 0], ['label' => 'あり', 'amount' => 330]]],
            ]);
        });
        $this->coupon(['code' => 'GIFT300', 'kind' => 'fixed', 'value' => 300]);

        $sale = $this->inside(function (): Transaction {
            $tin = Listing::query()->findOrFail($this->ids['tin']);
            $gift = (string) ListingAddon::query()->where('listing_id', $tin->id)->value('id');
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $tin->id)->value('id'), 2, AddonSelection::resolve($tin, [$gift => '1']));
            $carts->applyCoupon($cart, 'GIFT300');
            $view = $carts->view($cart);
            // 値引き 300 円を 8%（4,000）と 10%（包装 660）の比で 258 : 42 に。
            // 8%: 4,000 − 258 = 3,742（うち 277）、10%: 660 − 42 + 送料 550 = 1,168（うち 106）。確かめの画面と注文で同じ
            $this->assertSame([[800, 3742, 277], [1000, 1168, 106]], self::rows($view->taxBreakdown()));
            $sale = $this->place($cart);
            $this->assertSame(self::rows($view->taxBreakdown()), self::rows(TaxBreakdown::ofTransaction($sale)));

            return $sale;
        });

        $this->inside(function () use ($sale): void {
            $this->assertSame([660, 1000], [$sale->lines()->where('kind', 'addon')->sole()->amount, $sale->lines()->where('kind', 'addon')->sole()->tax_rate_bp]);
            $this->assertSame(800, $sale->lines()->where('amount', 4000)->sole()->tax_rate_bp);
            $this->assertSame([[-258, 800], [-42, 1000]], $sale->lines()->where('kind', 'discount')->orderBy('position')->get()->map(static fn (TransactionLine $l): array => [$l->amount, $l->tax_rate_bp])->all());
            $this->assertSame(277 + 106, $sale->tax_amount);
        });

        // 知らない税区分は保存で断る
        $this->inside(function (): void {
            $tin = Listing::query()->findOrFail($this->ids['tin']);
            try {
                app(AddonService::class)->define($tin, $tin->version, [['kind' => 'toggle', 'name' => '名入れ', 'amount' => 550, 'tax_class' => 'zero']]);
                $this->fail('知らない税区分を保存した');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('addons.0.tax_class', $e->errors());
            }
        });
    }

    public function test_a_coupon_discount_is_split_by_rate_and_matches_the_confirmation(): void
    {
        $this->coupon(['code' => 'SWEETS500', 'kind' => 'fixed', 'value' => 500]);

        $sale = $this->inside(function (): Transaction {
            $cart = $this->cart(['madeleine' => 10, 'tote' => 1]);
            app(CartService::class)->applyCoupon($cart, 'SWEETS500');
            $view = app(CartService::class)->view($cart);
            // 500 円を対象の額の率ごとの合計（1,480 : 1,100）で 287 : 213 に。8%: 1,480 − 287 = 1,193（うち 88）、
            // 10%: 1,100 − 213 + 送料 550 = 1,437（うち 130）。値引きを 10% だけにすると 109 + 104 = 213 円になる
            $this->assertSame([[800, 1193, 88], [1000, 1437, 130]], self::rows($view->taxBreakdown()));
            $sale = $this->place($cart);
            $this->assertSame(self::rows($view->taxBreakdown()), self::rows(TaxBreakdown::ofTransaction($sale)));

            return $sale;
        });

        $this->inside(function () use ($sale): void {
            $this->assertSame([2630, 218], [$sale->total_amount, $sale->tax_amount]);
            // 値引きの行は率ごとに 1 行、名前は同じ。どちらも送料の前
            $discounts = $sale->lines()->where('kind', 'discount')->orderBy('position')->get();
            $this->assertSame([[-287, 800], [-213, 1000]], $discounts->map(static fn (TransactionLine $l): array => [$l->amount, $l->tax_rate_bp])->all());
            $this->assertSame(['クーポン（SWEETS500）'], $discounts->pluck('description')->unique()->values()->all());
            $this->assertLessThan($sale->lines()->where('kind', 'shipping')->value('position'), $discounts->max('position'));

            // 使った記録の割り振り（明細の位置ごと）を率ごとに足すと、値引きの行と同じ
            $allocations = CouponRedemption::query()->sole()->allocations;
            $rates = $sale->lines()->pluck('tax_rate_bp', 'position');
            $byRate = [];
            foreach ($allocations as $position => $amount) {
                $byRate[(int) $rates[$position]] = ($byRate[(int) $rates[$position]] ?? 0) + $amount;
            }
            ksort($byRate);
            $this->assertSame([800 => 287, 1000 => 213], $byRate);

            // お客さんの画面は同じ名前の値引きを 1 つにまとめる
            $this->assertSame([['title' => 'クーポン（SWEETS500）', 'amountLabel' => '−¥500']], app(OrderReader::class)->read($sale)->discounts);
        });
    }

    public function test_a_percent_coupon_on_one_category_and_free_shipping_touch_only_their_rate(): void
    {
        $this->inside(function (): void {
            $category = Category::create(['name' => '雑貨', 'slug' => 'goods', 'position' => 0]);
            Listing::query()->findOrFail($this->ids['tote'])->categories()->attach($category->id);
            app(CouponService::class)->create(['code' => 'GOODS10', 'kind' => 'percent', 'value' => 10, 'target' => 'categories', 'target_ids' => [$category->id], 'name' => 'テスト'], 'test');
            app(CouponService::class)->create(['code' => 'SHIPFREE', 'kind' => 'free_shipping', 'value' => 0, 'name' => 'テスト'], 'test');
        });

        [$percent, $free] = $this->inside(function (): array {
            $cart = $this->cart(['madeleine' => 2, 'tote' => 1]);
            app(CartService::class)->applyCoupon($cart, 'GOODS10');
            $percent = $this->place($cart);

            $cart = $this->cart(['madeleine' => 2, 'tote' => 1]);
            app(CartService::class)->applyCoupon($cart, 'SHIPFREE');

            return [$percent, $this->place($cart)];
        });

        $this->inside(function () use ($percent, $free): void {
            // 雑貨だけの 10%（110 円）は 10% の行だけ。8%: 296（うち 21）、10%: 1,100 − 110 + 550 = 1,540（うち 140）
            $this->assertSame([[-110, 1000]], $percent->lines()->where('kind', 'discount')->get()->map(static fn (TransactionLine $l): array => [$l->amount, $l->tax_rate_bp])->all());
            $this->assertSame([[800, 296, 21], [1000, 1540, 140]], self::rows(TaxBreakdown::ofTransaction($percent)));

            // 送料無料は送料の行も値引きの行も作らない。10% は商品だけ
            $this->assertSame(0, $free->lines()->whereIn('kind', ['discount', 'shipping'])->count());
            $this->assertSame([[800, 296, 21], [1000, 1100, 100]], self::rows(TaxBreakdown::ofTransaction($free)));
        });
    }

    public function test_a_subscription_order_keeps_its_adjustments_at_the_product_rate(): void
    {
        $sale = $this->inside(function (): Transaction {
            $tin = Listing::query()->findOrFail($this->ids['tin']);
            $variant = ListingVariant::query()->where('listing_id', $tin->id)->firstOrFail();
            $account = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'storefront');

            // 定期便の回の形: 約束の単価 1,900（今は 2,000）× 2 個、定期の値引き 10%、送料は店の決まり
            return app(PlaceSingleOrder::class)->place($tin, $variant, 2, 1000, '定期便の値引き', $this->input(), $account, $this->key(), 'storefront', agreedUnitAmount: 1900);
        });

        $this->inside(function () use ($sale): void {
            $lines = $sale->lines()->orderBy('position')->get()->map(static fn (TransactionLine $l): array => [$l->kind ?? 'item', $l->amount, $l->tax_rate_bp])->all();
            // 約束の価格の差・値引きは商品と同じ 8%、送料は 10%
            $this->assertSame([['item', 4000, 800], ['price_agreed', -200, 800], ['discount', -380, 800], ['shipping', 550, 1000]], $lines);
            // 8%: 3,420（うち 253）、10%: 550（うち 50）
            $this->assertSame([[800, 3420, 253], [1000, 550, 50]], self::rows(TaxBreakdown::ofTransaction($sale)));
            $this->assertSame(303, $sale->tax_amount);
        });
    }

    public function test_cancelling_reverses_the_tax_journal_of_a_mixed_order(): void
    {
        $sale = $this->inside(fn (): Transaction => $this->place($this->cart(['madeleine' => 1, 'financier' => 1, 'tote' => 1])));

        $this->inside(function () use ($sale): void {
            $tax = LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Tax)->whereNull('reverses_journal_id')->sole();
            $this->assertSame(171, (int) $tax->postings()->where('account', 'tax')->value('amount'));

            app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale->id), 'operator:test');

            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($sale->id)->status);
            // 合意の仕訳（売上と消費税）を逆にして、どの口座も 0
            $this->assertSame([], array_filter(app(LedgerService::class)->balances($sale)));
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Tax)->whereNotNull('reverses_journal_id')->count());
        });
    }

    public function test_existing_products_stay_standard_and_placed_orders_keep_their_rate(): void
    {
        $this->inside(function (): void {
            // 区分を書かずに作った商品（移行の前からの商品と同じ）は標準
            $plain = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '皿', 'unit_amount' => 900]);
            $this->assertSame(TaxClass::Standard, $plain->refresh()->tax_class);
            // 列の既定（移行で今ある行を埋めた値）も標準
            DB::table('listings')->where('id', $plain->id)->update(['tax_class' => DB::raw('DEFAULT')]);
            $this->assertSame('standard', DB::table('listings')->where('id', $plain->id)->value('tax_class'));

            // DB も知らない区分を断る（アプリの確かめをすり抜けても）
            try {
                DB::transaction(static fn () => DB::table('listings')->where('id', $plain->id)->update(['tax_class' => 'zero']));
                $this->fail('知らない区分が入った');
            } catch (QueryException $e) {
                $this->assertStringContainsString('listings_tax_class_known', $e->getMessage());
            }
        });

        $sale = $this->inside(fn (): Transaction => $this->place($this->cart(['madeleine' => 1, 'tote' => 1])));

        $this->inside(function () use ($sale): void {
            $before = self::rows(TaxBreakdown::ofTransaction($sale));

            // 後で区分を変えても、作った注文の明細と内訳は注文の時の率のまま
            foreach (['madeleine' => TaxClass::Standard, 'tote' => TaxClass::Reduced] as $key => $class) {
                $listing = Listing::query()->findOrFail($this->ids[$key]);
                app(ListingService::class)->update($listing, $listing->version, ['tax_class' => $class], 'operator:test');
            }

            $fresh = Transaction::query()->findOrFail($sale->id);
            $this->assertSame([800, 1000, 1000], $fresh->lines()->orderBy('position')->pluck('tax_rate_bp')->map(static fn ($r): int => (int) $r)->all());
            $this->assertSame($before, self::rows(TaxBreakdown::ofTransaction($fresh)));
            $this->assertSame($sale->tax_amount, $fresh->tax_amount);
        });
    }

    public function test_the_order_is_not_placed_when_the_rate_changed_after_the_confirmation(): void
    {
        $this->inside(function (): void {
            $cart = $this->cart(['madeleine' => 1, 'tote' => 1]);
            $fingerprint = CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart));

            // 確かめの後に店が区分を変えた（税込みの合計は同じでも、見せた内訳と違う）
            $listing = Listing::query()->findOrFail($this->ids['madeleine']);
            app(ListingService::class)->update($listing, $listing->version, ['tax_class' => TaxClass::Standard], 'operator:test');

            try {
                app(PlaceOrder::class)->place($cart, $this->input(), $this->key(), $fingerprint);
                $this->fail('内訳が変わったのに注文が確定した');
            } catch (OrderException $e) {
                $this->assertTrue($e->changed);
            }

            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_every_official_theme_shows_the_breakdown_on_the_confirmation_and_the_order(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->ids['madeleine'], 'quantity' => '1']);
        $this->send('POST', '/cart/items', ['listing_id' => $this->ids['tote'], 'quantity' => '1']);
        $sale = $this->inside(fn (): Transaction => $this->place($this->cart(['madeleine' => 1, 'tote' => 1])));
        $link = $this->inside(fn (): string => OrderReader::link(Transaction::query()->findOrFail($sale->id), 'http://rtx.localhost'));

        foreach (['default', 'kinari', 'fashion', 'botanica'] as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('POST', '/checkout', [
                'buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => ''],
                'same_as_buyer' => '1',
            ])->assertRedirect('http://rtx.localhost/checkout/confirm');

            $pages = [
                '確認の画面' => (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent(),
                'ご注文の確認' => (string) $this->send('GET', (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY))->assertOk()->getContent(),
            ];

            foreach ($pages as $name => $html) {
                $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
                $rows = array_map(static fn ($n): string => trim((string) preg_replace('/\s+/u', ' ', (string) $n->textContent)), iterator_to_array($dom->querySelectorAll('[data-tax-breakdown] [data-test="tax-row"]')));
                $this->assertSame(['（8%※ 対象 ¥148 うち消費税 ¥10）', '（10% 対象 ¥1,650 うち消費税 ¥150）'], $rows, "{$theme} の{$name}");
                $this->assertStringContainsString('※ は軽減税率（8%）の対象です。', $html, "{$theme} の{$name}");
                $this->assertStringContainsString('マドレーヌ ※', $html, "{$theme} の{$name}");
                $this->assertStringNotContainsString('トートバッグ ※', $html, "{$theme} の{$name}");
            }
        }
    }

    /**
     * 内訳を [率, 対象の額, 消費税額] に
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private static function rows(TaxBreakdown $breakdown): array
    {
        return array_map(static fn (array $row): array => [$row['rateBp'], $row['amount'], $row['tax']], $breakdown->rows);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function coupon(array $values): void
    {
        $this->inside(fn () => app(CouponService::class)->create($values + ['name' => 'テスト'], 'test'));
    }

    /**
     * @param  array<string, int>  $items  名前 => 数
     */
    private function cart(array $items): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();

        foreach ($items as $key => $quantity) {
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->ids[$key])->value('id'), $quantity);
        }

        return $cart;
    }

    private function place(Cart $cart): Transaction
    {
        return app(PlaceOrder::class)->place($cart, $this->input(), $this->key(), CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart)));
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

        $url = "http://rtx.localhost{$path}";
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
