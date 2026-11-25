<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Discount\Services\CouponService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\MemberBenefits;
use App\Core\Plugin\Services\AdminPages;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Points\Models\OrderPoints;
use App\Core\Points\Services\PointSettings;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Access\ListingPreviewLink;
use App\Storefront\Access\MemberPhotoLink;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartService;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Checkout\PlaceSingleOrder;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberCookie;
use Carbon\CarbonImmutable;
use EcSugar\PluginCartReminder\ReminderItems;
use EcSugar\PluginMemberRanks\Jobs\SendRankNotice;
use EcSugar\PluginMemberRanks\Mail\RankUpMail;
use EcSugar\PluginMemberRanks\Models\RankChange;
use EcSugar\PluginMemberRanks\Models\RankMember;
use EcSugar\PluginMemberRanks\PurchaseAmounts;
use EcSugar\PluginMemberRanks\RankAdminPage;
use EcSugar\PluginMemberRanks\RankListingPanel;
use EcSugar\PluginMemberRanks\Ranks;
use EcSugar\PluginRestock\Jobs\SendRestockMail;
use EcSugar\PluginRestock\Mail\RestockMail;
use EcSugar\PluginRestock\Models\RestockRequest;
use EcSugar\PluginRestock\RestockTargets;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeMedia;
use Tests\Support\FakeStripe;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「会員ランク」（ADR 0079）。直近 12 か月の購入額（発送した注文のお金 − 返金。台帳から）でランクを毎日決め直し、
 * 会員価格（値引きの候補で、自動の割引・クーポンといちばん得な 1 つ）・ポイントの上乗せ・会員だけ・ランクだけの商品を核の口で効かせる。
 * 境界（ちょうどの額・12 か月の端）・返金で下がる・自動の割引とクーポンとの重なり・確かめの後のランクの変わり・URL を直に打っても買えない・
 * App を止めた・別の店・2 回流しても 1 回・手で決めたランク
 */
final class MemberRanksAppTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    private string $knit;

    /** @var array<string, string> 送る Cookie（名前 => 値） */
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        FakeStripe::install();
        // 日本の昼に固定する（12 か月の端を日本の暦の日で数えるため）
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Asia/Tokyo'));
        $this->tenant('mra', 'かくざとう');
        $this->knit = $this->inside('mra', fn (): string => $this->prepare());
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_the_rank_follows_the_amount_of_the_last_12_months_at_the_exact_boundaries(): void
    {
        $this->inside('mra', function (): void {
            $taro = $this->member('taro@example.com');
            // 2 点 8,800 円 ＋ 送料 550 円 = 9,350 円を払い、発送する
            $order = $this->shippedOrder($taro, 2);
            $this->assertSame(9350, app(PurchaseAmounts::class)->forAccount($taro->id, CarbonImmutable::now()));

            // ちょうどの額でランク 1、1 円足りなければランクなし（下がる時は知らせない）
            $this->settings(['rank1_threshold' => '9350']);
            $this->assertSame(['up' => 1, 'down' => 0], app(Ranks::class)->run(CarbonImmutable::now()));
            $this->assertSame(1, app(Ranks::class)->current($taro->id));
            // 店で初めてランクが付く決め直しは知らせない（App を使い始めた夜に全員へ一斉に送らない）
            Mail::assertNotSent(RankUpMail::class);

            $this->settings(['rank1_threshold' => '9351']);
            $this->assertSame(['up' => 0, 'down' => 1], app(Ranks::class)->run(CarbonImmutable::now()));
            $this->assertSame(0, app(Ranks::class)->current($taro->id));
            Mail::assertNotSent(RankUpMail::class);

            // 12 か月の端: 日本の暦の今日の 0 時から 12 か月前ちょうどに発送したものは数え、1 秒前は数えない
            $this->settings(['rank1_threshold' => '9350']);
            $since = PurchaseAmounts::since(CarbonImmutable::now());
            $this->assertSame('2025-11-05 00:00:00', $since->timezone('Asia/Tokyo')->format('Y-m-d H:i:s'));
            Fulfillment::query()->where('transaction_id', $order->id)->update(['shipped_at' => $since->subSecond()->utc()]);
            app(Ranks::class)->run(CarbonImmutable::now());
            $this->assertSame(0, app(Ranks::class)->current($taro->id));

            Fulfillment::query()->where('transaction_id', $order->id)->update(['shipped_at' => $since->utc()]);
            app(Ranks::class)->run(CarbonImmutable::now());
            $this->assertSame(1, app(Ranks::class)->current($taro->id));
            // 2 回目からの上がりは知らせる。リンクはマイページの会員ランクの画面
            Mail::assertSent(RankUpMail::class, 1);
            Mail::assertSent(RankUpMail::class, static fn (RankUpMail $m): bool => $m->pageUrl === 'http://mra.localhost/p/member-ranks/account' && $m->rankName === 'ブロンズ');

            // 変わった記録とイベント（前と後のランク・理由だけ。名前・メール・額は入れない）
            $this->assertSame([[0, 1, 'auto'], [1, 0, 'auto'], [0, 1, 'auto']], RankChange::query()->orderBy('id')->get()->map(static fn (RankChange $c): array => [$c->from_rank, $c->to_rank, $c->cause])->all());
            $event = DomainEvent::query()->where('type', 'member_rank.changed')->orderByDesc('sequence')->firstOrFail();
            $this->assertSame(['cause' => 'auto', 'from' => 0, 'to' => 1], self::sorted($event->payload));
            $this->assertSame(Ranks::ACTOR, $event->actor);
        });
    }

    public function test_unshipped_orders_gift_cards_and_test_payments_do_not_count_and_a_refund_lowers_the_rank(): void
    {
        $this->inside('mra', function (): void {
            $taro = $this->member('taro@example.com');
            $this->settings(['rank1_threshold' => '9350']);

            // 払っただけ（発送の前）は数えない
            $paid = $this->order($taro, 2);
            $this->pay($paid);
            $this->assertSame(0, app(PurchaseAmounts::class)->forAccount($taro->id, CarbonImmutable::now()));

            // テストのモードで払われた注文は数えない
            $this->shipAll($paid);
            PaymentAttempt::query()->where('transaction_id', $paid->id)->update(['test_mode' => true]);
            $this->assertSame(0, app(PurchaseAmounts::class)->forAccount($taro->id, CarbonImmutable::now()));
            PaymentAttempt::query()->where('transaction_id', $paid->id)->update(['test_mode' => false]);
            $this->assertSame(9350, app(PurchaseAmounts::class)->forAccount($taro->id, CarbonImmutable::now()));
            app(Ranks::class)->run(CarbonImmutable::now());
            $this->assertSame(1, app(Ranks::class)->current($taro->id));

            // 発送の後に 1 円でも返金すると、額が下がってランクも下がる（返金はいつ返しても引く）
            app(LedgerService::class)->recordRefund(Transaction::query()->findOrFail($paid->id), 1, PaymentRoute::Platform, null, 'test');
            $this->assertSame(9349, app(PurchaseAmounts::class)->forAccount($taro->id, CarbonImmutable::now()));
            $this->assertSame(['up' => 0, 'down' => 1], app(Ranks::class)->run(CarbonImmutable::now()));
            $this->assertSame(0, app(Ranks::class)->current($taro->id));
        });
    }

    public function test_running_twice_changes_once_and_a_manual_rank_holds_until_it_is_unpinned(): void
    {
        $this->inside('mra', function (): void {
            $taro = $this->member('taro@example.com');
            $this->shippedOrder($taro, 3);
            $this->settings(['rank1_threshold' => '1000']);

            $this->assertSame(['up' => 1, 'down' => 0], app(Ranks::class)->run(CarbonImmutable::now()));
            $this->assertSame(['up' => 0, 'down' => 0], app(Ranks::class)->run(CarbonImmutable::now()));
            $this->assertSame(1, RankChange::query()->count());
            $this->assertSame(1, DomainEvent::query()->where('type', 'member_rank.changed')->count());
            Mail::assertNotSent(RankUpMail::class);

            // 店の人が手でゴールドに（お金の操作。App の画面の欄のある操作）。毎日の決め直しで変えない
            $page = new RankAdminPage;
            $record = $page->record($taro->id);
            $this->assertNotNull($record);
            $set = collect($record->actions)->firstWhere('key', 'set');
            $this->assertTrue($set?->money);
            $page->actWith($taro->id, 'set', ['rank' => 'rank3'], 'operator:boss');
            $this->assertSame(3, app(Ranks::class)->current($taro->id));
            $this->assertSame('operator:boss', DomainEvent::query()->where('type', 'member_rank.changed')->orderByDesc('sequence')->firstOrFail()->actor);
            Mail::assertSent(RankUpMail::class, 1);
            app(Ranks::class)->run(CarbonImmutable::now());
            $this->assertSame(3, app(Ranks::class)->current($taro->id));
            $this->assertSame('ゴールド', app(MemberBenefits::class)->badgeFor($taro->id));

            // 購入額で決めるに戻すと、今の額で決め直す
            $this->assertTrue(collect($page->record($taro->id)?->actions)->contains('key', 'unpin'));
            $page->act($taro->id, 'unpin', 'operator:boss');
            $this->assertSame(1, app(Ranks::class)->current($taro->id));
            $this->assertNull(RankMember::query()->findOrFail($taro->id)->pinned_until);

            // 使っていないランク・会員でない人は決められない
            $this->settings(['ranks' => 'two']);
            try {
                $page->actWith($taro->id, 'set', ['rank' => 'rank3'], 'operator:boss');
                $this->fail('使っていないランクにできた');
            } catch (AdminActionRefused) {
            }

            $guest = app(CustomerAccounts::class)->forEmail('guest@example.com', 'test');
            $this->assertNull($page->record($guest->id));
            $this->expectException(AdminActionRefused::class);
            $page->actWith($guest->id, 'set', ['rank' => 'rank1'], 'operator:boss');
        });
    }

    public function test_the_member_price_is_one_candidate_among_the_automatic_discounts_and_coupons_and_the_best_one_wins(): void
    {
        $this->inside('mra', function (): void {
            $gold = $this->member('gold@example.com');
            app(Ranks::class)->setManually($gold->id, 3, 'operator:boss');
            $carts = app(CartService::class);
            $this->login($gold);

            // ゴールドは 5%: 3 点 13,200 円 → 660 円引き（送料はもともと無料）
            $cart = $this->cart(3);
            $view = $carts->view($cart);
            $this->assertSame(660, $view->discount);
            $this->assertSame('ゴールドの会員価格（5% 引き）', $view->discountName);
            $this->assertSame('member-price:rank3-5', $view->discountId);

            // 自動の割引 3% より得なので会員価格、10% なら自動の割引（1 つの注文に 1 つ。重ねない）
            $small = app(CouponService::class)->create(['method' => 'automatic', 'label' => '秋の 3%', 'name' => 'テスト', 'kind' => 'percent', 'value' => 3], 'test');
            $this->assertSame('member-price:rank3-5', $carts->view($cart)->discountId);
            app(CouponService::class)->setStatus($small->id, 'paused', 'test');
            $big = app(CouponService::class)->create(['method' => 'automatic', 'label' => '秋の 10%', 'name' => 'テスト', 'kind' => 'percent', 'value' => 10], 'test');
            $this->assertSame($big->id, $carts->view($cart)->discountId);
            $this->assertSame(1320, $carts->view($cart)->discount);
            app(CouponService::class)->setStatus($big->id, 'paused', 'test');

            // 入れたクーポンは、会員価格より得な時だけ使う（同じ・少ないなら会員価格のまま、知らせを出す）
            app(CouponService::class)->create(['code' => 'SMALL', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 500], 'test');
            $carts->applyCoupon($cart, 'SMALL');
            $this->assertSame('member-price:rank3-5', $carts->view($cart)->discountId);
            $this->assertNotNull($carts->view($cart)->couponNotice);
            app(CouponService::class)->create(['code' => 'BIG', 'name' => 'テスト', 'kind' => 'fixed', 'value' => 1000], 'test');
            $carts->applyCoupon($cart, 'BIG');
            $this->assertSame(1000, $carts->view($cart)->discount);
            $carts->removeCoupon($cart);

            // 会員価格で注文する: 値引きの行（名前は会員価格）・効いた印のイベント・使った記録は持たない
            $sale = $this->place($cart, 'gold@example.com');
            $this->assertSame(13200 - 660, (int) $sale->total_amount);
            $this->assertSame(['ゴールドの会員価格（5% 引き）'], $sale->lines()->where('kind', 'discount')->pluck('description')->all());
            $this->assertSame(-660, (int) $sale->lines()->where('kind', 'discount')->sum('amount'));
            $event = DomainEvent::query()->where('type', 'order.member_price_applied')->sole();
            $this->assertSame([$sale->id, 'rank3-5', 660], [$event->aggregate_id, $event->payload['key'], $event->payload['amount']]);
            $this->assertSame(0, DB::table('coupon_redemptions')->where('transaction_id', $sale->id)->count());

            // ランクの無い会員・ゲストには会員価格は無い
            Auth::forgetGuards();
            $this->assertSame(0, $carts->view($this->cart(3))->discount);
            $this->login($this->member('plain@example.com'));
            $this->assertSame(0, $carts->view($this->cart(3))->discount);
        });
    }

    public function test_an_order_confirmed_with_the_member_price_is_not_placed_after_the_rank_changes(): void
    {
        $this->inside('mra', function (): void {
            // ポイントの上乗せはシルバーとゴールドで同じにして、会員価格の違いだけで確かめる
            $this->settings(['rank2_points' => '2']);
            $gold = $this->member('gold@example.com');
            app(Ranks::class)->setManually($gold->id, 3, 'operator:boss');
            $this->login($gold);
            $cart = $this->cart(3);
            $input = $this->input('gold@example.com');
            $fingerprint = CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart, $input->destination(), 'gold@example.com', $input->pointsRequested()));

            // 確かめの画面の後にシルバーへ下がった: 錠の中で決め直すと 3% になり、指紋が合わずに確定しない
            app(Ranks::class)->setManually($gold->id, 2, 'operator:boss');

            try {
                app(PlaceOrder::class)->place($cart, $input, $this->key(), $fingerprint);
                $this->fail('ランクが変わった後に、前の会員価格で注文できた');
            } catch (OrderException $e) {
                $this->assertTrue($e->changed);
            }

            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_the_point_rate_bonus_is_added_for_the_rank_only_when_the_shop_uses_points(): void
    {
        $this->inside('mra', function (): void {
            $gold = $this->member('gold@example.com');
            app(Ranks::class)->setManually($gold->id, 3, 'operator:boss');
            $this->login($gold);
            $carts = app(CartService::class);

            // 1 点 4,400 円（会員価格 5% で 4,180 円）: 店の 1% ＋ ゴールドの 2% = 3% → 125 ポイント
            $cart = $this->cart(1);
            $this->assertSame(125, $carts->view($cart)->points->earn);
            $sale = $this->place($cart, 'gold@example.com');
            $this->assertSame([300, 125], [OrderPoints::query()->findOrFail($sale->id)->rate_bp, OrderPoints::query()->findOrFail($sale->id)->earn]);

            // ゲストの「会員なら」は店の率だけ
            Auth::forgetGuards();
            $this->assertSame(44, $carts->view($this->cart(1))->points->earn);

            // ポイントを使わない店では上乗せも無い
            app(PointSettings::class)->update(['enabled' => false, 'rate_bp' => 100], 'test');
            $this->assertSame(0, app(PointSettings::class)->rateBpFor($gold->id));
        });
    }

    public function test_members_only_and_rank_only_products_are_hidden_and_cannot_be_bought_by_url(): void
    {
        $secret = $this->inside('mra', function (): string {
            $secret = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ゴールドだけの財布', 'unit_amount' => 9900, 'status' => 'active']);
            app(StockService::class)->receive($secret, Location::query()->firstOrFail(), 10);
            $panel = new RankListingPanel;
            $panel->write($secret, ['visibility' => 'rank3'], 'operator:boss');
            $this->assertSame(['visibility' => 'rank3'], $panel->read($secret));
            $this->assertSame('ゴールド以上の会員だけに表示しています。', $panel->note($secret));
            $this->assertSame(1, DomainEvent::query()->where('type', 'member_rank.listing_restricted')->count());
            // 写真を共有のキャッシュに置かない印（ゲストに見せない商品）
            $this->assertFalse(app(Catalog::class)->forEveryone($secret->id));
            $this->assertTrue(app(Catalog::class)->forEveryone($this->knit));

            return $secret->id;
        });
        $variant = $this->inside('mra', fn (): string => (string) ListingVariant::query()->where('listing_id', $secret)->value('id'));

        // ゲスト: 一覧・検索・商品の画面・カート・サイトマップのどこにも無い（URL を直に打っても 404、カートは断る）
        $this->send('GET', '/products')->assertOk()->assertDontSee('ゴールドだけの財布');
        $this->send('GET', '/products?q='.rawurlencode('財布'))->assertOk()->assertDontSee('ゴールドだけの財布');
        $this->send('GET', "/products/{$secret}")->assertNotFound();
        $this->send('POST', '/cart/items', ['listing_id' => $secret, 'variant_id' => $variant, 'quantity' => '1']);
        $this->assertSame(0, $this->inside('mra', fn (): int => DB::table('cart_lines')->where('listing_id', $secret)->count()));

        // ランクの無い会員も見えない
        $taroId = $this->join('taro@example.com');
        $this->send('GET', "/products/{$secret}")->assertNotFound();

        // ゴールドになると見えて、カートに入れられる。サイトマップには会員が開いても載せない
        $this->inside('mra', fn () => app(Ranks::class)->setManually($taroId, 3, 'operator:boss'));
        $this->send('GET', "/products/{$secret}")->assertOk()->assertSee('ゴールドだけの財布')->assertSee('ゴールドの会員価格（5% 引き）');
        $this->send('GET', '/sitemap.xml')->assertOk()->assertDontSee($secret);
        $this->send('POST', '/cart/items', ['listing_id' => $secret, 'variant_id' => $variant, 'quantity' => '1']);
        $this->assertSame(1, $this->inside('mra', fn (): int => DB::table('cart_lines')->where('listing_id', $secret)->count()));

        // カートに入れた後にシルバーへ下がると、その行は買えない（注文の確定でも同じ答え）
        $this->inside('mra', function () use ($taroId, $secret): void {
            app(Ranks::class)->setManually($taroId, 2, 'operator:boss');
            $this->login(CustomerAccount::query()->findOrFail($taroId));
            $cart = Cart::query()->whereIn('id', DB::table('cart_lines')->where('listing_id', $secret)->select('cart_id'))->firstOrFail();
            $this->assertTrue(app(CartService::class)->view($cart)->hasUnavailable());

            // カートを通さない申し込み（定期便・チケット・予約の 1 品の注文）も、ショップのお客さんの申し込みなら断る。仕組みの注文（定期便の 2 回目から）は断らない
            $listing = Listing::query()->findOrFail($secret);
            $variant = ListingVariant::query()->where('listing_id', $secret)->firstOrFail();
            $account = CustomerAccount::query()->findOrFail($taroId);

            try {
                app(PlaceSingleOrder::class)->place($listing, $variant, 1, 0, '', $this->input('taro@example.com'), $account, $this->key(), PlaceOrder::ACTOR);
                $this->fail('見せない商品を 1 品の注文で買えた');
            } catch (OrderException $e) {
                $this->assertSame(PlaceSingleOrder::UNAVAILABLE, $e->getMessage());
            }

            $renewal = app(PlaceSingleOrder::class)->place($listing, $variant, 1, 0, '', $this->input('taro@example.com'), $account, $this->key(), 'system:subscriptions');
            $this->assertSame(9900 + 550, (int) $renewal->total_amount);
        });

        // App を止めると、会員だけ・ランクだけの商品は誰にも見せない（使うのをやめても誰にでも見せない）
        $this->inside('mra', function () use ($taroId): void {
            app(Ranks::class)->setManually($taroId, 3, 'operator:boss');
            app(PluginStates::class)->set('member-ranks', false, 'test');
        });
        $this->send('GET', "/products/{$secret}")->assertNotFound();
    }

    public function test_the_member_page_shows_the_rank_the_amount_and_the_way_to_the_next_rank(): void
    {
        $this->send('GET', '/p/member-ranks/account')->assertRedirect('http://mra.localhost/account/login?return=%2Fp%2Fmember-ranks%2Faccount');

        $taroId = $this->join('taro@example.com');
        $this->inside('mra', function () use ($taroId): void {
            $this->shippedOrder(CustomerAccount::query()->findOrFail($taroId), 3);
            app(Ranks::class)->run(CarbonImmutable::now());
        });

        // 13,200 円でブロンズ。シルバー（50,000 円）まで あと 36,800 円
        $this->send('GET', '/p/member-ranks/account')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('ブロンズ')->assertSee('¥13,200')->assertSee('あと ¥36,800 で「シルバー」')->assertSee('ポイント +0.5%');
        $this->send('GET', '/account')->assertOk()->assertSee('/p/member-ranks/account', false)->assertSee('会員ランク');
    }

    public function test_the_admin_record_page_of_a_member_opens_with_the_current_rank(): void
    {
        // 管理画面の会員の 1 件の画面（前は「今のランク」を大きく出す印つきの状態の形で出していて、核が文の形だけを許すので、どの会員でも 500 だった）
        $taroId = $this->join('taro@example.com');
        $this->inside('mra', function () use ($taroId): void {
            $this->shippedOrder(CustomerAccount::query()->findOrFail($taroId), 3);
            app(Ranks::class)->run(CarbonImmutable::now());
            $record = app(AdminPages::class)->record('member-ranks', $taroId);
            $rank = collect($record['facts'])->firstWhere('label', '今のランク');
            $this->assertNotNull($rank);
            $this->assertSame('status', $rank['type']);
        });
    }

    public function test_another_shop_does_not_see_the_ranks_of_this_shop(): void
    {
        $taro = $this->inside('mra', function (): CustomerAccount {
            $taro = $this->member('taro@example.com');
            app(Ranks::class)->setManually($taro->id, 3, 'operator:boss');

            return $taro;
        });

        $this->tenant('mrb', '別の店');
        $this->inside('mrb', function () use ($taro): void {
            app(PluginStates::class)->set('member-ranks', true, 'test');
            // 同じ台帳の ID でも、この店の DB には無い（ランクも会員価格も無い）
            $this->assertNull(app(MemberBenefits::class)->discountFor($taro->id));
            $this->assertSame(0, app(MemberBenefits::class)->pointBonusBp($taro->id));
            $this->assertNull(app(MemberBenefits::class)->badgeFor($taro->id));
            $this->assertSame(0, RankMember::query()->count());
        });
    }

    public function test_the_point_rate_with_the_bonus_stays_within_20_percent(): void
    {
        $this->inside('mra', function (): void {
            // 店の率 20% ＋ ゴールドの上乗せ 10% でも、注文に写す率は 20% まで（order_points の DB の決まりで注文が落ちない）
            app(PointSettings::class)->update(['enabled' => true, 'rate_bp' => 2000], 'test');
            $this->settings(['rank3_points' => '10']);
            $gold = $this->member('gold@example.com');
            app(Ranks::class)->setManually($gold->id, 3, 'operator:boss');
            $this->assertSame(2000, app(PointSettings::class)->rateBpFor($gold->id));
            $this->login($gold);
            $sale = $this->place($this->cart(1), 'gold@example.com');
            // 4,400 円 − 会員価格 5% = 4,180 円の 20%
            $this->assertSame([2000, 836], [OrderPoints::query()->findOrFail($sale->id)->rate_bp, OrderPoints::query()->findOrFail($sale->id)->earn]);
        });
    }

    public function test_members_only_photos_are_served_to_the_member_through_a_signed_link_without_a_session(): void
    {
        FakeMedia::fake();
        [$secret, $imageId] = $this->inside('mra', function (): array {
            $secret = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ゴールドだけの財布', 'unit_amount' => 9900, 'status' => 'active']);
            app(StockService::class)->receive($secret, Location::query()->firstOrFail(), 10);
            $path = (string) tempnam(sys_get_temp_dir(), 'mrp');
            file_put_contents($path, $this->jpeg(400, 300));
            $image = app(ListingImageService::class)->add($secret, $path);
            @unlink($path);
            (new RankListingPanel)->write($secret, ['visibility' => 'rank3'], 'operator:boss');

            return [$secret->id, $image->id];
        });

        // ゲスト: 署名の無い写真は見つからない
        $this->get("http://mra.localhost/media/listings/{$imageId}/medium")->assertNotFound();
        tenancy()->end();

        // ゴールドの会員の商品の画面には、署名つきの写真の URL が出る
        $taroId = $this->join('taro@example.com');
        $this->inside('mra', fn () => app(Ranks::class)->setManually($taroId, 3, 'operator:boss'));
        $html = (string) $this->send('GET', "/products/{$secret}")->assertOk()->getContent();
        $this->assertSame(1, preg_match('#/media/listings/'.$imageId.'/large\?member_expires=([0-9]+)&amp;member_token=([0-9a-f]{64})#', $html, $m));

        // 写真の経路はセッションを持たない（Cookie を送らない要求）。署名が合えば返し、共有のキャッシュに置かない
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $photo = $this->get("http://mra.localhost/media/listings/{$imageId}/medium?member_expires={$m[1]}&member_token={$m[2]}")->assertOk();
        $this->assertStringContainsString('no-store', (string) $photo->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $photo->headers->get('Cache-Control'));
        tenancy()->end();

        // 書き換えた署名・ほかの商品の署名・プレビューの鍵の署名・期限の過ぎた署名は断る
        $bad = str_repeat('0', 64);
        $this->get("http://mra.localhost/media/listings/{$imageId}/medium?member_expires={$m[1]}&member_token={$bad}")->assertNotFound();
        tenancy()->end();
        $other = MemberPhotoLink::query('mra', $this->knit);
        $this->get("http://mra.localhost/media/listings/{$imageId}/medium?member_expires={$other['member_expires']}&member_token={$other['member_token']}")->assertNotFound();
        tenancy()->end();
        $preview = ListingPreviewLink::query('mra', $secret, CarbonImmutable::now()->addMinutes(5)->getTimestamp());
        $this->get("http://mra.localhost/media/listings/{$imageId}/medium?member_expires={$preview['expires']}&member_token={$preview['token']}")->assertNotFound();
        tenancy()->end();
        $this->travel(3)->hours();
        $this->get("http://mra.localhost/media/listings/{$imageId}/medium?member_expires={$m[1]}&member_token={$m[2]}")->assertNotFound();
        tenancy()->end();
    }

    public function test_shop_side_processes_read_members_only_products_and_check_each_recipient(): void
    {
        $this->inside('mra', function (): void {
            $secret = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ゴールドだけの財布', 'unit_amount' => 9900, 'status' => 'active']);
            app(StockService::class)->receive($secret, Location::query()->firstOrFail(), 10);
            (new RankListingPanel)->write($secret, ['visibility' => 'rank3'], 'operator:boss');
            $variant = (string) ListingVariant::query()->where('listing_id', $secret->id)->value('id');

            // 店の内側の読み方（管理画面の「ショップで見る」・在庫の知らせ・入荷の見方）は見る人を問わない。ゲストの窓口には無い
            $this->assertFalse(app(Catalog::class)->isPublished($secret->id));
            $this->assertTrue(app(Catalog::class)->forShop()->isPublished($secret->id));
            $this->assertArrayHasKey($variant, app(Catalog::class)->forShop()->purchasableVariants([$variant]));
            $this->assertTrue(app(RestockTargets::class)->available($variant));

            // 再入荷のお知らせは、送る相手ごとに確かめる（ゲストの登録には送らずメールを消す、ゴールドの会員には送る）
            $gold = $this->member('gold@example.com');
            app(Ranks::class)->setManually($gold->id, 3, 'operator:boss');
            $guestRow = $this->restockRequest($secret->id, $variant, null, 'guest@example.com');
            $goldRow = $this->restockRequest($secret->id, $variant, $gold->id, 'gold@example.com');
            (new SendRestockMail($guestRow, 'restocked'))->handle();
            Mail::assertNotSent(RestockMail::class);
            $this->assertNull(RestockRequest::query()->findOrFail($guestRow)->email);
            (new SendRestockMail($goldRow, 'restocked'))->handle();
            Mail::assertSent(RestockMail::class, 1);

            // カゴ落ちのメールの商品も、その注文の会員に見せてよい時だけ（写真はメールでは開けないので載せない）
            $this->login($gold);
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, $variant, 1);
            $goldOrder = $this->place($cart, 'gold@example.com');
            $this->assertSame([['title' => 'ゴールドだけの財布', 'photo' => null]], app(ReminderItems::class)->forMail($goldOrder->id));
            app(Ranks::class)->setManually($gold->id, 2, 'operator:boss');
            $this->assertSame([], app(ReminderItems::class)->forMail($goldOrder->id));
        });
    }

    public function test_the_application_pages_of_single_orders_do_not_show_members_only_products(): void
    {
        $this->requiresApps('subscription');

        // 定期便の約束を作る所は SubscriptionPlanParts（許す一覧の Subscription* で公開の木に入らない）
        if (! class_exists(SubscriptionPlanParts::class)) {
            $this->missingPart('tests/Feature/Plugin/SubscriptionPlanParts.php');
        }

        $plans = new SubscriptionPlanParts;
        $secret = $this->inside('mra', function () use ($plans): string {
            app(PluginStates::class)->set('subscription', true, 'test');
            $secret = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ゴールドだけの定期便', 'unit_amount' => 3300, 'status' => 'active']);
            app(StockService::class)->receive($secret, Location::query()->firstOrFail(), 10);
            $plans->monthly($secret->id);
            (new RankListingPanel)->write($secret, ['visibility' => 'rank3'], 'operator:boss');

            return $secret->id;
        });

        // ゲストはログインへ（商品の名前を出さない）、ランクの足りない会員には「申し込めません」の画面で、名前を出さない
        $this->send('GET', "/p/subscription/{$secret}")->assertRedirect()->assertDontSee('ゴールドだけの定期便');
        $taroId = $this->join('taro@example.com');
        $this->send('GET', "/p/subscription/{$secret}")->assertDontSee('ゴールドだけの定期便');
        $this->inside('mra', fn () => app(Ranks::class)->setManually($taroId, 3, 'operator:boss'));
        $this->send('GET', "/p/subscription/{$secret}")->assertOk()->assertSee('ゴールドだけの定期便');
    }

    public function test_notices_after_the_first_night_are_paced_per_shop(): void
    {
        Queue::fake();
        $this->inside('mra', function (): void {
            $this->settings(['rank1_threshold' => '0']);
            // 最初の夜: ランクは付くが知らせない
            $this->member('first@example.com');
            app(Ranks::class)->run(CarbonImmutable::now());
            Queue::assertNothingPushed();

            // 次の夜に 61 人が上がると、60 人はすぐ、61 人目は 1 分後に並ぶ
            for ($i = 0; $i < 61; $i++) {
                $this->member("m{$i}@example.com");
            }

            $this->assertSame(['up' => 61, 'down' => 0], app(Ranks::class)->run(CarbonImmutable::now()));
            $jobs = Queue::pushed(SendRankNotice::class);
            $this->assertCount(61, $jobs);
            $later = $jobs->filter(static fn (SendRankNotice $job): bool => $job->delay instanceof \DateTimeInterface && CarbonImmutable::instance($job->delay)->gt(CarbonImmutable::now()->addSeconds(30)));
            $this->assertCount(1, $later);
        });
    }

    // --- 補助 ---

    private function prepare(): string
    {
        app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
        app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => 10000]);
        app(PointSettings::class)->update(['enabled' => true, 'rate_bp' => 100], 'test');
        app(PluginStates::class)->set('member-ranks', true, 'test');
        $knit = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
        app(StockService::class)->receive($knit, Location::query()->firstOrFail(), 200);

        return $knit->id;
    }

    private function restockRequest(string $listingId, string $variantId, ?string $accountId, string $email): string
    {
        $row = new RestockRequest;
        $row->forceFill(['listing_id' => $listingId, 'variant_id' => $variantId, 'customer_account_id' => $accountId, 'email' => $email,
            'token_hash' => hash('sha256', $email), 'mail_token' => null, 'status' => 'notified', 'confirmed_at' => now(), 'notified_at' => now()])->save();

        return $row->id;
    }

    /**
     * @param  array<string, string|bool>  $values
     */
    private function settings(array $values): void
    {
        app(PluginSettings::class)->update('member-ranks', $values, 'test');
    }

    private function member(string $email): CustomerAccount
    {
        $account = app(CustomerAccounts::class)->forEmail($email, 'test');
        CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);

        return CustomerAccount::query()->findOrFail($account->id);
    }

    private function login(CustomerAccount $account): void
    {
        Auth::guard('customer')->setUser(new Member($account, 'mra'));
    }

    private function cart(int $quantity): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->knit)->value('id'), $quantity);

        return $cart;
    }

    private function input(string $email): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => $email, 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null]);
        $this->assertNotNull($input);

        return $input;
    }

    private function key(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function place(Cart $cart, string $email): Transaction
    {
        $input = $this->input($email);
        $fingerprint = CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart, $input->destination(), $email, $input->pointsRequested()));

        return app(PlaceOrder::class)->place($cart, $input, $this->key(), $fingerprint);
    }

    /**
     * その会員のゲストの注文（ランクの値引きを効かせない。購入額を数えるため）
     */
    private function order(CustomerAccount $account, int $quantity): Transaction
    {
        Auth::forgetGuards();

        return $this->place($this->cart($quantity), $account->email);
    }

    private function shippedOrder(CustomerAccount $account, int $quantity): Transaction
    {
        $order = $this->order($account, $quantity);
        $this->pay($order);
        $this->shipAll($order);

        return $order;
    }

    /**
     * 払う（本番のモードの支払いとして。テストのモードの支払いは購入額に数えないため）
     */
    private function pay(Transaction $sale): void
    {
        app(PaymentService::class)->startCheckout($sale, 'x', 'y');
        $ref = PaymentAttempt::query()->where('transaction_id', $sale->id)->sole()->provider_ref;
        $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$sale->id, 'checkout.session.completed', $ref, $sale->id, (int) $sale->total_amount, 'jpy', 'pi_'.$sale->id));
        PaymentAttempt::query()->where('transaction_id', $sale->id)->update(['test_mode' => false]);
    }

    private function shipAll(Transaction $sale): void
    {
        foreach (Fulfillment::query()->where('transaction_id', $sale->id)->get() as $fulfillment) {
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale->id), $fulfillment->id, 'yamato', '1234', 'operator:test');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function sorted(array $payload): array
    {
        ksort($payload);

        return $payload;
    }

    /**
     * メールのコードで会員になる。会員の台帳の ID を返す
     */
    private function join(string $email): string
    {
        $count = count(Mail::sent(LoginCodeMail::class));
        $this->send('POST', '/account/login', ['email' => $email])->assertRedirect('http://mra.localhost/account/login/code');
        $mails = Mail::sent(LoginCodeMail::class);
        $this->assertCount($count + 1, $mails);
        $this->send('POST', '/account/login/code', ['code' => $mails->last()->code])->assertRedirect('http://mra.localhost/account');

        return $this->inside('mra', fn (): string => CustomerAccount::query()->whereBlind('email', $email)->sole()->id);
    }

    /**
     * 要求を送る。会員の印の Cookie を持ち回る（本番と同じく、要求ごとにログインの仕組みを作り直す）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        $this->defaultCookies = [];

        foreach ($this->cookies as $name => $value) {
            $this->withCookie($name, $value);
        }

        // 人の速さで（接続元ごとの回数の制限に、テスト自身が当たらないように）
        if ($method !== 'GET') {
            $this->travel(7)->seconds();
        }

        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://mra.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();

        $cookie = $response->getCookie(MemberCookie::NAME);

        if ($cookie !== null) {
            $value = (string) $cookie->getValue();

            if ($value === '') {
                unset($this->cookies[MemberCookie::NAME]);
            } else {
                $this->cookies[MemberCookie::NAME] = $value;
            }
        }

        return $response;
    }
}
