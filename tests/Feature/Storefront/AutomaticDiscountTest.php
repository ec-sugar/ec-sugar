<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Catalog\Models\Category;
use App\Core\Discount\Models\Coupon;
use App\Core\Discount\Models\CouponRedemption;
use App\Core\Discount\Services\AutomaticDiscounts;
use App\Core\Discount\Services\CouponRejected;
use App\Core\Discount\Services\CouponService;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentService;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartLine;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Members\Member;
use App\Storefront\Orders\OrderReader;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 自動で効く割引とまとめ買いの割引（ADR 0065）。コードを入れなくても条件に合えば効き、1 つの注文に値引きは 1 つ
 * （いちばん得なもの。入れたコードのクーポンは自動の割引と同じかそれ以上に得な時だけ）。数える・戻す・割り振りはクーポンと同じ仕組み
 */
final class AutomaticDiscountTest extends TestCase
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
        $this->tenant = Tenant::create(['id' => 'atd', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'atd.localhost']);
        [$this->knit, $this->cap] = $this->inside(function (): array {
            $knit = $this->listing('ニット', 4400);
            $cap = $this->listing('キャップ', 3300);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 10000]);
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

    public function test_bulk_tiers_swap_as_the_quantity_crosses_each_threshold(): void
    {
        $five = $this->automatic(['label' => 'まとめ買い 5%', 'kind' => 'percent', 'value' => 5, 'min_quantity' => 2]);
        $ten = $this->automatic(['label' => 'まとめ買い 10%', 'kind' => 'percent', 'value' => 10, 'min_quantity' => 3]);

        $sale = $this->inside(function () use ($five, $ten): Transaction {
            $carts = app(CartService::class);
            $cart = $this->cart([$this->knit => 1]);

            // 1 点: どちらも効かない。いちばん近い段の一言
            $view = $carts->view($cart);
            $this->assertNull($view->discountId);
            $this->assertSame(0, $view->discount);
            $this->assertSame('あと 1 点で「まとめ買い 5%」', $view->discountHint);

            // 2 点（ちょうど N 点）: 5%。次の段の一言
            $carts->add($cart, $this->variant($this->cap), 1);
            $view = $carts->view($cart);
            $this->assertSame($five, $view->discountId);
            $this->assertTrue($view->discountAutomatic);
            $this->assertSame('まとめ買い 5%', $view->discountName);
            // 7,700 の 5% = 385
            $this->assertSame(385, $view->discount);
            $this->assertSame('あと 1 点で「まとめ買い 10%」', $view->discountHint);

            // 3 点: 10% に入れ替わる。もう上の段は無いので一言も無い
            $line = CartLine::query()->where('cart_id', $cart->id)->where('listing_id', $this->knit)->sole();
            $carts->setQuantity($cart, $line->id, 2);
            $view = $carts->view($cart);
            $this->assertSame($ten, $view->discountId);
            // 12,100 の 10% = 1,210。値引きの後 10,890 は 10,000 以上なので送料無料
            $this->assertSame(1210, $view->discount);
            $this->assertSame(0, $view->shipping);
            $this->assertNull($view->discountHint);

            // 2 点に戻す（N − 1 点）: 5% に戻る
            $carts->setQuantity($cart, $line->id, 1);
            $this->assertSame($five, $carts->view($cart)->discountId);
            $carts->setQuantity($cart, $line->id, 2);

            return $this->place($cart);
        });

        $this->inside(function () use ($sale, $ten): void {
            $this->assertSame(10890, $sale->total_amount);
            $line = $sale->lines()->where('kind', 'discount')->sole();
            $this->assertSame(-1210, $line->amount);
            // 注文の値引きの行の名前は、自動の割引の名前
            $this->assertSame('まとめ買い 10%', $line->description);
            $redemption = CouponRedemption::query()->sole();
            $this->assertSame($ten, $redemption->coupon_id);
            $this->assertSame('reserved', $redemption->status);
            $this->assertSame(1210, array_sum($redemption->allocations));
            $this->assertSame([['title' => 'まとめ買い 10%', 'amountLabel' => '−¥1,210']], app(OrderReader::class)->read($sale)->discounts);

            // イベントに個人情報が無い（クーポンの ID と額だけ）
            $redeemed = DomainEvent::query()->where('type', 'coupon.redeemed')->sole();
            $this->assertSame(['amount', 'coupon_id'], $this->sortedKeys($redeemed->payload));
            $created = DomainEvent::query()->where('type', 'coupon.created')->where('aggregate_id', $ten)->sole();
            $this->assertEquals(['kind' => 'percent', 'method' => 'automatic'], $created->payload);
            $all = json_encode(DomainEvent::query()->pluck('payload')->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('hanako', $all);
            $this->assertStringNotContainsString('山田', $all);
        });
    }

    public function test_a_code_coupon_is_used_only_when_it_lowers_the_total_more_than_the_automatic_discount(): void
    {
        $sale = $this->automatic(['label' => '秋のセール 10%', 'kind' => 'percent', 'value' => 10]);
        // 後から作った同じ額の自動の割引（同じなら先に作った方が効く）
        $this->automatic(['label' => 'あとから 10%', 'kind' => 'percent', 'value' => 10]);
        $this->coupon(['code' => 'BIG', 'kind' => 'fixed', 'value' => 1000]);
        $this->coupon(['code' => 'SMALL', 'kind' => 'fixed', 'value' => 500]);
        $this->coupon(['code' => 'SAME', 'kind' => 'fixed', 'value' => 770]);

        $this->inside(function () use ($sale): void {
            $carts = app(CartService::class);
            // 7,700 の 10% = 770
            $cart = $this->cart([$this->knit => 1, $this->cap => 1]);
            $this->assertSame(770, $carts->view($cart)->discount);
            $this->assertSame($sale, $carts->view($cart)->discountId);

            // クーポンの方が得: クーポンを使う
            $carts->applyCoupon($cart, 'BIG');
            $view = $carts->view($cart);
            $this->assertSame(1000, $view->discount);
            $this->assertFalse($view->discountAutomatic);
            $this->assertSame('クーポン（BIG）', $view->discountName);
            $this->assertNull($view->couponNotice);
            $this->assertTrue($view->couponApplied());

            // 自動の割引の方が得: 自動のまま、理由を出し、コードはカートに残す
            $carts->applyCoupon($cart, 'SMALL');
            $view = $carts->view($cart);
            $this->assertSame(770, $view->discount);
            $this->assertSame($sale, $view->discountId);
            $this->assertSame(AutomaticDiscounts::CODE_NOT_BETTER, $view->couponNotice);
            $this->assertSame('自動の割引が適用されているため、クーポンは使われていません。', $view->couponNotice);
            $this->assertFalse($view->couponApplied());
            $this->assertSame('SMALL', Cart::query()->whereKey($cart->id)->value('coupon_code'));

            // カートが変われば入れ替わる（4,400 の 10% = 440 < 500）
            $line = CartLine::query()->where('cart_id', $cart->id)->where('listing_id', $this->cap)->sole();
            $carts->remove($cart, $line->id);
            $view = $carts->view($cart);
            $this->assertSame(500, $view->discount);
            $this->assertSame('クーポン（SMALL）', $view->discountName);
            $this->assertNull($view->couponNotice);

            // 同じ値打ちなら自動の割引（1 人 1 回のクーポンを同じ額のために使わせない）。理由は同じ文、確かめの画面でも 1 つの言い方
            $other = $this->cart([$this->knit => 1, $this->cap => 1]);
            $carts->applyCoupon($other, 'SAME');
            $view = $carts->view($other);
            $this->assertSame(770, $view->discount);
            $this->assertSame($sale, $view->discountId);
            $this->assertSame(AutomaticDiscounts::CODE_NOT_BETTER, $view->couponNotice);
            $this->assertSame(AutomaticDiscounts::CODE_NOT_BETTER, $view->couponNoticeForConfirm());

            // 自動の割引の方が得なまま注文すると、値引きは自動の割引で、クーポンの使った記録は残らない
            $carts->applyCoupon($other, 'SMALL');
            $placed = $this->place($other);
            $this->assertSame('秋のセール 10%', $placed->lines()->where('kind', 'discount')->value('description'));
            $this->assertSame([$sale], CouponRedemption::query()->pluck('coupon_id')->all());
        });
    }

    public function test_a_discount_that_drops_the_cart_below_the_free_shipping_line_is_used_only_when_the_total_still_goes_down(): void
    {
        // 送料 ¥550、¥10,000 以上で無料。¥10,050 の品
        $bag = $this->inside(fn (): string => $this->listing('かばん', 10050));
        $small = $this->automatic(['label' => '¥100 引き', 'kind' => 'fixed', 'value' => 100]);
        $this->coupon(['code' => 'TINY', 'kind' => 'fixed', 'value' => 100]);

        $this->inside(function () use ($bag, $small): void {
            $carts = app(CartService::class);
            $cart = $this->cart([$bag => 1]);

            // ¥100 引くと ¥9,950 で線を下回り、送料 ¥550 で ¥10,500。値引きの無い ¥10,050 より高いので効かせない
            $view = $carts->view($cart);
            $this->assertNull($view->discountId);
            $this->assertSame(0, $view->discount);
            $this->assertSame(0, $view->shipping);
            $this->assertSame(10050, $view->total());

            // クーポンでも同じ: 使わず、理由を出し、コードはカートに残す。確かめの画面では文を重ねない
            $carts->applyCoupon($cart, 'tiny');
            $view = $carts->view($cart);
            $this->assertNull($view->discountId);
            $this->assertSame(10050, $view->total());
            $this->assertSame(AutomaticDiscounts::CODE_NO_SAVING, $view->couponNotice);
            $this->assertSame(AutomaticDiscounts::CODE_NO_SAVING, $view->couponNoticeForConfirm());
            $this->assertSame('TINY', Cart::query()->whereKey($cart->id)->value('coupon_code'));

            // 注文しても値引きは無く、使った記録も残らない
            $sale = $this->place($cart);
            $this->assertSame(10050, $sale->total_amount);
            $this->assertSame(0, CouponRedemption::query()->count());

            // 線をまたいでも合計が下がれば効く: ¥1,000 引きは ¥9,050 ＋ ¥550 = ¥9,600（¥450 下がる）
            $big = app(CouponService::class)->create(['method' => 'automatic', 'label' => '¥1,000 引き', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 1000], 'test');
            $view = $carts->view($this->cart([$bag => 1]));
            $this->assertSame($big->id, $view->discountId);
            $this->assertSame(1000, $view->discount);
            $this->assertSame(550, $view->shipping);
            $this->assertSame(9600, $view->total());
            $this->assertNotSame($small, $view->discountId);

            // 使えない理由（期限など）には「クーポンは使わずに」を添える
            app(CouponService::class)->create(['code' => 'OLD', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 100, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()], 'test');
            $old = $this->cart([$this->cap => 1]);
            $carts->applyCoupon($old, 'OLD');
            $this->assertSame('このクーポンは、利用期間が終了しました。（クーポンは適用されずにご注文となります）', $carts->view($old)->couponNoticeForConfirm());
        });
    }

    public function test_the_discount_is_split_by_tax_rate_when_reduced_and_standard_items_are_mixed(): void
    {
        $this->automatic(['label' => '全品 10%', 'kind' => 'percent', 'value' => 10]);
        $cookie = $this->inside(fn (): string => $this->listing('焼き菓子', 1080, 'reduced'));

        $this->inside(function () use ($cookie): void {
            $cart = $this->cart([$cookie => 1, $this->cap => 1]);
            $view = app(CartService::class)->view($cart);
            // 4,380 の 10% = 438。率ごとの対象の額（1,080 : 3,300）で 108 : 330 に割り振る
            $this->assertSame(438, $view->discount);
            $this->assertSame([800 => 972, 1000 => 2970 + 550], $view->taxAmounts);

            $sale = $this->place($cart);
            $lines = $sale->lines()->where('kind', 'discount')->orderBy('tax_rate_bp')->get();
            $this->assertSame([[800, -108, '全品 10%'], [1000, -330, '全品 10%']], $lines->map(static fn ($l): array => [(int) $l->tax_rate_bp, (int) $l->amount, $l->description])->all());
            // お客さんの画面とメールでは、同じ名前の値引きの行を 1 つにまとめる
            $this->assertSame([['title' => '全品 10%', 'amountLabel' => '−¥438']], app(OrderReader::class)->read($sale)->discounts);
            $this->assertSame(438, CouponRedemption::query()->sole()->amount);
        });
    }

    public function test_free_shipping_minimum_amount_members_only_and_period_conditions(): void
    {
        $free = $this->automatic(['label' => '¥5,000 以上で送料無料', 'kind' => 'free_shipping', 'value' => 0, 'min_subtotal' => 5000]);
        $small = $this->automatic(['label' => '¥300 引き', 'kind' => 'fixed', 'value' => 300]);
        $members = $this->automatic(['label' => '会員さま 20%', 'kind' => 'percent', 'value' => 20, 'members_only' => true]);
        $this->automatic(['label' => '終わったセール', 'kind' => 'percent', 'value' => 50, 'min_quantity' => 5, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        $this->automatic(['label' => 'これからのセール', 'kind' => 'percent', 'value' => 50, 'min_quantity' => 5, 'starts_at' => now()->addDay()]);

        $this->inside(function () use ($free, $small, $members): void {
            $carts = app(CartService::class);

            // 3,300: 送料無料はあと ¥1,700。今は ¥300 引きが効く。会員だけ・期間の外は効かず、一言にも出ない
            $cart = $this->cart([$this->cap => 1]);
            $view = $carts->view($cart);
            $this->assertSame($small, $view->discountId);
            $this->assertSame(300, $view->discount);
            $this->assertSame('あと ¥1,700 で「¥5,000 以上で送料無料」', $view->discountHint);

            // 7,700: 送料無料（免じる ¥550）は ¥300 引きより得
            $carts->add($cart, $this->variant($this->knit), 1);
            $view = $carts->view($cart);
            $this->assertSame($free, $view->discountId);
            $this->assertSame(0, $view->shipping);
            $this->assertSame(550, $view->waivedShipping);
            $this->assertSame(0, $view->discount);
            $this->assertSame('無料（¥5,000 以上で送料無料）', $view->shippingLabel());
            $this->assertNull($view->discountHint);

            // 13,200 は 10,000 以上で送料がもともと無料: 送料無料の値打ちは 0 なので ¥300 引き
            $big = $this->cart([$this->knit => 3]);
            $this->assertSame($small, $carts->view($big)->discountId);

            $sale = $this->place($cart);
            // 送料の行も値引きの行も作らず、使った額は送料の分
            $this->assertFalse($sale->lines()->whereIn('kind', ['shipping', 'discount'])->exists());
            $this->assertSame(7700, $sale->total_amount);
            $this->assertSame(550, CouponRedemption::query()->sole()->amount);

            // 会員は会員だけの割引が効く（3,300 の 20% = 660）
            $account = app(CustomerAccounts::class)->forEmail('member@example.com', 'test');
            Auth::guard('customer')->setUser(new Member($account, 'atd'));
            $mine = $this->cart([$this->cap => 1]);
            $this->assertSame($members, $carts->view($mine)->discountId);
            $this->assertSame(660, $carts->view($mine)->discount);
            Auth::forgetGuards();
        });
    }

    public function test_the_usage_limit_lets_only_one_of_two_orders_get_the_discount_and_unpaid_orders_give_it_back(): void
    {
        $once = $this->automatic(['label' => '先着 1 名 ¥500 引き', 'kind' => 'fixed', 'value' => 500, 'usage_limit' => 1]);

        $first = $this->inside(function (): Transaction {
            // 2 つのカートで同時に確かめの画面まで進む（どちらにも効く）
            $a = $this->cart([$this->cap => 1]);
            $b = $this->cart([$this->cap => 1]);
            $fingerprintB = $this->fingerprint($b, 'jiro@example.com');
            $this->assertSame(500, app(CartService::class)->view($b)->discount);
            $sale = $this->place($a);
            $this->assertSame(2800 + 550, $sale->total_amount);

            // 先の注文が最後の 1 回を使った。後の注文は錠の中で決め直すと値引きが無くなり、確かめた指紋と違うので確定しない
            try {
                app(PlaceOrder::class)->place($b, $this->input('jiro@example.com'), $this->key(), $fingerprintB);
                $this->fail('上限を超えて値引きが効いた');
            } catch (OrderException $e) {
                $this->assertTrue($e->changed);
            }
            $this->assertSame(1, Transaction::query()->count());
            $this->assertSame(1, CouponRedemption::query()->count());

            // 確かめ直すと値引きの無い注文になる
            $this->assertNull(app(CartService::class)->view($b)->discountId);

            // 2 枚目の守り: 使った記録を残すときも錠の中で照らし直して断る（文は割引の名前で）
            try {
                DB::transaction(function () use ($sale): void {
                    app(EventStore::class)->lock();
                    $coupon = Coupon::query()->where('method', 'automatic')->sole();
                    app(CouponService::class)->redeem($sale->id, $coupon, [['key' => 'x', 'listingId' => $this->cap, 'amount' => 3300, 'quantity' => 1]], null, false, 500, ['x' => 500], 0, 'test');
                });
                $this->fail('回数の上限を超えて使った記録が残った');
            } catch (CouponRejected $e) {
                $this->assertSame('割引「先着 1 名 ¥500 引き」は、使える回数の上限に達しました。', $e->getMessage());
            }
            $this->assertSame(1, CouponRedemption::query()->count());

            return $sale;
        });

        // 払われずに取り消された注文は数を戻す。戻ると、また効く
        $this->inside(function () use ($first, $once): void {
            app(PaymentService::class)->startCheckout($first, 'x', 'y');
            $this->assertTrue(app(SaleOrderService::class)->cancelIfUnpaid($first, 'test'));
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($first->id)->status);
            $this->assertSame('released', CouponRedemption::query()->sole()->status);
            $again = $this->cart([$this->cap => 1]);
            $this->assertSame($once, app(CartService::class)->view($again)->discountId);

            // 払いが届いた注文は used に（数え方は同じ）
            $paid = $this->place($again);
            event(new PaymentConfirmed($paid->id));
            $this->assertSame('used', CouponRedemption::query()->where('transaction_id', $paid->id)->value('status'));
            $this->assertNull(app(CartService::class)->view($this->cart([$this->cap => 1]))->discountId);
        });
    }

    public function test_an_order_is_not_placed_when_another_discount_of_the_same_amount_took_over_after_the_confirmation(): void
    {
        $first = $this->automatic(['label' => '週末セール ¥500 引き', 'kind' => 'fixed', 'value' => 500]);

        $this->inside(function () use ($first): void {
            $cart = $this->cart([$this->cap => 1]);
            $fingerprint = $this->fingerprint($cart);

            // 確かめた後に店が止め、同じ額の別の割引を作った: 額は同じでも、見せた値引きと違うので確定しない
            app(CouponService::class)->setStatus($first, 'paused', 'test');
            app(CouponService::class)->create(['method' => 'automatic', 'label' => '別の ¥500 引き', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 500], 'test');
            $this->assertSame(500, app(CartService::class)->view($cart)->discount);

            try {
                app(PlaceOrder::class)->place($cart, $this->input(), $this->key(), $fingerprint);
                $this->fail('確認した値引きと違う値引きで注文ができた');
            } catch (OrderException $e) {
                $this->assertTrue($e->changed);
            }
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, CouponRedemption::query()->count());
        });
    }

    public function test_a_member_cannot_get_past_the_per_customer_limit_by_changing_the_order_email(): void
    {
        $first = $this->automatic(['label' => 'はじめての方 10%', 'kind' => 'percent', 'value' => 10, 'per_customer_limit' => 1]);
        $this->coupon(['code' => 'ONCE-1', 'kind' => 'fixed', 'value' => 500, 'per_customer_limit' => 1]);

        $this->inside(function () use ($first): void {
            $member = app(CustomerAccounts::class)->forEmail('member@example.com', 'test');
            Auth::guard('customer')->setUser(new Member($member, 'atd'));
            $carts = app(CartService::class);

            // 自分のメールで使う（記録は会員の台帳に）
            $sale = $this->place($this->cart([$this->cap => 1]), 'member@example.com');
            $this->assertSame(-330, (int) $sale->lines()->where('kind', 'discount')->sum('amount'));
            $this->assertSame($member->id, CouponRedemption::query()->where('coupon_id', $first)->value('customer_account_id'));

            // 注文のメールを別の宛先に書き換えても、会員の台帳で数えるので効かない（カート・確かめの画面・確定で同じ）
            $other = $this->cart([$this->cap => 1]);
            $this->assertNull($carts->view($other)->discountId);
            $this->assertNull($carts->view($other, null, 'member+1@example.com')->discountId);
            $this->assertSame(3300 + 550, $this->place($other, 'member+1@example.com')->total_amount);

            // クーポンも同じ: 別のメールで使った後、元のメール・また別のメールでは使えない
            $coupon = $this->cart([$this->cap => 1]);
            $carts->applyCoupon($coupon, 'ONCE-1');
            $this->assertSame(3300 - 500 + 550, $this->place($coupon, 'member+2@example.com')->total_amount);
            foreach (['member@example.com', 'member+3@example.com'] as $email) {
                $again = $this->cart([$this->cap => 1]);
                $carts->applyCoupon($again, 'ONCE-1');
                $view = $carts->view($again, null, $email);
                $this->assertSame(CouponService::USED_BY_YOU, $view->couponNotice);
                $this->assertSame(0, $view->discount);
            }
            $this->assertSame(2, CouponRedemption::query()->count());

            // 商品の画面: 会員が使い切った割引は出さない（ゲストには出す）
            $discounts = app(AutomaticDiscounts::class);
            $this->assertSame([], $discounts->forListing($this->cap, $member->id));
            $this->assertSame(['はじめての方 10%'], $discounts->forListing($this->cap, null));
            Auth::forgetGuards();
        });
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_automatic_discounts(): void
    {
        $category = $this->inside(function (): string {
            $category = Category::create(['name' => '帽子', 'slug' => 'caps', 'position' => 0]);
            Listing::query()->findOrFail($this->cap)->categories()->attach($category->id);

            return $category->id;
        });
        $this->coupon(['code' => 'COUNT', 'kind' => 'fixed', 'value' => 100]);
        $make = function (int $from, int $to) use ($category): void {
            for ($i = $from; $i <= $to; $i++) {
                // カテゴリが対象・数の不足（一言の候補）・回数の上限と 1 人の回数・額の不足・送料無料を混ぜる
                $this->automatic(match ($i % 4) {
                    0 => ['label' => "帽子 {$i}", 'kind' => 'percent', 'value' => 5, 'target' => 'categories', 'target_ids' => [$category], 'min_quantity' => 3],
                    1 => ['label' => "先着 {$i}", 'kind' => 'fixed', 'value' => $i, 'usage_limit' => 100, 'per_customer_limit' => 1],
                    2 => ['label' => "大口 {$i}", 'kind' => 'fixed', 'value' => 1000, 'min_subtotal' => 50000 + $i],
                    default => ['label' => "送料 {$i}", 'kind' => 'free_shipping', 'value' => 0, 'min_subtotal' => 5000 + $i],
                });
            }
        };

        $count = function (): int {
            return $this->inside(function (): int {
                $cart = $this->cart([$this->knit => 1, $this->cap => 2]);
                app(CartService::class)->applyCoupon($cart, 'COUNT');
                $queries = 0;
                DB::listen(static function () use (&$queries): void {
                    $queries++;
                });
                app(CartService::class)->view($cart, $this->input()->destination(), 'hanako@example.com');
                $seen = $queries;
                $queries = -1000000; // 後の操作は数えない

                return $seen;
            });
        };

        $make(1, 4);
        $few = $count();
        $make(5, 16);
        $many = $count();
        $this->assertGreaterThan(0, $few);
        $this->assertSame($few, $many, "割引 4 件で {$few} 回、16 件で {$many} 回のクエリ");
    }

    public function test_the_hint_is_not_shown_for_a_discount_that_would_not_lower_the_total_once_reached(): void
    {
        $bag = $this->inside(fn (): string => $this->listing('かばん', 9800));
        $this->automatic(['label' => '¥10,000 以上で ¥500 引き', 'kind' => 'fixed', 'value' => 500, 'min_subtotal' => 10000]);

        $this->inside(function () use ($bag): void {
            // ¥9,800: あと ¥200 で届くが、届いた ¥10,000 から ¥500 引くと ¥9,500 ＋ 送料 ¥550 = ¥10,050 で、値引きの無い ¥10,000 より高い
            $cart = $this->cart([$bag => 1]);
            $this->assertNull(app(CartService::class)->view($cart)->discountHint);

            // ¥1,000 引きなら ¥9,000 ＋ ¥550 = ¥9,550 で下がるので勧める
            app(CouponService::class)->create(['method' => 'automatic', 'label' => '¥10,000 以上で ¥1,000 引き', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 1000, 'min_subtotal' => 10000], 'test');
            $this->assertSame('あと ¥200 で「¥10,000 以上で ¥1,000 引き」', app(CartService::class)->view($cart)->discountHint);
        });
    }

    public function test_an_order_is_not_placed_when_the_discount_name_changed_after_the_confirmation(): void
    {
        $sale = $this->automatic(['label' => '週末セール', 'kind' => 'fixed', 'value' => 300]);

        $this->inside(function () use ($sale): void {
            $cart = $this->cart([$this->cap => 1]);
            $fingerprint = $this->fingerprint($cart);
            // 確かめた後に名前だけ直された: 見せた値引きの行と注文の行が食い違うので確定しない
            app(CouponService::class)->update($sale, ['label' => '平日セール'], 'test');

            try {
                app(PlaceOrder::class)->place($cart, $this->input(), $this->key(), $fingerprint);
                $this->fail('確認した名前と違う値引きの行で注文ができた');
            } catch (OrderException $e) {
                $this->assertTrue($e->changed);
            }
            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_the_per_customer_limit_is_checked_with_the_buyers_email_before_the_order_so_guests_are_not_stuck(): void
    {
        $this->automatic(['label' => 'はじめての方 10%', 'kind' => 'percent', 'value' => 10, 'per_customer_limit' => 1]);

        $this->inside(function (): void {
            $first = $this->cart([$this->cap => 1]);
            $this->assertSame(-330, (int) $this->place($first)->lines()->where('kind', 'discount')->sum('amount'));

            // 同じメールのゲスト: カートでは分からないので効いて見えるが、確かめの画面（メールが分かる）では効かない
            $second = $this->cart([$this->cap => 1]);
            $this->assertSame(330, app(CartService::class)->view($second)->discount);
            $confirm = app(CartService::class)->view($second, null, 'HANAKO@example.com');
            $this->assertSame(0, $confirm->discount);
            $this->assertNull($confirm->discountId);

            // 確かめの画面の指紋のまま、値引きの無い注文として確定できる（行き止まりにならない）
            $sale = app(PlaceOrder::class)->place($second, $this->input('HANAKO@example.com'), $this->key(), $this->fingerprint($second, 'HANAKO@example.com'));
            $this->assertSame(3300 + 550, $sale->total_amount);
            $this->assertSame(1, CouponRedemption::query()->count());

            // ほかのお客さんには効く
            $third = $this->cart([$this->cap => 1]);
            $this->assertSame(330, app(CartService::class)->view($third, null, 'jiro@example.com')->discount);
        });
    }

    public function test_the_cart_and_product_pages_show_the_discount_name_the_hint_and_the_discounts_for_the_product(): void
    {
        $category = $this->inside(function (): string {
            $category = Category::create(['name' => '帽子', 'slug' => 'caps', 'position' => 0]);
            Listing::query()->findOrFail($this->cap)->categories()->attach($category->id);

            return $category->id;
        });
        $this->automatic(['label' => 'まとめ買い 10%', 'kind' => 'percent', 'value' => 10, 'min_quantity' => 2]);
        $this->automatic(['label' => '帽子 ¥200 引き', 'kind' => 'fixed', 'value' => 200, 'target' => 'categories', 'target_ids' => [$category]]);
        $this->automatic(['label' => '会員さま 20%', 'kind' => 'percent', 'value' => 20, 'members_only' => true]);
        $this->automatic(['label' => 'これからのセール', 'kind' => 'percent', 'value' => 50, 'starts_at' => now()->addDay()]);

        // 商品の画面: その商品が対象で今使える割引だけ（会員だけ・始まる前は出さない）
        $knit = (string) $this->send('GET', "/products/{$this->knit}")->assertOk()->getContent();
        $this->assertStringContainsString('data-test="product-discounts"', $knit);
        $this->assertStringContainsString('まとめ買い 10%', $knit);
        $this->assertStringNotContainsString('帽子 ¥200 引き', $knit);
        $this->assertStringNotContainsString('会員さま 20%', $knit);
        $this->assertStringNotContainsString('これからのセール', $knit);
        $cap = (string) $this->send('GET', "/products/{$this->cap}")->assertOk()->getContent();
        $this->assertStringContainsString('帽子 ¥200 引き', $cap);

        // カート: 効いた割引の名前と、次に届く割引の一言（1 点のキャップ: ¥200 引きが効いて、あと 1 点でまとめ買い）
        $this->send('POST', '/cart/items', ['listing_id' => $this->cap, 'quantity' => '1'])->assertRedirect();
        $page = (string) $this->send('GET', '/cart')->assertOk()->getContent();
        $this->assertStringContainsString('帽子 ¥200 引き', $page);
        $this->assertStringContainsString('−¥200', $page);
        $this->assertStringContainsString('あと 1 点で「まとめ買い 10%」', $page);
        $json = $this->withHeader('Accept', 'application/json')->send('GET', '/cart');
        $this->flushHeaders();
        $this->assertSame('帽子 ¥200 引き', $json->json('cart.discount_name'));
        $this->assertSame('あと 1 点で「まとめ買い 10%」', $json->json('cart.discount_hint'));

        // 自動の割引の方が得なコードは、誤りでなく知らせとして受け、コードを入れたまま
        $this->inside(fn () => app(CouponService::class)->create(['code' => 'TINY', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 100], 'test'));
        $this->send('POST', '/cart/coupon', ['code' => 'tiny'])->assertRedirect()->assertSessionHas('cart_message', AutomaticDiscounts::CODE_NOT_BETTER);
        $page = (string) $this->send('GET', '/cart')->assertOk()->getContent();
        $this->assertStringContainsString('data-test="coupon-code">TINY<', $page);
        $this->assertStringContainsString('sugar-coupon__notice--info', $page);
    }

    public function test_at_most_twenty_automatic_discounts_are_live_and_the_database_keeps_the_shape(): void
    {
        $this->inside(function (): void {
            $service = app(CouponService::class);
            $ids = [];

            for ($i = 1; $i <= CouponService::MAX_AUTOMATIC; $i++) {
                $ids[] = $service->create(['method' => 'automatic', 'label' => "割引 {$i}", 'name' => 'テスト', 'kind' => 'fixed', 'value' => $i], 'test')->id;
            }

            // 終わった割引と止めた割引は数えない
            $service->create(['method' => 'automatic', 'label' => '終わった', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 1, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()], 'test');
            $paused = $service->create(['method' => 'automatic', 'label' => '止めた', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 1, 'status' => 'paused'], 'test');

            foreach ([
                'method' => fn () => $service->create(['method' => 'automatic', 'label' => '21 件目', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 1], 'test'),
                'status' => fn () => $service->setStatus($paused->id, 'active', 'test'),
            ] as $field => $attempt) {
                try {
                    $attempt();
                    $this->fail("{$field}: 21 件目が使えるようになった");
                } catch (ValidationException $e) {
                    $this->assertSame([CouponService::TOO_MANY_AUTOMATIC], $e->errors()[$field]);
                }
            }

            // クーポン（コード）は数に入らない
            $service->create(['code' => 'STILL-OK', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 1], 'test');

            // 1 つ止めれば、止めていたものを再開できる
            $service->setStatus($ids[0], 'paused', 'test');
            $this->assertSame('active', $service->setStatus($paused->id, 'active', 'test')->status);
            $this->assertSame(CouponService::MAX_AUTOMATIC, CouponService::liveAutomatic()->count());

            // 自動の割引はコードを持たず、名前が要る。名前を空にして直すことはできない
            $this->assertNull(Coupon::query()->findOrFail($paused->id)->code);
            try {
                $service->update($paused->id, ['label' => '  '], 'test');
                $this->fail('名前を空にできた');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('label', $e->errors());
            }

            // DB の 2 枚目: 自動の割引に名前が無い・コードがある、クーポンにコードが無い
            foreach ([
                ['method' => 'automatic', 'code' => null, 'label' => null],
                ['method' => 'automatic', 'code' => 'HAS-CODE', 'label' => '名前'],
                ['method' => 'code', 'code' => null, 'label' => null],
                ['method' => 'code', 'code' => 'WITH-LABEL', 'label' => '名前'],
                ['method' => 'automatic', 'code' => null, 'label' => '   '],
            ] as $row) {
                try {
                    DB::transaction(static fn () => DB::table('coupons')->insert($row + ['id' => strtolower((string) Str::ulid()), 'name' => 'x', 'kind' => 'fixed', 'value' => 1, 'created_at' => now(), 'updated_at' => now()]));
                    $this->fail('DB が受けた: '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                } catch (QueryException $e) {
                    $this->assertMatchesRegularExpression('/coupons_(code|label)_by_method/', $e->getMessage());
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function automatic(array $values): string
    {
        return $this->inside(fn (): string => app(CouponService::class)->create($values + ['method' => 'automatic', 'name' => 'テスト'], 'test')->id);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function coupon(array $values): void
    {
        $this->inside(fn () => app(CouponService::class)->create($values + ['name' => 'テスト'], 'test'));
    }

    private function listing(string $title, int $price, string $taxClass = 'standard'): string
    {
        $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $price, 'status' => 'active', 'tax_class' => $taxClass]);
        app(StockService::class)->receive($listing, Location::query()->orderBy('id')->firstOrFail(), 30);

        return $listing->id;
    }

    private function variant(string $listing): string
    {
        return (string) ListingVariant::query()->where('listing_id', $listing)->value('id');
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

    /**
     * 確かめの画面と同じ決め方の指紋（お届け先と買い手のメールで）
     */
    private function fingerprint(Cart $cart, string $email = 'hanako@example.com'): string
    {
        return CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart, $this->input($email)->destination(), $email));
    }

    private function place(Cart $cart, string $email = 'hanako@example.com'): Transaction
    {
        return app(PlaceOrder::class)->place($cart, $this->input($email), $this->key(), $this->fingerprint($cart, $email));
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
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
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

        $url = "http://atd.localhost{$path}";
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
