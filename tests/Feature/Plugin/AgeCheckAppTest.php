<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Http\Presenters\TransactionPresenter;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\AgeChecks;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerAddress;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\AgeCheck\AgeAnswer;
use App\Core\Plugin\AgeCheck\AgeCheckRefused;
use App\Core\Plugin\AgeCheck\AgeRule;
use App\Core\Plugin\Contracts\AgeCheckHandler;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Checkout\PlaceSingleOrder;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use Carbon\CarbonImmutable;
use EcSugar\PluginAgeCheck\AgeCheckListingPanel;
use EcSugar\PluginAgeCheck\AgeCheckListings;
use EcSugar\PluginAgeCheck\AgeCheckRules;
use EcSugar\PluginAgeCheck\Models\AgeCheckMember;
use EcSugar\PluginOrderPrint\OrderSheet;
use EcSugar\PluginShippingCsv\CsvFormat;
use EcSugar\PluginShippingCsv\Parcels;
use EcSugar\PluginShippingCsv\Shipper;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「年齢確認」（ADR 0077）: 年齢の確かめが要る商品は、商品の画面に一言を出し、注文の入力で生年月日と同意を受けて満年齢をサーバーで数え、
 * 足りなければ確定しない（錠の中でも照らし直す）。生年月日はどこにも残さず、注文には確かめた事実だけ。会員は確かめた年齢を覚え、次から生年月日を聞かない
 */
final class AgeCheckAppTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    /** 日本の 11 月 5 日のちょうど 20 年前に生まれた人の生年月日 */
    private const string BIRTHDAY = '2006-11-05';

    private ?string $cart = null;

    private ?string $member = null;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        FakeStripe::install();
        // 日本の 2026-11-05 の 0 時 10 分（UTC ではまだ 11 月 4 日）
        $this->travelTo(new CarbonImmutable('2026-11-05 00:10:00', 'Asia/Tokyo'));
        $this->tenant('agc', 'お酒の店');
        $this->inside('agc', function (): void {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(ShopSettingsService::class)->setShipping(['fee' => 800, 'free_over' => null], 'test');
            app(PluginStates::class)->set('age-check', true, 'test');
        });
        Event::listen(MessageLogged::class, function (MessageLogged $log): void {
            $this->logs[] = $log->message.' '.json_encode($log->context);
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_shop_without_the_app_asks_nothing(): void
    {
        $sake = $this->listing('純米酒', 2400);
        $this->requireAge($sake, 20);
        $this->inside('agc', fn () => app(PluginStates::class)->set('age-check', false, 'test'));

        $this->assertStringNotContainsString('data-test="age-notice"', $this->page("/products/{$sake}"));
        $this->send('POST', '/cart/items', ['listing_id' => $sake, 'quantity' => '1'])->assertRedirect();
        $this->assertStringNotContainsString('data-test="age-check"', $this->page('/checkout'));
        $this->checkout()->assertRedirect('http://agc.localhost/checkout/confirm');
        $this->confirmAndPlace()->assertStatus(303);
        $this->inside('agc', fn () => $this->assertSame(0, DomainEvent::query()->where('type', AgeChecks::VERIFIED)->count()));
        // 止めたら確かめないことは、App の画面の説明（「この店では」の欄）で前もって知らせる
        $this->assertStringContainsString('「使わない」にすると、年齢確認が必要な商品も確認なしで販売されます', app(PluginRegistry::class)->get('age-check')->description);
    }

    public function test_a_guest_order_counts_the_age_on_the_server_and_keeps_only_the_fact(): void
    {
        $sake = $this->listing('純米酒', 2400);
        $this->requireAge($sake, 20);

        // どのテーマの商品の画面にも「20 歳未満の方はお求めいただけません」
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->inside('agc', fn () => Tenant::query()->findOrFail('agc')->forceFill(['theme' => $theme])->save());
            $this->assertStringContainsString('20 歳未満の方はお求めいただけません。', $this->page("/products/{$sake}"), $theme);
            $this->send('POST', '/cart/items', ['listing_id' => $sake, 'quantity' => '1'])->assertRedirect();
            $form = $this->page('/checkout');
            $this->assertStringContainsString('data-test="age-birthdate"', $form, $theme);
            $this->assertStringContainsString('私は 20 歳以上です', $form, $theme);
            $this->cart = null;
        }
        $this->send('POST', '/cart/items', ['listing_id' => $sake, 'quantity' => '1'])->assertRedirect();

        // 生年月日も同意も無い・同意だけ: 理由を欄の下に
        $this->checkout([])->assertRedirect('http://agc.localhost/checkout')->assertSessionHasErrors(['age.birthdate', 'age.consent']);
        // 日本の明日が 20 歳の誕生日の人は断る。入れた生年月日は入れ直しの値としてセッションに戻さない
        $this->checkout(['birthdate' => '2006-11-06', 'consent' => '1'])->assertSessionHasErrors(['age.birthdate' => '20 歳未満の方は、この商品をお求めいただけません。']);
        $this->assertStringNotContainsString('2006-11-06', (string) json_encode(session()->all()));
        // 誤りは欄の下に出て、生年月日の欄は空のまま（セッションを確かめた後の画面は、テストの都合でもう一度送ってから開く）
        $this->checkout(['birthdate' => '2006-11-06', 'consent' => '1']);
        $retry = $this->page('/checkout');
        $this->assertStringContainsString('20 歳未満の方は、この商品をお求めいただけません。', $retry);
        $this->assertStringNotContainsString('2006-11-06', $retry);
        // 形の違う・未来の日・あり得ない年
        $this->checkout(['birthdate' => '2006/11/05', 'consent' => '1'])->assertSessionHasErrors('age.birthdate');
        $this->checkout(['birthdate' => '2026-11-06', 'consent' => '1'])->assertSessionHasErrors('age.birthdate');
        $this->checkout(['birthdate' => '1800-01-01', 'consent' => '1'])->assertSessionHasErrors('age.birthdate');

        // 日本の今日がちょうど 20 歳の誕生日（UTC ではまだ前の日なので、サーバーの時計で数えると 19 歳に見える人）: 通る。セッションには答えだけ（生年月日は無い）
        $this->checkout(['birthdate' => self::BIRTHDAY, 'consent' => '1'])->assertRedirect('http://agc.localhost/checkout/confirm');
        $this->assertSame(['age' => 20, 'level' => 20, 'consent' => true, 'recipient' => false, 'version' => AgeRule::CONSENT_VERSION], session('checkout.age'));
        $this->assertStringNotContainsString(self::BIRTHDAY, (string) json_encode(session()->all()));
        // 確認の画面から入力の画面へ戻って直しても、生年月日を入れ直させない（このセッションの答えを引き継ぐ）
        $this->assertStringContainsString('data-test="age-remembered"', $this->page('/checkout'));
        $this->checkout(['consent' => '1'])->assertRedirect('http://agc.localhost/checkout/confirm');
        $this->assertSame(20, session('checkout.age.level'));
        $this->assertStringContainsString('20 歳以上であることを確認しました', $this->page('/checkout/confirm'));
        $this->confirmAndPlace()->assertStatus(303);

        $order = $this->inside('agc', function (): string {
            $order = Transaction::query()->sole();
            $event = DomainEvent::query()->where('type', AgeChecks::VERIFIED)->where('aggregate_id', $order->id)->sole();
            $this->assertEquals(['age' => 20, 'via' => 'birthdate', 'consent' => AgeRule::CONSENT_VERSION, 'recipient' => false, 'remembered' => false], $event->payload);
            $this->assertSame('storefront', $event->actor);
            // ゲストは覚えない
            $this->assertSame(0, AgeCheckMember::query()->count());

            return $order->id;
        });
        $this->assertBirthdateNowhere(self::BIRTHDAY);
        $this->assertBirthdateNowhere('2006-11-06');

        // 納品書・配送の CSV・管理画面の注文に「年齢確認済み（20 歳以上）」
        $this->pay($order);
        $this->inside('agc', function () use ($order): void {
            $this->assertSame('年齢確認済み（20 歳以上）', OrderSheet::of(Transaction::query()->findOrFail($order), true)->remark);
            [$parcels] = Parcels::pick('all', []);
            $this->assertCount(1, $parcels);
            $this->assertSame('年齢確認済20歳以上', $parcels[0]->remark());
            $csv = CsvFormat::YamatoB2->csv($parcels, new Shipper('お酒の店', '150-0001', '東京都渋谷区', '0312345678'), [], CarbonImmutable::now('Asia/Tokyo'));
            $this->assertSame('年齢確認済20歳以上', $csv->rows[0][32]);
            $this->assertSame('年齢確認済20歳以上', CsvFormat::JapanPostYupri->csv($parcels, new Shipper('お酒の店', '150-0001', '東京都渋谷区', '0312345678'), [], CarbonImmutable::now('Asia/Tokyo'))->rows[0][26]);
            $presented = app(TransactionPresenter::class)->present(Transaction::query()->findOrFail($order));
            $this->assertSame(['age' => 20, 'recipient' => false, 'label' => '年齢確認済み（20 歳以上）'], $presented['age_check']);
            // App を使うのをやめても、注文の記録は残る
            app(PluginStates::class)->set('age-check', false, 'test');
            $this->assertSame('年齢確認済み（20 歳以上）', app(AgeChecks::class)->verifiedFor([$order])[$order]['label']);
        });
    }

    public function test_items_added_after_the_input_and_a_later_requirement_are_refused_in_the_lock(): void
    {
        $water = $this->listing('炭酸水', 300);
        $sake = $this->listing('純米酒', 2400);
        $this->requireAge($sake, 20);

        // 年齢の要らない品だけで入力した後に、年齢の要る品をカートへ足した: 確かめの画面は入力の画面へ戻して聞き直す
        $this->send('POST', '/cart/items', ['listing_id' => $water, 'quantity' => '1'])->assertRedirect();
        $this->checkout()->assertRedirect('http://agc.localhost/checkout/confirm');
        $confirm = $this->page('/checkout/confirm');
        $this->send('POST', '/cart/items', ['listing_id' => $sake, 'quantity' => '1'])->assertRedirect();
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://agc.localhost/checkout')->assertSessionHasErrors('age.consent');
        // 前の確かめの画面の合い言葉で確定へ直に送っても、注文は作らない
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertRedirect();
        $this->inside('agc', fn () => $this->assertSame(0, Transaction::query()->count()));

        // 確かめの画面を見せた後に、店が品を年齢の要るものにした（内容の指紋は同じ）: 錠の中で断る
        $this->cart = null;
        $beer = $this->listing('クラフトビール', 600);
        $this->send('POST', '/cart/items', ['listing_id' => $beer, 'quantity' => '1'])->assertRedirect();
        $this->checkout()->assertRedirect('http://agc.localhost/checkout/confirm');
        $confirm = $this->page('/checkout/confirm');
        $this->requireAge($beer, 20);
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $this->send('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])
            ->assertRedirect('http://agc.localhost/checkout/confirm')->assertSessionHas('cart_error', '20 歳以上の方だけがお求めいただける商品があります。生年月日を入力し、「20 歳以上です」にチェックを入れてから、もう一度お進みください。');
        $this->inside('agc', fn () => $this->assertSame(0, Transaction::query()->count()));

        // 確定の口を直に呼んでも、答えの無い・足りない注文は作らない（18 歳で確かめた答えで 20 歳の品）
        $this->inside('agc', function () use ($sake): void {
            $cart = $this->storeCart($sake);
            foreach ([null, new AgeAnswer(18, 18, true, false), new AgeAnswer(20, 18, true, false), new AgeAnswer(20, 20, false, false)] as $answer) {
                try {
                    $this->placeDirect($cart, $answer);
                    $this->fail('年齢の確認の足りない注文ができた');
                } catch (OrderException $e) {
                    $this->assertStringContainsString('20 歳以上の方だけがお求めいただける商品があります。', $e->getMessage());
                }
            }
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, DomainEvent::query()->where('type', AgeChecks::VERIFIED)->count());

            $this->placeDirect($cart, new AgeAnswer(20, 20, true, false));
            $this->assertSame(1, DomainEvent::query()->where('type', AgeChecks::VERIFIED)->count());
        });
    }

    public function test_a_member_is_remembered_and_not_asked_for_the_birthdate_again(): void
    {
        $sake = $this->listing('純米酒', 2400);
        $wine = $this->listing('赤ワイン', 3000);
        $this->requireAge($sake, 20);
        $this->requireAge($wine, 20);
        $this->signIn('hanako@example.com');

        $this->send('POST', '/cart/items', ['listing_id' => $sake, 'quantity' => '1'])->assertRedirect();
        $this->checkout(['birthdate' => '1990-04-01', 'consent' => '1'])->assertRedirect('http://agc.localhost/checkout/confirm');
        $this->confirmAndPlace()->assertStatus(303);
        $account = $this->inside('agc', function (): string {
            $member = AgeCheckMember::query()->sole();
            $this->assertSame(20, $member->age);
            $this->assertTrue(DomainEvent::query()->where('type', AgeChecks::VERIFIED)->sole()->payload['remembered']);

            return $member->customer_account_id;
        });

        // 次の注文: 生年月日は聞かず、同意だけ
        $this->send('POST', '/cart/items', ['listing_id' => $wine, 'quantity' => '1'])->assertRedirect();
        $form = $this->page('/checkout');
        $this->assertStringNotContainsString('data-test="age-birthdate"', $form);
        $this->assertStringContainsString('data-test="age-remembered"', $form);
        $this->checkout(['consent' => '0'])->assertSessionHasErrors('age.consent')->assertSessionDoesntHaveErrors('age.birthdate');
        $this->checkout(['consent' => '1'])->assertRedirect('http://agc.localhost/checkout/confirm');
        $this->confirmAndPlace()->assertStatus(303);
        $this->inside('agc', function (): void {
            $last = DomainEvent::query()->where('type', AgeChecks::VERIFIED)->orderByDesc('sequence')->firstOrFail();
            $this->assertEquals(['age' => 20, 'via' => 'member', 'consent' => AgeRule::CONSENT_VERSION, 'recipient' => false, 'remembered' => false], $last->payload);
        });

        // 会員のメールを打ったゲスト（ログインしていない）には使わない
        $this->member = null;
        $this->cart = null;
        $this->send('POST', '/cart/items', ['listing_id' => $wine, 'quantity' => '1'])->assertRedirect();
        $this->assertStringContainsString('data-test="age-birthdate"', $this->page('/checkout'));
        $this->checkout(['consent' => '1'])->assertSessionHasErrors('age.birthdate');

        // 退会したら覚えた年齢を消す
        $this->inside('agc', function () use ($account): void {
            app(CustomerAccounts::class)->leave(CustomerAccount::query()->findOrFail($account), 'test');
            $this->assertSame(0, AgeCheckMember::query()->count());
        });
        $this->assertBirthdateNowhere('1990-04-01');
    }

    public function test_another_shop_does_not_share_the_rules_or_the_memory(): void
    {
        $sake = $this->listing('純米酒', 2400);
        $this->requireAge($sake, 20);
        $this->signIn('hanako@example.com');
        $this->send('POST', '/cart/items', ['listing_id' => $sake, 'quantity' => '1'])->assertRedirect();
        $this->checkout(['birthdate' => '1990-04-01', 'consent' => '1'])->assertRedirect('http://agc.localhost/checkout/confirm');
        $this->confirmAndPlace()->assertStatus(303);

        // 別の店: 同じメールの会員でも、前の店の覚えは見えない（店の DB が別）。年齢の決まりも、その店で決めた商品だけ
        $this->inside('agc', fn () => $this->assertSame(1, AgeCheckMember::query()->count()));
        $this->tenant('agd', '別の店');
        $other = $this->inside('agd', function () use ($sake): string {
            app(PluginStates::class)->set('age-check', true, 'test');
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '純米酒', 'unit_amount' => 2400, 'status' => 'active']);
            app(AgeCheckListings::class)->save($listing, true, 20, 'test');
            $this->assertSame(0, AgeCheckMember::query()->count());
            $this->assertSame(0, app(AgeChecks::class)->required([$sake]));

            return $listing->id;
        });
        $this->inside('agd', function () use ($other): void {
            $account = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'test');
            $this->assertSame(0, app(AgeChecks::class)->remembered($account->id));
            $this->assertSame(20, app(AgeChecks::class)->required([$other]));
        });
    }

    public function test_a_gift_needs_the_recipient_consent_too(): void
    {
        $sake = $this->listing('純米酒', 2400);
        $this->requireAge($sake, 20);
        $this->inside('agc', fn () => app(PluginStates::class)->set('gift', true, 'test'));
        $this->send('POST', '/cart/items', ['listing_id' => $sake, 'quantity' => '1'])->assertRedirect();
        $this->assertStringContainsString('贈り物を受け取る方も 20 歳以上です。', $this->page('/checkout'));

        $gift = ['gift' => ['on' => '1', 'sender' => 'はなこ', 'message' => '']];
        $this->checkout(['birthdate' => '1990-04-01', 'consent' => '1'], $gift)->assertSessionHasErrors('age.recipient');
        $this->checkout(['birthdate' => '1990-04-01', 'consent' => '1', 'recipient' => '1'], $gift)->assertRedirect('http://agc.localhost/checkout/confirm');
        $this->assertStringContainsString('（贈り物を受け取る方も含む）', $this->page('/checkout/confirm'));
        $this->confirmAndPlace()->assertStatus(303);
        $this->inside('agc', fn () => $this->assertTrue(DomainEvent::query()->where('type', AgeChecks::VERIFIED)->sole()->payload['recipient']));
    }

    public function test_one_item_orders_check_the_answer_or_the_members_memory(): void
    {
        $sake = $this->listing('純米酒', 2400, stock: 10);
        $this->requireAge($sake, 20);

        $this->inside('agc', function () use ($sake): void {
            $listing = Listing::query()->findOrFail($sake);
            $variant = ListingVariant::query()->where('listing_id', $sake)->firstOrFail();
            $account = app(CustomerAccounts::class)->forEmail('taro@example.com', 'test');
            $input = CheckoutInput::fromArray(['buyer' => [...self::buyer(), 'email' => 'taro@example.com'], 'recipient' => null]);
            $this->assertNotNull($input);
            $orders = app(PlaceSingleOrder::class);
            $key = static fn (): string => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

            // 答えも覚えも無い（定期便の回など）: 断る
            foreach (['place' => fn () => $orders->place($listing, $variant, 1, 0, '', $input, $account, $key(), 'test'),
                'digital' => fn () => $orders->placeDigital($listing, $variant, 1, '山田 太郎', $account, $key(), 'test')] as $path => $place) {
                try {
                    $place();
                    $this->fail("{$path}: 年齢を確認せずに注文できた");
                } catch (OrderException $e) {
                    $this->assertStringContainsString('20 歳以上の方だけ', $e->getMessage(), $path);
                }
            }

            // 申し込みの画面で受けた答えで通り、会員の年齢を覚える
            $orders->place($listing, $variant, 1, 0, '', $input, $account, $key(), 'test', age: new AgeAnswer(20, 20, true, false));
            $this->assertSame(20, app(AgeCheckRules::class)->remembered($account->id));
            // 次の回は答え無しでも、覚えた年齢で通る
            $orders->place($listing, $variant, 1, 0, '', $input, $account, $key(), 'system:subscription');
            $this->assertSame(['birthdate', 'member'], DomainEvent::query()->where('type', AgeChecks::VERIFIED)->orderBy('sequence')->get()->map(static fn (DomainEvent $e): string => $e->payload['via'])->all());
            // 店が品を 18 歳に下げても、覚えた 20 歳は下がらない
            app(AgeCheckListings::class)->save($listing, true, 18, 'test');
            $orders->place($listing, $variant, 1, 0, '', $input, $account, $key(), 'test', age: new AgeAnswer(18, 18, true, false));
            $this->assertSame(20, app(AgeCheckRules::class)->remembered($account->id));
        });
    }

    public function test_the_listing_panel_saves_the_age_with_an_event(): void
    {
        $sake = $this->listing('純米酒', 2400);

        $this->inside('agc', function () use ($sake): void {
            $panel = new AgeCheckListingPanel;
            $listing = Listing::query()->findOrFail($sake);
            $this->assertSame(['required' => false, 'age' => 'age20'], $panel->read($listing));
            $panel->write($listing, ['required' => true, 'age' => 'age18'], 'operator:test');
            $this->assertSame(['required' => true, 'age' => 'age18'], $panel->read($listing));
            $this->assertSame(18, app(AgeChecks::class)->forListing($sake));
            // 同じ値の保存は記録に残さない。外すと消える
            $panel->write($listing, ['required' => true, 'age' => 'age18'], 'operator:test');
            $panel->write($listing, ['required' => false, 'age' => 'age18'], 'operator:test');
            $this->assertNull(app(AgeChecks::class)->forListing($sake));
            $this->assertSame(['age_check.listing_set', 'age_check.listing_cleared'], DomainEvent::query()->where('aggregate_id', $sake)->where('type', 'like', 'age_check.%')->orderBy('sequence')->pluck('type')->all());
            // DB も選べる年齢だけ
            $this->expectException(QueryException::class);
            DB::table('age_check_listings')->insert(['listing_id' => $sake, 'age' => 21, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function test_the_age_gate_is_off_by_default_and_keeps_only_a_cookie(): void
    {
        $sake = $this->listing('純米酒', 2400);
        $this->requireAge($sake, 20);
        $water = $this->listing('炭酸水', 300);

        // 既定では出さない
        $this->send('GET', "/products/{$sake}")->assertOk();

        // 設定の説明に、クローラーと商品フィードの着地も門へ送られることを書いてある
        $this->assertStringContainsString('検索エンジンのクローラーや商品フィード', (string) app(PluginRegistry::class)->get('age-check')->app()->setting('gate')?->help);
        $this->inside('agc', fn () => app(PluginSettings::class)->update('age-check', ['gate' => true], 'test'));
        $this->send('GET', "/products/{$water}")->assertOk();
        $this->send('GET', "/products/{$sake}")->assertRedirect("http://agc.localhost/p/age-check/gate?age=20&return=%2Fproducts%2F{$sake}");
        $this->send('GET', "/p/age-check/gate?age=20&return=/products/{$sake}")->assertOk()->assertSee('20 歳以上ですか')->assertHeader('X-Robots-Tag');
        // 「いいえ」: お求めいただけない旨。Cookie は付けない
        $no = $this->send('POST', '/p/age-check/gate', ['age' => '20', 'return' => "/products/{$sake}", 'answer' => 'no'])->assertOk()->assertSee('お求めいただけません');
        $this->assertNull($no->getCookie(AgeCheckRules::GATE_COOKIE));
        // 外へ送る戻り先は使わない
        $this->send('POST', '/p/age-check/gate', ['age' => '20', 'return' => '//evil.example/x', 'answer' => 'yes'])->assertRedirect('http://agc.localhost');
        // 「はい」: Cookie だけを置いて商品の画面へ。次からは門を出さない。記録は残さない
        $yes = $this->send('POST', '/p/age-check/gate', ['age' => '20', 'return' => "/products/{$sake}", 'answer' => 'yes'])->assertRedirect("http://agc.localhost/products/{$sake}");
        $cookie = $yes->getCookie(AgeCheckRules::GATE_COOKIE);
        $this->assertNotNull($cookie);
        $this->withCookie(AgeCheckRules::GATE_COOKIE, (string) $cookie->getValue());
        $this->send('GET', "/products/{$sake}")->assertOk()->assertSee('20 歳未満の方はお求めいただけません。');
        $this->inside('agc', fn () => $this->assertSame(0, DomainEvent::query()->where('type', 'like', 'age_check.gate%')->count()));
    }

    public function test_the_subscription_signup_checks_the_age_too(): void
    {
        $this->requiresApps('subscription');

        // 定期便の約束を作る所は SubscriptionPlanParts（許す一覧の Subscription* で公開の木に入らない）
        if (! class_exists(SubscriptionPlanParts::class)) {
            $this->missingPart('tests/Feature/Plugin/SubscriptionPlanParts.php');
        }

        $plans = new SubscriptionPlanParts;
        $sake = $this->listing('純米酒の頒布会', 2400, stock: 10);
        $this->requireAge($sake, 20);
        $this->inside('agc', function () use ($sake, $plans): void {
            app(PluginStates::class)->set('subscription', true, 'test');
            $plans->monthly($sake);
        });
        $this->signIn('hanako@example.com');
        $this->send('POST', '/account/addresses', ['address' => self::address()])->assertRedirect();
        [$variant, $address] = $this->inside('agc', fn (): array => [ListingVariant::query()->where('listing_id', $sake)->value('id'), CustomerAddress::query()->value('id')]);

        $form = $this->page("/p/subscription/{$sake}");
        $this->assertStringContainsString('data-test="age-birthdate"', $form);
        $data = ['variant_id' => $variant, 'quantity' => '1', 'interval' => 'monthly', 'address_id' => $address];
        $this->send('POST', "/p/subscription/{$sake}", $data)->assertSessionHasErrors(['age.birthdate', 'age.consent']);
        $this->send('POST', "/p/subscription/{$sake}", [...$data, 'age' => ['birthdate' => '2006-11-06', 'consent' => '1']])->assertSessionHasErrors('age.birthdate');
        // 種類が見つからない（年齢の答えは通った後の戻し）: 生年月日を入れ直しの値としてセッションに残さない
        $this->send('POST', "/p/subscription/{$sake}", [...$data, 'variant_id' => strtolower((string) Str::ulid()), 'age' => ['birthdate' => '1990-04-01', 'consent' => '1']])
            ->assertRedirect("http://agc.localhost/p/subscription/{$sake}")->assertSessionHas('cart_error', '種類かお届け先を選び直してください。');
        $this->assertSame('1', session('_old_input.age.consent'));
        $this->assertBirthdateNowhere('1990-04-01');
        $this->send('POST', "/p/subscription/{$sake}", [...$data, 'age' => ['birthdate' => self::BIRTHDAY, 'consent' => '1']])->assertRedirect("http://agc.localhost/p/subscription/{$sake}/confirm");
        $this->assertStringContainsString('20 歳以上であることを確認しました', $this->page("/p/subscription/{$sake}/confirm"));
        $this->send('POST', "/p/subscription/{$sake}/place")->assertRedirect();

        $this->inside('agc', function (): void {
            $event = DomainEvent::query()->where('type', AgeChecks::VERIFIED)->sole();
            $this->assertEquals(['age' => 20, 'via' => 'birthdate', 'consent' => AgeRule::CONSENT_VERSION, 'recipient' => false, 'remembered' => true], $event->payload);
            $this->assertSame(20, AgeCheckMember::query()->sole()->age);
        });
        $this->assertBirthdateNowhere(self::BIRTHDAY);
    }

    public function test_listing_ids_are_compared_in_lower_case_and_bad_ids_are_refused(): void
    {
        $sake = $this->listing('純米酒', 2400);
        $this->requireAge($sake, 20);

        $this->inside('agc', function () use ($sake): void {
            $ages = app(AgeChecks::class);
            $this->assertSame(20, $ages->required([strtoupper($sake)]));
            // 形の違う ID を黙って捨てない（確かめずに売る道にしない）
            foreach (['', 'not-a-listing', $sake.'x', "{$sake}\n"] as $bad) {
                try {
                    $ages->required([$sake, $bad]);
                    $this->fail('形の違う ID を通した: '.$bad);
                } catch (AgeCheckRefused $e) {
                    $this->assertSame(AgeChecks::UNAVAILABLE, $e->getMessage());
                }
            }
            // 商品の画面は出さない側に倒す
            $this->assertNull($ages->forListing('not-a-listing'));
        });
    }

    public function test_a_failing_app_refuses_the_order_and_does_not_break_the_transaction(): void
    {
        $sake = $this->listing('純米酒', 2400);
        $this->requireAge($sake, 20);

        $this->inside('agc', function () use ($sake): void {
            // 品の年齢が読めない（App の DB の読み出しが落ちる）: 確かめずに売らない
            $this->withFailingApp(failRequirements: true, failRemembered: false);
            $cart = $this->storeCart($sake);
            try {
                $this->placeDirect($cart, new AgeAnswer(20, 20, true, false));
                $this->fail('年齢の決まりを読めないのに注文ができた');
            } catch (OrderException $e) {
                $this->assertSame(AgeChecks::UNAVAILABLE, $e->getMessage());
            }
            $this->assertSame(0, Transaction::query()->count());

            // 会員の覚えが読めない（錠の中で App の DB の読み出しが落ちる）: 外側のトランザクションを中断させず、生年月日の答えで確定する
            $this->withFailingApp(failRequirements: false, failRemembered: true);
            $account = app(CustomerAccounts::class)->forEmail('taro@example.com', 'test');
            $listing = Listing::query()->findOrFail($sake);
            $variant = ListingVariant::query()->where('listing_id', $sake)->firstOrFail();
            $input = CheckoutInput::fromArray(['buyer' => [...self::buyer(), 'email' => 'taro@example.com'], 'recipient' => null]);
            $this->assertNotNull($input);
            $order = app(PlaceSingleOrder::class)->place($listing, $variant, 1, 0, '', $input, $account, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'test', age: new AgeAnswer(20, 20, true, false));
            $this->assertSame('birthdate', DomainEvent::query()->where('type', AgeChecks::VERIFIED)->where('aggregate_id', $order->id)->sole()->payload['via']);
            // 答えの無い回は、覚えが読めないので断る
            try {
                app(PlaceSingleOrder::class)->place($listing, $variant, 1, 0, '', $input, $account, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'system:subscription');
                $this->fail('覚えが読めないのに答えの無い注文ができた');
            } catch (OrderException $e) {
                $this->assertStringContainsString('20 歳以上の方だけ', $e->getMessage());
            }
            $this->assertSame(1, Transaction::query()->count());
        });
    }

    /**
     * 落ちる App の口に差し替える（App の表の DB の読み出しが落ちるのをまねる。登録簿の名前と店の「使う」はそのまま）
     */
    private function withFailingApp(bool $failRequirements, bool $failRemembered): void
    {
        $handler = new class($failRequirements, $failRemembered) implements AgeCheckHandler
        {
            public function __construct(private readonly bool $failRequirements, private readonly bool $failRemembered) {}

            public function requirements(array $listingIds): array
            {
                if ($this->failRequirements) {
                    DB::select('SELECT * FROM age_check_no_such_table');
                }

                return (new AgeCheckRules)->requirements($listingIds);
            }

            public function remembered(string $customerAccountId): int
            {
                if ($this->failRemembered) {
                    DB::select('SELECT * FROM age_check_no_such_table');
                }

                return 0;
            }

            public function remember(string $customerAccountId, int $age, string $actor): void {}

            public function gate(int $age, string $returnPath, Request $request): ?string
            {
                return null;
            }
        };
        $registry = new PluginRegistry;
        $registry->register(new PluginDefinition(name: 'age-check', title: '年齢確認', description: '', pluginApi: PluginRegistry::API_VERSION, ageCheck: $handler));
        $this->app->instance(AgeChecks::class, new AgeChecks($registry, app(PluginStates::class), app(EventStore::class)));
    }

    /**
     * 生年月日が店の DB のどの表の行にも、セッションにも、ログにも無い
     */
    private function assertBirthdateNowhere(string $birthdate): void
    {
        $this->inside('agc', function () use ($birthdate): void {
            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                foreach (DB::select("SELECT to_jsonb(t)::text AS row FROM \"{$table}\" t") as $row) {
                    $this->assertStringNotContainsString($birthdate, $row->row, "表 {$table} に生年月日が残っている");
                }
            }
            $this->assertNotContains('birthdate', [...Schema::getColumnListing('age_check_members'), ...Schema::getColumnListing('age_check_listings')]);
        });
        $this->assertStringNotContainsString($birthdate, (string) json_encode(session()->all()));
        $this->assertStringNotContainsString($birthdate, implode("\n", $this->logs));
    }

    private function requireAge(string $listing, int $age): void
    {
        $this->inside('agc', fn () => app(AgeCheckListings::class)->save(Listing::query()->findOrFail($listing), true, $age, 'operator:test'));
    }

    private function listing(string $title, int $amount, int $stock = 5): string
    {
        return $this->inside('agc', function () use ($title, $amount, $stock): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->orderBy('created_at')->firstOrFail(), $stock);

            return $listing->id;
        });
    }

    private function storeCart(string $listing): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $listing)->value('id'), 1);

        return $cart;
    }

    private function placeDirect(Cart $cart, ?AgeAnswer $answer): Transaction
    {
        $input = CheckoutInput::fromArray(['buyer' => self::buyer(), 'recipient' => null]);
        $this->assertNotNull($input);

        return app(PlaceOrder::class)->place($cart, $input->withAge($answer), rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart)));
    }

    /**
     * @param  array<string, string>|null  $age
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function checkout(?array $age = null, array $extra = []): TestResponse
    {
        return $this->send('POST', '/checkout', ['buyer' => self::buyer(), 'same_as_buyer' => '1', ...($age === null ? [] : ['age' => $age]), ...$extra]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function confirmAndPlace(): TestResponse
    {
        $confirm = $this->page('/checkout/confirm');
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);

        return $this->send('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]]);
    }

    private function signIn(string $email): void
    {
        $this->send('POST', '/account/login', ['email' => $email]);
        $this->send('POST', '/account/login/code', ['code' => Mail::sent(LoginCodeMail::class)->last()->code]);
        $this->assertNotNull($this->member);
    }

    private function pay(string $order): void
    {
        $this->inside('agc', function () use ($order): void {
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
        return ['email' => 'hanako@example.com', ...self::address()];
    }

    /**
     * @return array<string, string>
     */
    private static function address(): array
    {
        return ['name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''];
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
        } else {
            unset($this->defaultCookies[CartCookie::NAME]);
        }

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        } else {
            unset($this->defaultCookies[MemberCookie::NAME]);
        }

        if ($method !== 'GET') {
            $this->travel(7)->seconds();
        }

        $this->app['auth']->forgetGuards();
        $url = "http://agc.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $this->cart = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->cart;
        $member = $response->getCookie(MemberCookie::NAME);

        if ($member !== null) {
            $this->member = (string) $member->getValue() === '' ? null : (string) $member->getValue();
        }

        return $response;
    }
}
