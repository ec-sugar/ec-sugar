<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Admin\Services\SalesPeriod;
use App\Admin\Services\SalesReport;
use App\Admin\Services\ShopHome;
use App\Core\Discount\Models\CouponRedemption;
use App\Core\Discount\Services\CouponService;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\GiftCard\Models\GiftCard;
use App\Core\GiftCard\Models\GiftCardEntry;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\GiftCard\Services\GiftCardException;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\ReturnRequest;
use App\Core\Order\Services\OrderReturns;
use App\Core\Order\Services\OrderShippingCharges;
use App\Core\Order\Services\ReturnPlanner;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Order\Services\ShippingDifferenceRefunds;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Enums\ReturnReason;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\RefundResult;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Points\Services\PointService;
use App\Core\Shop\Services\ShippingMethods;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Shop\Shipping\Regions;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\Services\TransactionException;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\GiftCardCodes;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Members\Member;
use App\Storefront\Orders\OrderReader;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * ギフトカードを注文に使う（ADR 0076。お金の核）。値引き → ポイント → ギフトカードの順に充て、ギフトカードは支払いの一部なので合計も税も変えない。
 * 使う分は注文を作る錠の中でカードの残高を確かめて引き、払いが確かになったら台帳に「ギフトカードでの受け取り」を書く。
 * 払われずに消えた・取消・全額の返金でカードへ戻す（期限は延ばさない）。帳簿は追記だけで、残高は DB でも負にならない
 */
final class GiftCardsTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private string $knit;

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'gct', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'gct.localhost']);
        $this->knit = $this->inside(function (): string {
            $knit = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($knit, Location::query()->orderBy('id')->firstOrFail(), 20);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 100000]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);

            return $knit->id;
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        CarbonImmutable::setTestNow();
        $this->app['auth']->forgetGuards();
        Tenant::query()->whereIn('id', ['gct', 'gcu'])->get()->each->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_the_card_goes_after_the_discount_and_points_pays_part_and_is_recorded_as_a_payment_without_changing_the_tax(): void
    {
        $this->inside(function (): void {
            app(CouponService::class)->create(['code' => 'OFF1000', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 1000], 'test');
            $member = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'test');
            CustomerAccount::query()->whereKey($member->id)->update(['member_since' => now()]);
            $member = CustomerAccount::query()->findOrFail($member->id);
            Auth::guard('customer')->setUser(new Member($member, 'gct'));
            app(PointService::class)->adjust($member, 500, 'テスト', 'test');
            [$card, $code] = $this->issue(2000);
            $cart = $this->cart();
            app(CartService::class)->applyCoupon($cart, 'OFF1000');

            // 4,400 − 値引き 1,000 + 送料 550 − ポイント 500 = 3,450（合計）。ギフトカードはその後で 2,000、お支払いは 1,450
            $without = app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com', 500);
            $view = app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com', 500, $card->id);
            $this->assertSame([1000, 500, 3450, 2000, 1450], [$view->discount, $view->points->used, $view->total(), $view->giftCard->applied, $view->due()]);
            // 支払いの一部なので、合計と税率ごとの額は使わない時と同じ
            $this->assertSame($without->total(), $view->total());
            $this->assertSame($without->taxAmounts, $view->taxAmounts);
            $this->assertSame('−¥2,000', $view->giftCard->appliedLabel());
            $this->assertSame(0, $view->giftCard->remaining());
            $summary = new CheckoutSummary($view, $this->input(500, $card->id), null, null);
            $this->assertStringStartsWith('ギフトカード（¥2,000）と、残りの ¥1,450 を クレジットカード', $summary->paymentMethod());

            $sale = $this->place($cart, points: 500, giftCard: $card->id);
            // 注文の合計は変わらず、ギフトカードの行は無い（値引きでない）。使った額は注文に写す
            $this->assertSame(3450, $sale->total_amount);
            $this->assertSame(0, $sale->lines()->where('kind', 'gift_card')->count());
            $this->assertSame(2000, OrderGiftCard::appliedTo($sale->id));
            $this->assertSame(1450, OrderGiftCard::dueOf($sale));
            $this->assertSame(0, app(GiftCardService::class)->balance($card->id));
            $this->assertEquals(['amount' => -2000, 'balance' => 0, 'transaction' => $sale->id], DomainEvent::query()->where('type', 'gift_card.spent')->sole()->payload);

            // 支払いの画面は残りの 1,450 で（明細と合わないので「ご注文」の 1 行）
            app(PaymentService::class)->startCheckout($sale, 'x', 'y');
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale->id)->sole();
            $this->assertSame(1450, $attempt->amount);
            // 知らせの額が合計（3,450）なら合わないので支払いにしない。払ってもらう額（1,450）で支払いになる
            $this->assertSame('mismatch', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_wrong', 'checkout.session.completed', $attempt->provider_ref, $sale->id, 3450, 'jpy', 'pi_gc'));
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_ok', 'checkout.session.completed', $attempt->provider_ref, $sale->id, 1450, 'jpy', 'pi_gc'));

            // 台帳: 決済代行の受け取り 1,450 と、ギフトカードでの受け取り 2,000（direct・参照 gift_card:）で、合計を受け取った
            $sale = Transaction::query()->findOrFail($sale->id);
            $this->assertSame(Phase::Payment, $sale->phase);
            $this->assertSame(3450, app(LedgerService::class)->netPaid($sale));
            $this->assertSame(['pi_gc' => 1450, 'gift_card:'.$card->id => 2000], $this->payments($sale));
            $this->assertSame(2000, app(GiftCardService::class)->heldInLedger($sale->id));

            // ご注文の状況とメール: 使った額とカードの残り（コードは出さない）
            $order = app(OrderReader::class)->read($sale);
            $this->assertSame('ギフトカード（末尾 '.$card->last4.'）で ¥2,000 をお支払い（ギフトカードの残高 ¥0）', $order->giftCard?->note());

            // 払った後の取消: 決済代行の 1,450 を返金し、ギフトカードの 2,000 はカードへ戻して台帳も返金にする
            $result = app(SaleOrderService::class)->cancel($sale, 'operator:test');
            $this->assertSame(1450, $result['refunded']);
            $this->assertSame(2000, app(GiftCardService::class)->balance($card->id));
            $this->assertSame(0, app(LedgerService::class)->netPaid(Transaction::query()->findOrFail($sale->id)));
            $this->assertSame(0, app(GiftCardService::class)->heldInLedger($sale->id));
            $this->assertSame(1450, Refund::query()->sole()->amount);
            $this->assertStringContainsString('ギフトカードの残高に戻しました', (string) app(OrderReader::class)->read(Transaction::query()->findOrFail($sale->id))->giftCard?->note());
            $this->assertCodeNowhere($code);
        });
    }

    public function test_exactly_the_balance_one_more_and_more_than_the_total(): void
    {
        $this->inside(function (): void {
            $carts = app(CartService::class);
            $cart = $this->cart();
            $to = $this->input()->destination();

            // 残高ちょうど（合計 4,950）: 全額をギフトカードで、お支払いは 0 円
            [$exact] = $this->issue(4950);
            $view = $carts->view($cart, $to, 'hanako@example.com', 0, $exact->id);
            $this->assertSame([4950, 0, 0], [$view->giftCard->applied, $view->due(), $view->giftCard->remaining()]);
            $this->assertSame('ギフトカード（¥4,950。ほかのお支払いは不要です）', (new CheckoutSummary($view, $this->input(0, $exact->id), null, null))->paymentMethod());

            // 1 多い: 合計まで使い、1 円残る
            [$more] = $this->issue(4951);
            $this->assertSame([4950, 1], [$carts->view($cart, $to, 'hanako@example.com', 0, $more->id)->giftCard->applied, $carts->view($cart, $to, 'hanako@example.com', 0, $more->id)->giftCard->remaining()]);

            // 合計をずっと超える残高: 合計までだけ
            [$large] = $this->issue(50000);
            $this->assertSame(4950, $carts->view($cart, $to, 'hanako@example.com', 0, $large->id)->giftCard->applied);

            // 1 少ない: お支払いが 1 円になるので、50 円が残るように抑える
            [$less] = $this->issue(4949);
            $lowered = $carts->view($cart, $to, 'hanako@example.com', 0, $less->id);
            $this->assertSame([4900, 50, true], [$lowered->giftCard->applied, $lowered->due(), $lowered->giftCard->lowered]);

            // 49 円が残る残高: 抑えて 50 円
            [$near] = $this->issue(4901);
            $this->assertSame([4900, 50], [$carts->view($cart, $to, 'hanako@example.com', 0, $near->id)->giftCard->applied, $carts->view($cart, $to, 'hanako@example.com', 0, $near->id)->due()]);
        });
    }

    public function test_paying_the_whole_order_with_a_card_skips_the_payment_and_records_the_gift_card_receipt(): void
    {
        $this->inside(function (): void {
            [$card] = $this->issue(5000);
            $sale = $this->place($this->cart(), giftCard: $card->id);

            $this->assertSame(4950, $sale->total_amount);
            $this->assertSame(Phase::Payment, $sale->phase);
            $this->assertSame(['amount' => 0, 'gift_card' => 4950], DomainEvent::query()->where('type', 'payment.not_required')->sole()->payload);
            $this->assertSame(['gift_card:'.$card->id => 4950], $this->payments($sale));
            $this->assertSame(50, app(GiftCardService::class)->balance($card->id));
            // 支払いの画面は作らない（払うものが無い）
            $this->assertSame(0, OrderGiftCard::dueOf($sale));
            // 売上の画面: お金はカードを売った時に数えたので、ギフトカードで払った分は入金に数えない（注文の数には数える）
            $totals = app(SalesReport::class)->summary(SalesPeriod::of('today'))['totals'];
            $this->assertSame([0, 1], [$totals['gross'], $totals['orders']]);

            try {
                app(PaymentService::class)->startCheckout($sale, 'x', 'y');
                $this->fail('全額をギフトカードで払った注文に支払いの画面を作った');
            } catch (PaymentUnavailableException) {
                $this->assertSame(0, PaymentAttempt::query()->count());
            }

            // 取り消すと、台帳の受け取りを返金にしてカードへ戻す
            app(SaleOrderService::class)->cancel($sale, 'operator:test');
            $this->assertSame(5000, app(GiftCardService::class)->balance($card->id));
            $this->assertSame(0, app(LedgerService::class)->netPaid(Transaction::query()->findOrFail($sale->id)));
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($sale->id)->status);
        });
    }

    public function test_the_same_card_spent_by_two_orders_only_confirms_one_and_the_database_refuses_a_negative_balance(): void
    {
        $this->inside(function (): void {
            [$card] = $this->issue(3000);
            $carts = app(CartService::class);
            $first = $this->cart();
            $second = $this->cart();
            // 2 つの画面で同じ残高を見て確かめた
            $shownFirst = CheckoutSummary::fingerprintOf($carts->view($first, $this->input()->destination(), 'hanako@example.com', 0, $card->id));
            $shownSecond = CheckoutSummary::fingerprintOf($carts->view($second, $this->input()->destination(), 'hanako@example.com', 0, $card->id));

            $this->place($first, giftCard: $card->id, fingerprint: $shownFirst);

            // 後の方は錠の中でカードの残高から決め直すので、使える額が変わって確定しない（在庫も押さえない）
            try {
                $this->place($second, giftCard: $card->id, fingerprint: $shownSecond);
                $this->fail('同じ残高で 2 つ目の注文が確定した');
            } catch (OrderException $e) {
                $this->assertTrue($e->changed);
            }

            $this->assertSame(1, Transaction::query()->count());
            $this->assertSame(0, app(GiftCardService::class)->balance($card->id));
            $this->assertSame(1, GiftCardEntry::query()->where('kind', 'spend')->count());

            // サービスの錠をすり抜けても、DB のトリガーが負の残高を断る
            $this->expectDatabaseRefusal(static fn () => GiftCardEntry::create(['gift_card_id' => $card->id, 'kind' => 'expire', 'amount' => -1, 'occurred_at' => now()]), 'gift_card_entries_negative_balance');
            // 錠の中で使い切ったカードに使おうとすると、サービスが断る
            $sale = Transaction::query()->sole();

            try {
                DB::transaction(static fn () => app(GiftCardService::class)->spend($sale->id, $card->id, 1, 'test'));
                $this->fail('残高 0 のカードから引けた');
            } catch (GiftCardException $e) {
                $this->assertSame(GiftCardService::EMPTY, $e->getMessage());
            }
        });
    }

    public function test_the_ledger_of_a_card_cannot_be_updated_or_deleted(): void
    {
        $this->inside(function (): void {
            [$card] = $this->issue(1000);
            $entry = GiftCardEntry::query()->sole();

            $this->expectDatabaseRefusal(static fn () => GiftCardEntry::query()->whereKey($entry->id)->update(['amount' => 999999]));
            $this->expectDatabaseRefusal(static fn () => GiftCardEntry::query()->whereKey($entry->id)->delete());
            $this->expectDatabaseRefusal(static fn () => DB::statement('TRUNCATE gift_card_entries CASCADE'));
            // 発行はカードの最初の行だけ（2 度目の発行で残高を増やせない）
            $this->expectDatabaseRefusal(static fn () => GiftCardEntry::create(['gift_card_id' => $card->id, 'kind' => 'issue', 'amount' => 1000, 'occurred_at' => now()]), 'gift_card_entries_issue_first');
            $this->assertSame(1000, app(GiftCardService::class)->balance($card->id));
        });
    }

    public function test_the_expiry_boundary_and_a_stopped_card(): void
    {
        $this->inside(function (): void {
            $start = CarbonImmutable::parse('2026-11-05 10:00:00');
            CarbonImmutable::setTestNow($start);
            [$card, $code] = $this->issue(3000, days: 180);
            $codes = app(GiftCardCodes::class);
            $this->assertTrue($card->expires_at->equalTo($start->addDays(180)));

            // 期限の 1 秒前は使える、期限の時刻ちょうどで使えない
            CarbonImmutable::setTestNow($start->addDays(180)->subSecond());
            $this->assertSame($card->id, $codes->lookup('192.0.2.1', $code)->id);
            CarbonImmutable::setTestNow($start->addDays(180));
            $this->assertLookupFails($codes, '192.0.2.1', $code, GiftCardService::EXPIRED);
            $this->assertSame(GiftCardService::EXPIRED, app(CartService::class)->view($this->cart(), $this->input()->destination(), 'hanako@example.com', 0, $card->id)->giftCard->problem);

            // 期限切れの見回り: 残高を期限切れにして、未使用の残高の合計から外す。2 回目は何もしない
            $this->assertSame(['amount' => 0, 'cards' => 0], app(GiftCardService::class)->outstanding());
            $this->assertSame(1, app(GiftCardService::class)->expireDue(CarbonImmutable::now(), 'system:gift-cards'));
            $this->assertSame(0, app(GiftCardService::class)->expireDue(CarbonImmutable::now(), 'system:gift-cards'));
            $this->assertSame(0, app(GiftCardService::class)->balance($card->id));

            // 止めたカードは使えない（再開すれば使える）
            CarbonImmutable::setTestNow($start);
            [$other, $otherCode] = $this->issue(3000);
            app(GiftCardService::class)->stop($other, 'operator:test');
            $this->assertLookupFails($codes, '192.0.2.1', $otherCode, GiftCardService::STOPPED);
            app(GiftCardService::class)->resume($other, 'operator:test');
            $this->assertSame($other->id, $codes->lookup('192.0.2.1', $otherCode)->id);
            $this->assertSame(['amount' => 3000, 'cards' => 1], app(GiftCardService::class)->outstanding());
        });
    }

    public function test_a_card_stopped_after_the_confirmation_is_refused_when_placing(): void
    {
        $this->inside(function (): void {
            [$card] = $this->issue(3000);
            $cart = $this->cart();
            $shown = CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com', 0, $card->id));
            app(GiftCardService::class)->stop($card, 'operator:test');

            try {
                $this->place($cart, giftCard: $card->id, fingerprint: $shown);
                $this->fail('止めたカードで確定した');
            } catch (OrderException $e) {
                $this->assertStringStartsWith(GiftCardService::STOPPED, $e->getMessage());
            }

            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(3000, app(GiftCardService::class)->balance($card->id));
        });
    }

    public function test_codes_are_looked_up_ten_times_per_source_and_another_shops_code_is_not_found(): void
    {
        [$card, $code] = $this->inside(fn (): array => $this->issue(3000));
        // ほかの店で発行したコード
        Tenant::create(['id' => 'gcu', 'name' => '別の店']);
        [, $other] = $this->inside(fn (): array => $this->issue(3000), 'gcu');

        $this->inside(function () use ($card, $code, $other): void {
            $codes = app(GiftCardCodes::class);
            // ほかの店のコードは、この店の DB に無いので見つからない
            $this->assertLookupFails($codes, '198.51.100.7', $other, GiftCardService::NOT_FOUND);
            // 形の違う文字・小文字とハイフン抜きの正しいコード
            $this->assertLookupFails($codes, '198.51.100.7', 'ABCD', GiftCardService::NOT_FOUND);
            $this->assertSame($card->id, $codes->lookup('198.51.100.7', strtolower(str_replace('-', ' ', $code)))->id);

            for ($i = 4; $i <= 10; $i++) {
                $this->assertLookupFails($codes, '198.51.100.7', 'ZZZZ-ZZZZ-ZZZZ-ZZZ'.$i, GiftCardService::NOT_FOUND);
            }

            // 11 回目は、正しいコードでも断る（接続元ごとに 10 分に 10 回）。ほかの接続元は使える
            $this->assertLookupFails($codes, '198.51.100.7', $code, GiftCardCodes::TOO_MANY);
            $this->assertSame($card->id, $codes->lookup('203.0.113.9', $code)->id);
        });
    }

    public function test_the_code_entered_at_checkout_is_kept_only_as_the_card_and_never_in_the_session(): void
    {
        [$card, $code] = $this->inside(fn (): array => $this->issue(3000));
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => 1]);

        // 誤りで戻る時（住所が無い）も、入れたコードを入れ直した値としてセッションに残さない
        $back = $this->send('POST', '/checkout', ['buyer' => ['email' => 'hanako@example.com'], 'gift_card' => ['code' => $code]])->assertRedirect();
        $this->assertNull(session()->getOldInput('gift_card.code'));
        unset($back);

        // 見つからないコードは欄の下に
        $this->send('POST', '/checkout', $this->form(['gift_card' => ['code' => 'ZZZZ-ZZZZ-ZZZZ-ZZZZ']]))->assertRedirect()->assertSessionHasErrors('gift_card.code');

        // 正しいコード: セッションにはカードの ID だけ
        $this->send('POST', '/checkout', $this->form(['gift_card' => ['code' => $code]]))->assertRedirect('http://gct.localhost/checkout/confirm');
        $this->assertSame($card->id, session('checkout.gift_card_id'));
        $this->assertStringNotContainsString(str_replace('-', '', $code), (string) json_encode(session()->all()));
        $this->assertStringNotContainsString($code, (string) json_encode(session()->all()));

        $confirm = $this->send('GET', '/checkout/confirm')->assertOk();
        $confirm->assertSee('ギフトカード（末尾 '.$card->last4.'）')->assertSee('−¥3,000')->assertSee('¥1,950');
        $this->assertStringNotContainsString($code, (string) $confirm->getContent());

        // 入力の画面に戻ると、使っているカードは末尾だけ。もう一度送る（コードは入れない）と同じカードのまま、「使わない」で外れる
        $form = $this->send('GET', '/checkout')->assertOk()->assertSee('末尾 <strong>'.$card->last4.'</strong>', false);
        $this->assertStringNotContainsString($code, (string) $form->getContent());
        $this->send('POST', '/checkout', $this->form())->assertRedirect();
        $this->assertSame($card->id, session('checkout.gift_card_id'));
        $this->send('POST', '/checkout', $this->form(['gift_card' => ['remove' => '1']]))->assertRedirect();
        $this->assertNull(session('checkout.gift_card_id'));
    }

    public function test_an_unpaid_order_that_lapses_returns_the_card_and_an_expired_card_keeps_nothing(): void
    {
        $this->inside(function (): void {
            $start = CarbonImmutable::parse('2026-11-05 10:00:00');
            CarbonImmutable::setTestNow($start);
            [$card] = $this->issue(3000, days: 1);
            [$later] = $this->issue(3000);
            $first = $this->place($this->cart(), giftCard: $card->id);
            $second = $this->place($this->cart(), giftCard: $later->id);
            $this->assertSame(0, app(GiftCardService::class)->balance($card->id));

            // 払われずに消えた注文（期限内のカード）: 使った分が戻る。払われていないので台帳には何も書かない
            $this->assertTrue(app(SaleOrderService::class)->cancelIfUnpaid($second, 'system:expiry'));
            $this->assertSame(3000, app(GiftCardService::class)->balance($later->id));
            $this->assertSame([], $this->payments($second));
            // 2 度目は何もしない
            $this->assertSame(0, app(GiftCardService::class)->release($second->id, 'test'));

            // 期限を過ぎてから消えた注文: 戻した分はすぐ期限切れ（期限は延ばさない）
            CarbonImmutable::setTestNow($start->addDays(2));
            $this->assertTrue(app(SaleOrderService::class)->cancelIfUnpaid($first, 'system:expiry'));
            $this->assertSame(['spend', 'release', 'expire'], GiftCardEntry::query()->where('gift_card_id', $card->id)->where('kind', '<>', 'issue')->orderBy('card_seq')->pluck('kind')->all());
            $this->assertSame(0, app(GiftCardService::class)->balance($card->id));
            $this->assertTrue(GiftCard::query()->findOrFail($card->id)->expires_at->equalTo($start->addDay()));
        });
    }

    public function test_a_full_refund_outside_does_not_return_the_card_until_the_shop_chooses_and_stripe_refunds_stop_at_what_stripe_holds(): void
    {
        $this->inside(function (): void {
            [$card] = $this->issue(2000);
            $sale = $this->place($this->cart(), giftCard: $card->id);
            $this->pay($sale, 'pi_gift', 2950);

            // 決済代行の経路の返金は、決済代行が預かった 2,950 まで（ギフトカードでの受け取り 2,000 まで決済代行から返さない）
            try {
                app(LedgerService::class)->recordRefund(Transaction::query()->findOrFail($sale->id), 2951, PaymentRoute::Platform, 're_over', 'test');
                $this->fail('決済代行が預かった額を超える返金を記録した');
            } catch (\InvalidArgumentException) {
                $this->assertSame(4950, app(LedgerService::class)->netPaid(Transaction::query()->findOrFail($sale->id)));
            }

            foreach (Fulfillment::query()->where('transaction_id', $sale->id)->get() as $fulfillment) {
                app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale->id), $fulfillment->id, 'yamato', '1234', 'operator:test');
            }

            // 一部の返金（決済代行で 1,000）では戻さない
            app(PaymentNotices::class)->refundUpdated('stripe', 'evt_part', 'refund.created', new RefundResult('re_part', 'succeeded', null, 1000, 'pi_gift'));
            app(PaymentRefunds::class)->resolve(Refund::query()->sole(), 'recorded', 1000, 'test');
            $this->assertSame(0, app(GiftCardService::class)->balance($card->id));

            // 残りの現金も返して決済代行で受け取った額が 0 になっても、ギフトカードの額は自動では戻さない（盗まれたカードの分まで戻さないよう、店の人が選ぶ）
            app(PaymentNotices::class)->refundUpdated('stripe', 'evt_rest', 'refund.created', new RefundResult('re_rest', 'succeeded', null, 1950, 'pi_gift'));
            app(PaymentRefunds::class)->resolve(Refund::query()->where('provider_ref', 're_rest')->sole(), 'recorded', 1950, 'test');
            $this->assertSame([0, 2000], [app(GiftCardService::class)->balance($card->id), app(GiftCardService::class)->releasable($sale->id)]);
            $this->assertSame(2000, app(LedgerService::class)->netPaid(Transaction::query()->findOrFail($sale->id)));

            // 店の人が選んだ額だけ戻す（台帳のギフトカードでの受け取りもその額だけ返金に）。同じ理由で 2 度目は戻さない
            $this->assertSame(1500, app(GiftCardService::class)->release($sale->id, 'operator:test', 'refund', 1500));
            $this->assertSame(0, app(GiftCardService::class)->release($sale->id, 'operator:test', 'refund', 500));
            $this->assertSame([1500, 500, 500], [app(GiftCardService::class)->balance($card->id), app(GiftCardService::class)->releasable($sale->id), app(LedgerService::class)->netPaid(Transaction::query()->findOrFail($sale->id))]);
            // 使った額を超えて戻すことは DB も断る
            $this->expectDatabaseRefusal(static fn () => GiftCardEntry::create(['gift_card_id' => $card->id, 'kind' => 'release', 'cause' => 'shipping', 'amount' => 501, 'transaction_id' => $sale->id, 'occurred_at' => now()]), 'gift_card_entries_release_over_spent');
        });
    }

    public function test_after_an_amount_only_refund_an_item_paid_partly_by_card_goes_back_to_stock_and_stripe_returns_only_what_stripe_holds(): void
    {
        $this->inside(function (): void {
            // ニット 4,400 ＋ 送料 550 ＝ 4,950 をギフトカード 2,000 と Stripe 2,950 で。発送の後、お詫びに金額だけ 300 を Stripe で返した
            [$card] = $this->issue(2000);
            $sale = $this->place($this->cart(), giftCard: $card->id);
            $this->pay($sale, 'pi_gift', 2950);
            foreach (Fulfillment::query()->where('transaction_id', $sale->id)->get() as $fulfillment) {
                app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale->id), $fulfillment->id, 'yamato', '1234', 'operator:test');
            }
            $returns = app(OrderReturns::class);
            $sale = Transaction::query()->findOrFail($sale->id);
            $returns->record($sale, new ReturnRequest([], false, 300, ReturnReason::Goodwill, 'provider'), null, 'operator:test');
            $position = (int) TransactionLine::query()->where('transaction_id', $sale->id)->where('line_type', 'item')->value('position');
            $item = static fn (string $channel, ?int $goods = null): ReturnRequest => new ReturnRequest([$position => ['quantity' => 1, 'restock' => true, 'addons' => false]], false, 0, ReturnReason::Returned, $channel, $goods);
            $stock = fn (): int => (int) InventoryStock::query()->where('listing_id', $this->knit)->sum('quantity');
            $before = $stock();

            // Stripe で返す既定は、Stripe で受けてまだ返していない 2,650 まで（ギフトカードの分は Stripe では返せない）。外で返すなら品の額 4,400 まで
            $this->assertSame([2650, 4400, 2650], [$returns->preview($sale, $item('provider'))->total, $returns->preview($sale, $item('provider'))->goodsFull, $returns->preview($sale, $item('provider'))->goodsLimit]);
            $this->assertSame(4400, $returns->preview($sale, $item('outside'))->total);

            try {
                $returns->preview($sale, $item('provider', 2651));
                $this->fail('Stripe で受けた額を越えて返す額を入れられた');
            } catch (TransactionException $e) {
                $this->assertSame(sprintf(ReturnPlanner::GOODS_OVER, '¥2,650'), $e->getMessage());
            }

            $refund = $returns->record($sale, $item('provider'), 2650, 'operator:test');
            $this->assertSame([2650, 'succeeded', 'provider'], [$refund?->amount, $refund?->status, $refund?->channel]);
            $this->assertSame($before + 1, $stock());
            // 残りはギフトカードで受けた 2,000（カードへは店の人が選んで戻す。自動では戻さない）
            $this->assertSame([2000, 0], [app(LedgerService::class)->netPaid(Transaction::query()->findOrFail($sale->id)), app(GiftCardService::class)->balance($card->id)]);
        });
    }

    public function test_a_card_sold_with_a_test_payment_cannot_be_used_once_the_shop_has_a_live_key(): void
    {
        $this->inside(function (): void {
            $issued = app(GiftCardService::class)->issue(3000, 180, null, 'test', test: true);
            $card = $issued['card'];
            $code = GiftCardService::format((string) $issued['code']);
            $cart = $this->cart();

            // テストの鍵の店では使える（店の人の試し）
            $this->assertSame($card->id, app(GiftCardCodes::class)->lookup('192.0.2.1', $code)->id);
            $shown = CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com', 0, $card->id));

            // 本番の鍵に替えると、入れる時・確かめの画面・確定の錠の中のどこでも断る
            config(['payment.live_keys_allowed' => true]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'live', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])], 'test');
            $this->assertLookupFails(app(GiftCardCodes::class), '192.0.2.2', $code, GiftCardService::TEST_ONLY);
            $this->assertSame(GiftCardService::TEST_ONLY, app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com', 0, $card->id)->giftCard->problem);

            try {
                $this->place($cart, giftCard: $card->id, fingerprint: $shown);
                $this->fail('本番の鍵の店でテストのカードで確定した');
            } catch (OrderException $e) {
                $this->assertStringStartsWith(GiftCardService::TEST_ONLY, $e->getMessage());
            }

            $this->assertSame(3000, app(GiftCardService::class)->balance($card->id));
            // 本物のカードは本番の鍵の店でも使える
            [$real, $realCode] = $this->issue(3000);
            $this->assertSame($real->id, app(GiftCardCodes::class)->lookup('192.0.2.3', $realCode)->id);

            // 確定の錠の中の守り（カードの行の錠の中でも、本番の鍵の店ではテストのカードから引かない）
            $cash = $this->place($this->cart());

            try {
                DB::transaction(static fn () => app(GiftCardService::class)->spend($cash->id, $card->id, 100, 'test', liveShop: true));
                $this->fail('本番の鍵の店でテストのカードから引けた');
            } catch (GiftCardException $e) {
                $this->assertSame(GiftCardService::TEST_ONLY, $e->getMessage());
            }
        });
    }

    public function test_a_gift_shipping_difference_goes_back_to_points_then_the_card_once_and_the_rest_is_a_todo(): void
    {
        $this->inside(function (): void {
            $table = app(ShippingMethods::class);
            $table->replace(['methods' => [['id' => $table->all()[0]['id'], 'name' => '通常便', 'free_over' => null, 'rates' => [...Regions::flat(800), '北海道' => 1500, '沖縄県' => 2000]]]], 'test');
            app(PluginStates::class)->set('gift', true, 'test');

            // ニット 4,400 + いちばん高い送料 2,000 = 6,400 を全部ギフトカードで（お支払い 0 円）
            [$card] = $this->issue(10000);
            $first = $this->place($this->cart(), giftCard: $card->id, gift: true);
            $this->assertSame([6400, 0], [(int) $first->total_amount, OrderGiftCard::dueOf($first)]);

            // 北海道の送料 1,500 との差額 500: 現金もポイントも無いので、使ったギフトカードへ戻す（理由 shipping で 1 回だけ）
            $this->assertSame('none', $this->settleGift($first));
            $this->assertSame('none', app(ShippingDifferenceRefunds::class)->refund($first->id, 'test'));
            $back = GiftCardEntry::query()->where('kind', 'release')->sole();
            $this->assertSame([500, 'shipping'], [$back->amount, $back->cause]);
            $this->assertSame(10000 - 6400 + 500, app(GiftCardService::class)->balance($card->id));
            $this->assertSame(['points' => 0, 'gift_card' => 500, 'unreturned' => 0], DomainEvent::query()->where('type', 'order.shipping_rest_returned')->sole()->payload);
            $this->assertSame(0, app(ShopHome::class)->todo()['shipping_unreturned']);

            // 店の人がもうカードへ戻していて、戻せる額が差額より少ない: 戻せる分だけ戻し、残りはホームのやることに出す
            [$other] = $this->issue(10000);
            $second = $this->place($this->cart(), giftCard: $other->id, gift: true);
            app(GiftCardService::class)->release($second->id, 'operator:test', 'refund', 6000);
            $this->settleGift($second);
            $this->assertSame(['points' => 0, 'gift_card' => 400, 'unreturned' => 100], DomainEvent::query()->where('type', 'order.shipping_rest_returned')->where('aggregate_id', $second->id)->sole()->payload);
            $this->assertSame(1, app(ShopHome::class)->todo()['shipping_unreturned']);
        });
    }

    public function test_cancelling_an_order_paid_whole_by_card_is_a_cancel_after_payment_and_keeps_the_coupon_used(): void
    {
        $this->inside(function (): void {
            app(CouponService::class)->create(['code' => 'OFF1000', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 1000], 'test');
            [$card] = $this->issue(10000);
            $cart = $this->cart();
            app(CartService::class)->applyCoupon($cart, 'OFF1000');
            $sale = $this->place($cart, giftCard: $card->id);
            $this->assertSame(Phase::Payment, $sale->phase);

            app(SaleOrderService::class)->cancel($sale, 'operator:test');
            $this->assertSame(10000, app(GiftCardService::class)->balance($card->id));
            $this->assertNotSame('released', CouponRedemption::query()->where('transaction_id', $sale->id)->value('status'));
            $this->assertSame(0, DomainEvent::query()->where('type', 'coupon.released')->count());
        });
    }

    /**
     * 受け取る人が北海道の住所を入れて送料の差額が決まり、差額を返す（受け取りの後の仕事と同じ）
     */
    private function settleGift(Transaction $order): string
    {
        DB::transaction(function () use ($order): void {
            app(EventStore::class)->lock();
            $this->assertSame(500, app(OrderShippingCharges::class)->settle($order->id, '北海道', 'test'));
        });

        return app(ShippingDifferenceRefunds::class)->refund($order->id, 'test');
    }

    /**
     * @return array{0: GiftCard, 1: string}
     */
    private function issue(int $amount, int $days = 180): array
    {
        $issued = app(GiftCardService::class)->issue($amount, $days, null, 'test');
        $this->assertNotNull($issued['code']);

        return [$issued['card'], GiftCardService::format($issued['code'])];
    }

    private function cart(): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->knit)->value('id'), 1);

        return $cart;
    }

    private function place(Cart $cart, int $points = 0, ?string $giftCard = null, ?string $fingerprint = null, bool $gift = false): Transaction
    {
        $input = $this->input($points, $giftCard, $gift);
        $fingerprint ??= CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart, $input->destination(), 'hanako@example.com', $input->pointsRequested(), $giftCard));

        return app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $fingerprint);
    }

    private function input(int $points = 0, ?string $giftCard = null, bool $gift = false): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null,
            'gift' => $gift ? ['sender' => 'はなこ', 'message' => 'おめでとう'] : null,
            'points' => $points > 0 ? ['mode' => 'some', 'amount' => $points] : null, 'gift_card_id' => $giftCard]);
        $this->assertNotNull($input);

        return $input;
    }

    private function pay(Transaction $sale, string $paymentRef, int $due): void
    {
        app(PaymentService::class)->startCheckout($sale, 'x', 'y');
        $ref = PaymentAttempt::query()->where('transaction_id', $sale->id)->sole()->provider_ref;
        $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$sale->id, 'checkout.session.completed', $ref, $sale->id, $due, 'jpy', $paymentRef));
    }

    /**
     * 支払いの仕訳（打ち消していないもの）の参照 => 額
     *
     * @return array<string, int>
     */
    private function payments(Transaction $sale): array
    {
        return LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Payment)->whereNull('reverses_journal_id')->orderBy('occurred_at')->orderBy('id')->get()
            ->mapWithKeys(static fn (LedgerJournal $j): array => [(string) $j->reference => (int) $j->postings()->where('account', 'customer')->value('amount')])->all();
    }

    /**
     * コードがどこにも残っていない（DB のカードはハッシュと末尾だけ、店の記録・ログの表にも無い）
     */
    private function assertCodeNowhere(string $code): void
    {
        $bare = str_replace('-', '', $code);
        // DB の索引は店の鍵の HMAC（素の sha256 ではない。鍵を持たない者は DB からコードを総当たりで確かめられない）
        $card = GiftCard::query()->where('code_hash', GiftCardService::hash($bare))->sole();
        $this->assertNotSame(hash('sha256', $bare), $card->code_hash);
        $this->assertSame(substr($bare, -4), $card->last4);

        foreach (['gift_cards', 'gift_card_entries', 'order_gift_cards', 'domain_events', 'ledger_journals'] as $table) {
            $dump = (string) json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString($bare, $dump, $table);
        }
    }

    private function assertLookupFails(GiftCardCodes $codes, string $ip, string $code, string $message): void
    {
        try {
            $codes->lookup($ip, $code);
            $this->fail('コードが通った');
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->errors()['gift_card.code'][0] ?? null);
        }
    }

    private function expectDatabaseRefusal(Closure $write, string $message = '追記専用'): void
    {
        try {
            DB::transaction(static function () use ($write): void {
                $write();
            });
            $this->fail('DB が書き込みを断らなかった');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function form(array $extra = []): array
    {
        return ['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1'], 'same_as_buyer' => '1', ...$extra];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $this->app['auth']->forgetGuards();
        $url = "http://gct.localhost{$path}";
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
    private function inside(Closure $work, string $shop = 'gct'): mixed
    {
        tenancy()->initialize(Tenant::query()->findOrFail($shop));

        try {
            return $work();
        } finally {
            tenancy()->end();
        }
    }
}
