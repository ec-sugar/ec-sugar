<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Services\ShopHome;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Preorders;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\Preorder\PreorderUnavailable;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\DeliveryDates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\Services\TransactionException;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Orders\OrderMail;
use App\Storefront\Orders\OrderReader;
use Carbon\CarbonImmutable;
use EcSugar\PluginPreorder\Mail\PreorderDateChangedMail;
use EcSugar\PluginPreorder\Models\PreorderItem;
use EcSugar\PluginPreorder\Models\PreorderListing;
use EcSugar\PluginPreorder\PreorderAdminPage;
use EcSugar\PluginPreorder\PreorderCheckout;
use EcSugar\PluginPreorder\PreorderCounts;
use EcSugar\PluginPreorder\PreorderListings;
use EcSugar\PluginPreorder\PreorderRefused;
use EcSugar\PluginPreorder\SendPreorderMail;
use EcSugar\PluginProductFeed\FeedBuilder;
use EcSugar\PluginProductFeed\FeedFormat;
use EcSugar\PluginProductFeed\FeedWriter;
use EcSugar\PluginRestock\RestockTargets;
use EcSugar\PluginShippingCsv\Imports;
use EcSugar\PluginShippingCsv\Models\ShippingImportRow;
use EcSugar\PluginShippingCsv\Parcels;
use EcSugar\PluginShippingCsv\RowReason;
use EcSugar\PluginShopNotify\Jobs\SendShopNotice;
use EcSugar\PluginShopNotify\LowStock;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 予約販売の App（ADR 0073）: 発売日の前の商品は在庫によらず予約の上限まで受け、お支払いは注文の時。
 * 予約の数は注文の確定の錠の中で数え直し、払われずに消えた注文で戻る。予約の品のある注文は発売日にまとめて送り、その日まで発送できない。
 * お届け日時の指定は受けない。発売日を変えると、発送の前の注文を新しい日に直し、払ったお客さんへメールで知らせる
 */
final class PreorderAppTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const string RELEASE = '2026-11-20';

    private ?string $cart = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        // 日本の時間の 2026-11-10 の朝（発売日の 10 日前）
        $this->travelTo(new CarbonImmutable('2026-11-10 10:00:00', 'Asia/Tokyo'));
        $this->tenant('pra', '予約の店');
        $this->inside('pra', function (): void {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(ShopSettingsService::class)->setShipping(['fee' => 800, 'free_over' => null], 'test');
            app(PluginStates::class)->set('preorder', true, 'test');
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_shop_without_the_app_sells_only_from_stock(): void
    {
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $this->preorder($figure, self::RELEASE);
        $this->inside('pra', fn () => app(PluginStates::class)->set('preorder', false, 'test'));

        $html = $this->page("/products/{$figure}");
        $this->assertStringNotContainsString('予約商品', $html);
        $this->assertStringNotContainsString('予約する', $html);
        $this->assertStringContainsString('売り切れ', $html);
        $this->inside('pra', fn () => $this->assertSame([], app(Preorders::class)->terms([$figure])));
    }

    public function test_a_preorder_is_taken_without_stock_paid_at_once_and_held_until_the_release_date(): void
    {
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $this->preorder($figure, self::RELEASE, capacity: '3');
        // 店はお届け日時の指定を受けている。予約の品のある注文には欄を出さない
        $this->inside('pra', fn () => app(DeliveryDates::class)->update(['dates' => true, 'slots' => ['am'], 'ship_days' => 1, 'transit_days' => 1, 'span_days' => 14, 'cutoff' => null, 'closed_weekdays' => [], 'closed_dates' => []], 'test'));

        // どのテーマの商品の画面にも「予約商品（11月20日ごろ発送）」と「予約する」
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->inside('pra', fn () => Tenant::query()->findOrFail('pra')->forceFill(['theme' => $theme])->save());
            $html = $this->page("/products/{$figure}");
            $this->assertStringContainsString('予約商品（11月20日ごろ発送）', $html, $theme);
            $this->assertMatchesRegularExpression('/data-test="add-to-cart">\s*予約する\s*</', $html, $theme);
        }

        // 在庫 0 でもカートに入り、カートに予約の一言と送る日
        $this->send('POST', '/cart/items', ['listing_id' => $figure, 'quantity' => '2'])->assertRedirect();
        $cart = $this->page('/cart');
        $this->assertStringContainsString('data-test="cart-preorder"', $cart);
        $this->assertStringContainsString('予約商品は発売日（11月20日ごろ）にお送りします。', $cart);

        // 注文の入力にお届け日時の欄が無い。送られてきても使わない
        $checkout = $this->page('/checkout');
        $this->assertStringNotContainsString('data-test="delivery-fields"', $checkout);
        $this->assertStringContainsString('予約商品は発売日（11月20日ごろ）にお送りします。', $checkout);
        $date = CarbonImmutable::now('Asia/Tokyo')->addDays(3)->format('Y-m-d');
        $this->send('POST', '/checkout', ['buyer' => self::buyer(), 'same_as_buyer' => '1', 'delivery' => ['date' => $date, 'slot' => 'am']])->assertRedirect('http://pra.localhost/checkout/confirm');
        $confirm = $this->page('/checkout/confirm');
        $this->assertStringNotContainsString('data-test="delivery-summary"', $confirm);
        $this->assertStringContainsString('予約商品は発売日（11月20日ごろ）にお送りします。', $confirm);
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertStatus(303);

        $order = $this->inside('pra', function () use ($figure): string {
            $order = Transaction::query()->sole();
            // 予約の品は在庫を押さえない（拠点の無い行）。在庫は 0 のまま
            $line = TransactionLine::query()->where('transaction_id', $order->id)->where('line_type', LineType::Item)->sole();
            $this->assertNull($line->location_id);
            $this->assertSame(2, (int) $line->quantity);
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $figure)->sum('reserved'));
            // 受渡は発売日から。お届けの希望は持たない
            $fulfillment = Fulfillment::query()->where('transaction_id', $order->id)->sole();
            $this->assertSame([self::RELEASE, null, null], [$fulfillment->shipsOn(), $fulfillment->delivery_date, $fulfillment->delivery_slot]);
            $this->assertNotNull($fulfillment->location_id);
            $placed = DomainEvent::query()->where('type', Preorders::PLACED)->where('aggregate_id', $order->id)->sole();
            $this->assertEquals(['ships_on' => self::RELEASE, 'listings' => [$figure => 2]], $placed->payload);
            $this->assertSame([2, self::RELEASE], [PreorderItem::query()->sole()->quantity, PreorderItem::query()->sole()->releaseDate()]);

            return $order->id;
        });
        $this->pay($order);

        // 発売日の前の日は発送できない（ホームのやることにも出さない）
        $this->travelTo(new CarbonImmutable('2026-11-19 23:59:00', 'Asia/Tokyo'));
        $this->inside('pra', function () use ($order): void {
            $this->assertSame(0, (new PreorderAdminPage)->attention()[0]->count);
            $fulfillment = Fulfillment::query()->where('transaction_id', $order)->sole();

            try {
                app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($order), $fulfillment->id, 'yamato', '1234', 'operator:test');
                $this->fail('発売日の前に発送できた');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('11月20日 から発送できます', $e->getMessage());
            }
            $this->assertSame('pending', Fulfillment::query()->findOrFail($fulfillment->id)->status->value);
        });

        // 発売日の当日から発送できる。やることに「発送を始められる予約の注文」
        $this->travelTo(new CarbonImmutable('2026-11-20 00:00:30', 'Asia/Tokyo'));
        $this->inside('pra', function () use ($order, $figure): void {
            $this->assertSame(1, (new PreorderAdminPage)->attention()[0]->count);
            // 発売日になった商品は予約でなくなる（在庫 0 なので売り切れ）
            $this->assertSame([], app(Preorders::class)->terms([$figure]));
            $fulfillment = Fulfillment::query()->where('transaction_id', $order)->sole();
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($order), $fulfillment->id, 'yamato', '1234', 'operator:test');
            $this->assertSame('shipped', Fulfillment::query()->findOrFail($fulfillment->id)->status->value);
            $this->assertSame(0, (new PreorderAdminPage)->attention()[0]->count);
        });
        $this->assertStringContainsString('売り切れ', $this->page("/products/{$figure}"));
    }

    public function test_the_cap_is_counted_under_the_lock_exactly_and_an_unpaid_order_gives_it_back(): void
    {
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $this->preorder($figure, self::RELEASE, capacity: '2');

        $this->inside('pra', function () use ($figure): void {
            // 3 つのカートで、どれも確かめの画面まで進む（どれも残りがある）
            $carts = [$this->cart($figure, 1), $this->cart($figure, 1), $this->cart($figure, 1)];
            $prints = array_map(fn (Cart $cart): string => CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart)), $carts);
            $first = $this->place($carts[0], $prints[0]);
            // 上限ちょうどまでは受ける
            $this->place($carts[1], $prints[1]);

            // 3 つ目は、確かめた指紋のままでも錠の中で数え直して断る（注文を作らない）
            try {
                $this->place($carts[2], $prints[2], 'saburo@example.com');
                $this->fail('上限を超えて予約できた');
            } catch (OrderException $e) {
                $this->assertSame(PlaceOrder::UNAVAILABLE, $e->getMessage());
            }
            $this->assertSame(2, Transaction::query()->count());
            $this->assertSame([$figure => 2], PreorderCounts::taken([$figure]));

            // 払われずに取り消された注文は、数を戻す（取消で数えなくなる）
            app(PaymentService::class)->startCheckout($first, 'x', 'y');
            $this->assertTrue(app(SaleOrderService::class)->cancelIfUnpaid($first, 'test'));
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($first->id)->status);
            $this->assertSame([$figure => 1], PreorderCounts::taken([$figure]));
            $this->place($carts[2], CheckoutSummary::fingerprintOf(app(CartService::class)->view($carts[2])), 'saburo@example.com');
            $this->assertSame([$figure => 2], PreorderCounts::taken([$figure]));
        });

        // 上限に届いた商品は「予約の受付を終えました」で、カートに入らない
        $html = $this->page("/products/{$figure}");
        $this->assertStringContainsString('予約の受付は終了しました', $html);
        $this->assertStringNotContainsString('data-test="add-to-cart"', $html);
        $this->send('POST', '/cart/items', ['listing_id' => $figure, 'quantity' => '1']);
        $this->inside('pra', fn () => $this->assertSame(0, app(CartService::class)->count(app(CartService::class)->find($this->cart))));
    }

    public function test_the_app_recounts_the_whole_order_and_refuses_more_than_the_cap(): void
    {
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $this->preorder($figure, self::RELEASE, capacity: '2');

        $this->inside('pra', function () use ($figure): void {
            $order = $this->place($this->cart($figure, 1));

            // 同じ商品の種類違いを足した数で数える（カートの行ごとの残りの確かめをすり抜けても、App が断る）
            $this->expectException(PreorderUnavailable::class);
            $this->expectExceptionMessage(sprintf(PreorderCheckout::FULL, '限定のフィギュア'));
            app(PreorderCheckout::class)->reserve($order->id, [$figure => 2], 'test');
        });
    }

    public function test_acceptance_ends_after_the_last_day_and_the_release_date_returns_to_stock(): void
    {
        $figure = $this->listing('限定のフィギュア', 8800, 3);
        $this->preorder($figure, self::RELEASE, endsOn: '2026-11-15');

        // 受付の終わりの日の夜の最後まで受ける（在庫の 3 点によらず予約で）
        $this->travelTo(new CarbonImmutable('2026-11-15 23:59:59', 'Asia/Tokyo'));
        $stale = $this->inside('pra', function () use ($figure): array {
            $cart = $this->cart($figure, 1);
            $this->assertSame(1, app(CartService::class)->view($cart)->count);

            return [$cart, CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart))];
        });
        $this->assertStringContainsString('予約商品（11月20日ごろ発送）', $this->page("/products/{$figure}"));

        // 次の日の 0 時から受けない（確かめの画面の後でも、確定で断る）
        $this->travelTo(new CarbonImmutable('2026-11-16 00:00:00', 'Asia/Tokyo'));
        $html = $this->page("/products/{$figure}");
        $this->assertStringContainsString('予約の受付は終了しました', $html);
        $this->assertStringNotContainsString('data-test="add-to-cart"', $html);
        $this->inside('pra', function () use ($stale): void {
            try {
                $this->place($stale[0], $stale[1]);
                $this->fail('受付の終わりの後に予約できた');
            } catch (OrderException) {
                $this->assertSame(0, Transaction::query()->count());
            }
        });

        // 発売日からはふつうの在庫の販売（「在庫あり」「カートに入れる」）。注文は在庫を押さえ、発送を始める日を持たない
        $this->travelTo(new CarbonImmutable('2026-11-20 09:00:00', 'Asia/Tokyo'));
        $html = $this->page("/products/{$figure}");
        $this->assertStringNotContainsString('予約商品', $html);
        $this->assertMatchesRegularExpression('/data-test="add-to-cart">\s*カートに入れる\s*</', $html);
        $this->inside('pra', function () use ($figure): void {
            $order = $this->place($this->cart($figure, 1));
            $this->assertNotNull(TransactionLine::query()->where('transaction_id', $order->id)->where('line_type', LineType::Item)->value('location_id'));
            $this->assertNull(Fulfillment::query()->where('transaction_id', $order->id)->sole()->shipsOn());
            $this->assertSame(1, (int) InventoryStock::query()->where('listing_id', $figure)->sum('reserved'));
            $this->assertSame(0, PreorderItem::query()->count());
        });
    }

    public function test_a_mixed_cart_is_sent_together_on_the_latest_release_date(): void
    {
        $towel = $this->listing('いつものタオル', 1200, 5);
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $book = $this->listing('画集', 3300, 0);
        $this->preorder($figure, self::RELEASE);
        $this->preorder($book, '2026-12-01');
        $this->inside('pra', fn () => app(DeliveryDates::class)->update(['dates' => true, 'slots' => [], 'ship_days' => 1, 'transit_days' => 1, 'span_days' => 14, 'cutoff' => null, 'closed_weekdays' => [], 'closed_dates' => []], 'test'));

        foreach ([$towel, $figure, $book] as $listing) {
            $this->send('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1'])->assertRedirect();
        }

        // カート・注文の入力に「予約商品の発売日（12月1日ごろ）にまとめてお送りします」。送料は 1 回（同じ便）
        $note = '予約商品の発売日（12月1日ごろ）にまとめてお送りします。';
        $this->assertStringContainsString($note, $this->page('/cart'));
        $checkout = $this->page('/checkout');
        $this->assertStringContainsString($note, $checkout);
        $this->assertStringNotContainsString('data-test="delivery-fields"', $checkout);

        $this->inside('pra', function () use ($towel, $figure, $book): void {
            $cart = app(CartService::class)->find($this->cart);
            $this->assertNotNull($cart);
            $view = app(CartService::class)->view($cart);
            $this->assertSame(['2026-12-01', 800], [$view->preorderShipDate(), $view->shipping]);
            $order = $this->place($cart, CheckoutSummary::fingerprintOf($view));

            // 1 つの受渡にまとめ、いちばん遅い発売日から。ふつうの品は在庫を押さえる
            $fulfillment = Fulfillment::query()->where('transaction_id', $order->id)->sole();
            $this->assertSame('2026-12-01', $fulfillment->shipsOn());
            $this->assertSame(3, $fulfillment->items()->count());
            $this->assertSame(1, (int) InventoryStock::query()->where('listing_id', $towel)->sum('reserved'));
            $located = TransactionLine::query()->where('transaction_id', $order->id)->where('line_type', LineType::Item)->pluck('location_id', 'listing_id');
            $this->assertNotNull($located[$towel]);
            $this->assertNull($located[$figure]);
            $this->assertNull($located[$book]);
            $this->assertEquals([$book => 1, $figure => 1], DomainEvent::query()->where('type', Preorders::PLACED)->sole()->payload['listings']);
        });
    }

    public function test_a_release_date_moved_after_the_confirmation_stops_the_order(): void
    {
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $this->preorder($figure, self::RELEASE);

        $this->inside('pra', function () use ($figure): void {
            $cart = $this->cart($figure, 1);
            $fingerprint = CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart));
            // 確かめた後に発売日が延びた: 見せた「引き渡しの時期」と違うので確定しない
            app(PreorderListings::class)->save(Listing::query()->findOrFail($figure), ['enabled' => true, 'release_date' => '2026-11-25', 'ends_on' => null, 'capacity' => null], 'test');

            try {
                $this->place($cart, $fingerprint);
                $this->fail('発売日の変わった注文を確定できた');
            } catch (OrderException $e) {
                $this->assertTrue($e->changed);
            }
            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_changing_the_release_date_moves_unshipped_orders_and_mails_paid_customers(): void
    {
        Queue::fake();
        Mail::fake();
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $this->preorder($figure, self::RELEASE, capacity: '5');
        [$paid, $unpaid] = $this->inside('pra', fn (): array => [$this->place($this->cart($figure, 2))->id, $this->place($this->cart($figure, 1), email: 'jiro@example.com')->id]);
        $this->pay($paid);

        $this->inside('pra', function () use ($figure, $paid, $unpaid): void {
            $listing = Listing::query()->findOrFail($figure);
            $listings = app(PreorderListings::class);

            // 上限は今の予約の数（3 点）より下げられない
            try {
                $listings->save($listing, ['enabled' => true, 'release_date' => self::RELEASE, 'ends_on' => null, 'capacity' => '2'], 'test');
                $this->fail('予約の数より少ない上限にできた');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('capacity', $e->errors());
            }

            // 発売日を延ばす: 発送の前の注文は新しい日に直し、受渡も新しい日から
            $listings->save($listing, ['enabled' => true, 'release_date' => '2026-12-05', 'ends_on' => null, 'capacity' => '5'], 'operator:test');
            foreach ([$paid, $unpaid] as $order) {
                $this->assertSame('2026-12-05', PreorderItem::query()->where('transaction_id', $order)->sole()->releaseDate());
                $this->assertSame('2026-12-05', Fulfillment::query()->where('transaction_id', $order)->sole()->shipsOn());
            }
            $this->assertSame(2, DomainEvent::query()->where('type', 'fulfillment.ships_on_changed')->count());
            $changed = DomainEvent::query()->where('type', 'preorder.release_date_changed')->sole();
            $this->assertEquals(['from' => self::RELEASE, 'to' => '2026-12-05', 'orders' => 2], array_intersect_key($changed->payload, array_flip(['from', 'to', 'orders'])));

            // 知らせは払った注文のお客さんだけ（お支払いの前の注文には送らない）
            Queue::assertPushed(SendPreorderMail::class, 1);
            Queue::assertPushed(SendPreorderMail::class, fn (SendPreorderMail $job): bool => $job->transactionId === $paid && $job->from === self::RELEASE && $job->to === '2026-12-05' && $job->listingId === $figure);
            (new SendPreorderMail($paid, self::RELEASE, '2026-12-05', $figure))->handle();
        });

        Mail::assertSent(PreorderDateChangedMail::class, function (PreorderDateChangedMail $mail): bool {
            $mail->assertSeeInText('ご予約の「限定のフィギュア」の発売日が 11月20日 から 12月5日 に変わりました');
            $mail->assertSeeInText('12月5日ごろにまとめてお送りします');
            $mail->assertSeeInText('お待たせしてしまい申し訳ありません');
            // 名前とお届け先は載せない
            $mail->assertDontSeeInText('山田');
            $mail->assertDontSeeInText('神宮前');

            return $mail->hasTo('hanako@example.com');
        });

        // 前倒しで今日を発売日にすると、すぐ発送できる
        $this->inside('pra', function () use ($figure, $paid): void {
            app(PreorderListings::class)->save(Listing::query()->findOrFail($figure), ['enabled' => true, 'release_date' => '2026-11-10', 'ends_on' => null, 'capacity' => '5'], 'operator:test');
            $fulfillment = Fulfillment::query()->where('transaction_id', $paid)->sole();
            $this->assertFalse($fulfillment->heldForRelease());
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($paid), $fulfillment->id, null, null, 'operator:test');
            $this->assertSame('shipped', Fulfillment::query()->findOrFail($fulfillment->id)->status->value);
        });
    }

    public function test_stopping_and_restarting_before_the_release_date_keeps_counting_the_same_preorders(): void
    {
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $this->preorder($figure, self::RELEASE, capacity: '2');

        $this->inside('pra', function () use ($figure): void {
            $this->place($this->cart($figure, 2));
            $listing = Listing::query()->findOrFail($figure);
            $listings = app(PreorderListings::class);

            // やめるとすぐに在庫の販売に戻る（在庫 0 なので売り切れ）
            $listings->save($listing, ['enabled' => false, 'release_date' => null, 'ends_on' => null, 'capacity' => null], 'test');
            $this->assertSame([], app(Preorders::class)->terms([$figure]));

            // 発売日の前に始め直しても同じ回: 受けた 2 点を数えるので、上限 2 のままなら残りは 0
            $listings->save($listing, ['enabled' => true, 'release_date' => self::RELEASE, 'ends_on' => null, 'capacity' => '2'], 'test');
            $this->assertSame(0, $this->acceptable($figure));
            $this->assertSame(1, (int) PreorderListing::query()->where('listing_id', $figure)->value('round'));

        });
    }

    public function test_after_the_release_date_the_same_round_is_delayed_while_orders_wait_and_the_next_round_needs_the_button(): void
    {
        Queue::fake();
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $this->preorder($figure, self::RELEASE, capacity: '2');
        $order = $this->inside('pra', fn (): string => $this->place($this->cart($figure, 2))->id);
        $this->pay($order);

        // 発売日が来たのに品が届かない: 発送の前の予約の注文があるので、延ばすのは同じ回の続き（注文も新しい日に直し、知らせる）
        $this->travelTo(new CarbonImmutable('2026-11-21 09:00:00', 'Asia/Tokyo'));
        $this->inside('pra', function () use ($figure, $order): void {
            $listing = Listing::query()->findOrFail($figure);
            $row = PreorderListing::query()->where('listing_id', $figure)->sole();
            $this->assertSame(1, PreorderListings::pendingOrders($row));
            $this->assertFalse(PreorderListings::canStartNext($row));
            app(PreorderListings::class)->save($listing, ['enabled' => true, 'release_date' => '2026-11-30', 'ends_on' => null, 'capacity' => '2'], 'operator:test');

            $this->assertSame([1, '2026-11-30'], [(int) PreorderListing::query()->where('listing_id', $figure)->value('round'), PreorderItem::query()->sole()->releaseDate()]);
            $this->assertSame('2026-11-30', Fulfillment::query()->where('transaction_id', $order)->sole()->shipsOn());
            Queue::assertPushed(SendPreorderMail::class, fn (SendPreorderMail $job): bool => $job->transactionId === $order && $job->from === self::RELEASE && $job->to === '2026-11-30');
            // また予約になるが、受けた 2 点を数えるので残りは 0
            $this->assertSame(0, $this->acceptable($figure));

            // 発送するまでは「次の予約」は始められない（App の画面にも出さない）
            $this->assertSame([], (new PreorderAdminPage)->record((string) PreorderListing::query()->where('listing_id', $figure)->value('id'))?->actions);
            try {
                app(PreorderListings::class)->nextRound(PreorderListing::query()->where('listing_id', $figure)->value('id'), 'operator:test');
                $this->fail('発送の前に次の予約を始められた');
            } catch (PreorderRefused) {
                $this->assertSame(1, (int) PreorderListing::query()->where('listing_id', $figure)->value('round'));
            }
        });

        // 届いて発送した後は、欄からは始め直せない（前の予約を数えてしまう）。「次の予約を始める」の後に新しい発売日
        $this->travelTo(new CarbonImmutable('2026-11-30 09:00:00', 'Asia/Tokyo'));
        $this->inside('pra', function () use ($figure, $order): void {
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($order), Fulfillment::query()->where('transaction_id', $order)->sole()->id, null, null, 'operator:test');
            $listing = Listing::query()->findOrFail($figure);
            $listings = app(PreorderListings::class);

            try {
                $listings->save($listing, ['enabled' => true, 'release_date' => '2026-12-20', 'ends_on' => null, 'capacity' => '2'], 'test');
                $this->fail('発送の済んだ回を欄から始め直せた');
            } catch (ValidationException $e) {
                $this->assertSame([PreorderListings::NEXT_ROUND], $e->errors()['release_date']);
            }

            $row = PreorderListing::query()->where('listing_id', $figure)->sole();
            $this->assertTrue(PreorderListings::canStartNext($row));
            // App の画面の 1 件に「次の予約を始める」が出て、押すと回が進む
            $page = new PreorderAdminPage;
            $this->assertSame(['next_round'], array_map(static fn ($action): string => $action->key, $page->record($row->id)->actions ?? []));
            $page->act($row->id, 'next_round', 'operator:test');
            $this->assertSame([], $page->record($row->id)?->actions);
            $listings->save($listing, ['enabled' => true, 'release_date' => '2026-12-20', 'ends_on' => null, 'capacity' => '2'], 'test');
            $this->assertSame(2, $this->acceptable($figure));
            $this->assertSame(2, (int) PreorderListing::query()->where('listing_id', $figure)->value('round'));
            $this->assertSame(1, DomainEvent::query()->where('type', 'preorder.next_round')->count());
        });
    }

    public function test_the_mail_goes_only_when_the_shipping_date_of_the_order_moves(): void
    {
        Queue::fake();
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $book = $this->listing('画集', 3300, 0);
        $this->preorder($figure, self::RELEASE);
        $this->preorder($book, '2026-12-01');

        $order = $this->inside('pra', function () use ($figure, $book): string {
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            foreach ([$figure, $book] as $listing) {
                $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $listing)->value('id'), 1);
            }

            return $this->place($cart)->id;
        });
        $this->pay($order);

        $this->inside('pra', function () use ($figure, $order): void {

            // フィギュアが 11/25 に延びても、まとめて送る日（画集の 12/1）は変わらない: 知らせない
            app(PreorderListings::class)->save(Listing::query()->findOrFail($figure), ['enabled' => true, 'release_date' => '2026-11-25', 'ends_on' => null, 'capacity' => null], 'test');
            Queue::assertNotPushed(SendPreorderMail::class);
            $this->assertSame('2026-12-01', Fulfillment::query()->where('transaction_id', $order)->sole()->shipsOn());

            // 12/10 に延びると、まとめて送る日が変わる: どの商品かを付けて知らせる
            app(PreorderListings::class)->save(Listing::query()->findOrFail($figure), ['enabled' => true, 'release_date' => '2026-12-10', 'ends_on' => null, 'capacity' => null], 'test');
            Queue::assertPushed(SendPreorderMail::class, fn (SendPreorderMail $job): bool => $job->transactionId === $order && $job->listingId === $figure && $job->to === '2026-12-10');
            $this->assertSame('2026-12-10', Fulfillment::query()->where('transaction_id', $order)->sole()->shipsOn());
        });
    }

    public function test_stock_notices_and_the_home_do_not_take_preorders_for_stock(): void
    {
        Queue::fake();
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $mug = $this->listing('白いマグ', 1200, 0);
        $this->preorder($figure, self::RELEASE);

        $this->inside('pra', function () use ($figure, $mug): void {
            $figureVariant = (string) ListingVariant::query()->where('listing_id', $figure)->value('id');
            $mugVariant = (string) ListingVariant::query()->where('listing_id', $mug)->value('id');
            $this->assertSame([self::RELEASE, null], [app(Catalog::class)->purchasableVariants([$figureVariant])[$figureVariant]['preorder'], app(Catalog::class)->purchasableVariants([$mugVariant])[$mugVariant]['preorder']]);

            // 再入荷のお知らせ: 予約の商品は「売り切れ」の登録の対象でなく、予約の残りを「入荷」と見ない
            $this->assertSame([], app(RestockTargets::class)->soldOut($figure));
            $this->assertFalse(app(RestockTargets::class)->available($figureVariant));
            $this->assertNotSame([], app(RestockTargets::class)->soldOut($mug));

            // 在庫の少ない知らせ: 予約の残りを在庫と見ない（ふつうの商品は知らせる）
            app(PluginStates::class)->set('shop-notify', true, 'test');
            app(PluginSettings::class)->update('shop-notify', ['mail_to' => 'shop@example.com', 'on_low_stock' => true], 'test');
            app(LowStock::class)->check([$figureVariant, $mugVariant]);
            Queue::assertPushed(SendShopNotice::class, fn (SendShopNotice $job): bool => $job->kind === 'low_stock' && $job->subjectId === $mugVariant);
            Queue::assertNotPushed(SendShopNotice::class, fn (SendShopNotice $job): bool => $job->subjectId === $figureVariant);

            // ホームの「在庫切れ」は予約の商品を数えない
            $this->assertSame(1, app(ShopHome::class)->todo()['out_of_stock']);
        });

        // ホームの「発送を待つ注文」は、発売日の前の予約の注文を数えない（発売日から数える）
        $order = $this->inside('pra', fn (): string => $this->place($this->cart($figure, 1))->id);
        $this->pay($order);
        $this->inside('pra', fn () => $this->assertSame(0, app(ShopHome::class)->todo()['to_ship']));
        $this->travelTo(new CarbonImmutable('2026-11-20 00:00:30', 'Asia/Tokyo'));
        $this->inside('pra', fn () => $this->assertSame(1, app(ShopHome::class)->todo()['to_ship']));
    }

    public function test_the_feed_and_the_structured_data_mark_preorders_with_the_release_date(): void
    {
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $this->preorder($figure, self::RELEASE);

        $feeds = $this->inside('pra', function () use ($figure): array {
            ListingImage::create(['listing_id' => $figure, 'position' => 0, 'width' => 800, 'height' => 800, 'checksum' => str_repeat('a', 64)]);
            $result = app(FeedBuilder::class)->build();

            return [app(FeedWriter::class)->write(FeedFormat::Google, $result, '予約の店', 'http://pra.localhost'), app(FeedWriter::class)->write(FeedFormat::Meta, $result, '予約の店', 'http://pra.localhost')];
        });

        foreach ($feeds as $xml) {
            $this->assertStringContainsString('<g:availability>preorder</g:availability>', $xml);
            $this->assertStringContainsString('<g:availability_date>2026-11-20T00:00+09:00</g:availability_date>', $xml);
        }

        $html = $this->page("/products/{$figure}");
        $this->assertStringContainsString('"availability":"https://schema.org/PreOrder"', $html);
        $this->assertStringContainsString('"availabilityStarts":"2026-11-20T00:00:00+09:00"', $html);
    }

    public function test_the_shipping_csv_leaves_out_preorders_until_the_release_date(): void
    {
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $this->preorder($figure, self::RELEASE);
        $order = $this->inside('pra', fn (): string => $this->place($this->cart($figure, 1))->id);
        $this->pay($order);

        $this->inside('pra', function () use ($order): void {
            // 書き出しの対象にしない。取り込みの下見は「発売日の前」と理由を出す
            $this->assertSame([], Parcels::pick('all', [])[0]);
            $import = app(Imports::class)->create(strtoupper($order).",123456789012\n", 'yamato', 'operator:test');
            $row = ShippingImportRow::query()->where('import_id', $import)->sole();
            $this->assertSame(['skipped', RowReason::BeforeRelease->value], [$row->status, $row->reason]);
        });

        $this->travelTo(new CarbonImmutable('2026-11-20 08:00:00', 'Asia/Tokyo'));
        $this->inside('pra', fn () => $this->assertCount(1, Parcels::pick('all', [])[0]));
    }

    public function test_the_order_view_and_the_paid_mail_show_the_plan_and_a_date_moved_before_payment(): void
    {
        Mail::fake();
        $figure = $this->listing('限定のフィギュア', 8800, 0);
        $this->preorder($figure, self::RELEASE);
        $order = $this->inside('pra', fn (): string => $this->place($this->cart($figure, 1))->id);

        // お支払いの前に発売日が延びた（まだ払っていないので知らせのメールは無い）
        $this->inside('pra', fn () => app(PreorderListings::class)->save(Listing::query()->findOrFail($figure), ['enabled' => true, 'release_date' => '2026-11-25', 'ends_on' => null, 'capacity' => null], 'test'));
        $this->pay($order);

        // お支払いの確定のメールに、今の発送の予定と注文の時の日
        Mail::assertSent(OrderMail::class, function (OrderMail $mail) use ($order): bool {
            if ($mail->kind !== 'order_paid' || $mail->order->orderId !== $order) {
                return false;
            }

            $mail->assertSeeInText('発送予定: 11月25日ごろ発送（予約商品）');
            $mail->assertSeeInText('ご注文時の発売予定日は 11月20日 でした');

            return true;
        });

        // ご注文の状況・マイページの部品
        $view = $this->inside('pra', fn () => app(OrderReader::class)->read(Transaction::query()->findOrFail($order)));
        $this->assertSame(['11月25日ごろ発送（予約商品）', '11月20日'], [$view->preorder, $view->preorderOrderedOn]);
        $html = (string) $this->blade('<x-storefront::delivery-summary :label="null" :preorder="$p" :ordered-on="$o" />', ['p' => $view->preorder, 'o' => $view->preorderOrderedOn]);
        $this->assertStringContainsString('11月25日ごろ発送（予約商品）（発売日が変更になりました。ご注文時は 11月20日）', $html);
    }

    public function test_release_dates_and_limits_are_checked(): void
    {
        $figure = $this->listing('限定のフィギュア', 8800, 0);

        $this->inside('pra', function () use ($figure): void {
            $listing = Listing::query()->findOrFail($figure);
            $cases = [
                ['release_date' => '2026-11-10', 'field' => 'release_date'],
                ['release_date' => '2026-02-30', 'field' => 'release_date'],
                ['release_date' => '2029-01-01', 'field' => 'release_date'],
                ['release_date' => self::RELEASE, 'ends_on' => self::RELEASE, 'field' => 'ends_on'],
                ['release_date' => self::RELEASE, 'capacity' => '0', 'field' => 'capacity'],
                ['release_date' => self::RELEASE, 'capacity' => '100001', 'field' => 'capacity'],
            ];

            foreach ($cases as $case) {
                try {
                    app(PreorderListings::class)->save($listing, ['enabled' => true, 'release_date' => $case['release_date'], 'ends_on' => $case['ends_on'] ?? null, 'capacity' => $case['capacity'] ?? null], 'test');
                    $this->fail('通ってしまった: '.json_encode($case));
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey($case['field'], $e->errors(), (string) json_encode($case));
                }
            }

            $this->assertSame([], app(Preorders::class)->terms([$figure]));
        });
    }

    /**
     * 今受けられる予約の数（予約でなければ失敗）
     */
    private function acceptable(string $listing): ?int
    {
        $terms = app(Preorders::class)->terms([$listing]);
        $this->assertArrayHasKey($listing, $terms);

        return $terms[$listing]->acceptable();
    }

    private function preorder(string $listing, string $release, ?string $endsOn = null, ?string $capacity = null): void
    {
        $this->inside('pra', fn () => app(PreorderListings::class)->save(Listing::query()->findOrFail($listing), ['enabled' => true, 'release_date' => $release, 'ends_on' => $endsOn, 'capacity' => $capacity], 'test'));
    }

    private function listing(string $title, int $amount, int $stock): string
    {
        return $this->inside('pra', function () use ($title, $amount, $stock): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => 'active']);

            if ($stock > 0) {
                app(StockService::class)->receive($listing, Location::query()->orderBy('created_at')->firstOrFail(), $stock);
            }

            return $listing->id;
        });
    }

    private function cart(string $listing, int $quantity): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $listing)->value('id'), $quantity);

        return $cart;
    }

    private function place(Cart $cart, ?string $fingerprint = null, string $email = 'hanako@example.com'): Transaction
    {
        $input = CheckoutInput::fromArray(['buyer' => [...self::buyer(), 'email' => $email], 'recipient' => null]);
        $this->assertNotNull($input);

        return app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            $fingerprint ?? CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart)));
    }

    private function pay(string $order): void
    {
        $this->inside('pra', function () use ($order): void {
            $transaction = Transaction::query()->findOrFail($order);
            app(PaymentService::class)->startCheckout($transaction, 'x', 'y');
            $ref = (string) PaymentAttempt::query()->where('transaction_id', $order)->value('provider_ref');
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$order, 'checkout.session.completed', $ref, $order, (int) $transaction->total_amount, 'jpy', 'pi_'.$order));
        });
    }

    /**
     * @return array<string, string>
     */
    private static function buyer(): array
    {
        return ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''];
    }

    private function page(string $path): string
    {
        return (string) $this->send('GET', $path)->assertOk()->getContent();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->cart !== null) {
            $this->withCookie(CartCookie::NAME, $this->cart);
        }

        $url = "http://pra.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $this->cart = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->cart;

        return $response;
    }
}
