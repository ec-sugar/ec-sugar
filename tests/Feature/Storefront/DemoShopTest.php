<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Admin\Demo\DemoShopBuilder;
use App\Admin\Demo\DemoShops;
use App\Core\Crypto\BlindIndex;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\DirectPayments;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerLoginCode;
use App\Core\Party\Models\CustomerSession;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\Models\StoredPaymentMethod;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\StoredPayments;
use App\Core\Plugin\Models\PluginState;
use App\Core\Plugin\PluginRefusedInDemo;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Sample\Demo\DemoMails;
use App\Core\Sample\Demo\DemoOrders;
use App\Core\Sample\Demo\DemoShop;
use App\Core\Sample\Demo\DemoVisitors;
use App\Core\Sample\Models\DemoMail;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Members\MemberCookie;
use EcSugar\PluginShopNotify\ShopNotices;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Tests\Feature\Plugin\LotteryDemoParts;
use Tests\Support\FakeMedia;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * デモの店の守り（ADR 0086。誰でも触れる公開の店なので、悪用を防ぐ）。
 * 印は CLI だけ・本物の鍵は断る（置いてあっても使わない）・メールを送らず来訪者の本人だけが見られる・入力のアドレスは来訪者のアドレスに置き換わる・
 * デモの会員は来訪者ごとに使い捨てで、ほかの来訪者と混ざらない（接続元ごとの回数の上限）・帯と noindex。デモでない店は何も変わらない
 */
final class DemoShopTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    /** カートの合い言葉の Cookie（送るたびに持ち越す） */
    private ?string $cart = null;

    /** 会員の印の Cookie を持ち越すか（デモの会員で入ったまま続ける試しだけ） */
    private bool $keepMember = false;

    private ?string $member = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->tenant('demoshop');
        $this->tenant('plainshop');
        $this->artisan('shop:demo', ['id' => 'demoshop', '--on' => true])->assertSuccessful();

        foreach (['demoshop', 'plainshop'] as $id) {
            $this->inside($id, static fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Open, 'test'));
        }
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();

        parent::tearDown();
    }

    public function test_only_the_cli_sets_the_demo_mark_and_only_on_demo_ids(): void
    {
        $this->assertTrue(DemoShop::is(Tenant::query()->findOrFail('demoshop')));

        // ID が demo で始まらない店には付けられない
        $this->artisan('shop:demo', ['id' => 'plainshop', '--on' => true])->expectsOutputToContain('demo で始まる店だけ')->assertFailed();
        $this->assertFalse(DemoShop::is(Tenant::query()->findOrFail('plainshop')));

        // 印が中央の行にあっても、ID が demo で始まらなければ効かない
        $plain = Tenant::query()->findOrFail('plainshop');
        $plain->setAttribute(DemoShop::FLAG, true);
        $this->assertFalse(DemoShop::is($plain));

        // 印を書くのは shop:demo だけ（管理画面の経路やほかのコードは印に触れない）
        $writers = [];

        foreach ((new Finder)->files()->in([base_path('app'), base_path('plugins'), base_path('routes')])->name('*.php') as $file) {
            if (preg_match('/setAttribute\(\s*DemoShop::FLAG|[\'"]demo[\'"]\s*=>\s*(true|false|\$)/', $file->getContents()) === 1) {
                $writers[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame(['app/Admin/Console/ShopDemoCommand.php'], $writers);

        // 外すのも CLI
        $this->artisan('shop:demo', ['id' => 'demoshop', '--off' => true])->assertSuccessful();
        $this->assertFalse(DemoShop::is(Tenant::query()->findOrFail('demoshop')));
    }

    public function test_live_stripe_keys_are_refused_and_never_used_in_a_demo_shop(): void
    {
        config(['payment.live_keys_allowed' => true]);
        $live = ['secret_key' => 'sk_live_'.str_repeat('a', 24), 'webhook_secret' => 'whsec_'.str_repeat('b', 24)];
        $test = ['secret_key' => 'sk_test_'.str_repeat('c', 24), 'webhook_secret' => 'whsec_'.str_repeat('d', 24)];

        $this->inside('demoshop', function () use ($live, $test): void {
            $gateways = app(PaymentGatewayService::class);

            try {
                $gateways->configureStripe($live, 'test');
                $this->fail('デモの店で本物の鍵を受け付けた');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('テスト用の鍵', $e->errors()['secret_key'][0]);
            }

            $gateways->configureStripe($test, 'test');
            $this->assertSame('test', $gateways->credentials()?->mode);

            // 印を後から付けた店に本物の鍵が残っていても、デモの店では使わない
            PaymentGateway::query()->where('provider', 'stripe')->firstOrFail()->fill(['credentials' => $live, 'mode' => 'live'])->save();
            $this->assertNull($gateways->credentials());
            $this->assertNull($gateways->credentialsForAttempt(new PaymentAttempt(['test_mode' => false])));
        });

        // デモでない店は今までどおり（本物の鍵を許す環境なら使う）
        $this->inside('plainshop', function () use ($live): void {
            app(PaymentGatewayService::class)->configureStripe($live, 'test');
            $this->assertSame('live', app(PaymentGatewayService::class)->credentials()?->mode);
        });

        // 本物の鍵のある店はデモにできない
        $this->tenant('demolive');
        $this->inside('demolive', static fn () => app(PaymentGatewayService::class)->configureStripe($live, 'test'));
        $this->artisan('shop:demo', ['id' => 'demolive', '--on' => true])->expectsOutputToContain('本物の Stripe の鍵')->assertFailed();
        $this->assertFalse(DemoShop::is(Tenant::query()->findOrFail('demolive')));
    }

    public function test_mails_are_kept_from_going_out_and_only_the_visitor_who_caused_them_sees_them(): void
    {
        // 来訪者 A がほかの人のアドレスでログインのコードを頼む → 来訪者 A のアドレスに置き換わり、送らずに A の受け箱へ
        $this->send('demoshop', 'POST', '/account/login', ['email' => 'someone-else@example.org'])->assertRedirect();
        $a = DemoVisitors::mailbox((string) session(DemoVisitors::SESSION));

        $this->assertSame([], $this->sentMessages());
        $mails = $this->inside('demoshop', static fn (): array => DemoMail::query()->get()->map(static fn (DemoMail $m): array => ['mailbox' => $m->mailbox, 'subject' => $m->subject])->all());
        $this->assertCount(1, $mails);
        $this->assertSame($a, $mails[0]['mailbox']);
        // 置き換えたアドレスへの台帳はあっても、打ち込まれた他人のアドレスには何も届かない・残らない
        $this->assertSame(0, $this->inside('demoshop', static fn (): int => CustomerAccount::query()->whereBlind('email', 'someone-else@example.org')->count()));

        $page = $this->send('demoshop', 'GET', '/demo/mails')->assertOk();
        $page->assertSee($mails[0]['subject'])->assertSee('demo-'.$a.'@example.com');

        // 来訪者 B（別のブラウザ）: A のメールは見えない。A のアドレスを打っても、B のアドレスに置き換わる
        $this->flushSession();
        $this->send('demoshop', 'GET', '/demo/mails')->assertOk()->assertSee('まだメールはありません')->assertDontSee($mails[0]['subject']);
        $this->send('demoshop', 'POST', '/account/login', ['email' => 'demo-'.$a.'@example.com'])->assertRedirect();
        $b = DemoVisitors::mailbox((string) session(DemoVisitors::SESSION));
        $this->assertNotSame($a, $b);
        $this->assertSame(1, $this->inside('demoshop', static fn (): int => DemoMail::query()->where('mailbox', $a)->count()));
        $this->assertSame(1, $this->inside('demoshop', static fn (): int => DemoMail::query()->where('mailbox', $b)->count()));
        $this->assertSame([], $this->sentMessages());

        // デモでない店はふだんどおり送り、受け箱の画面は無い
        $this->flushSession();
        $this->send('plainshop', 'POST', '/account/login', ['email' => 'hanako@example.org'])->assertRedirect();
        $this->assertCount(1, $this->sentMessages());
        $this->assertSame(0, $this->inside('plainshop', static fn (): int => DemoMail::query()->count()));
        $this->send('plainshop', 'GET', '/demo/mails')->assertNotFound();
    }

    public function test_a_guest_order_goes_to_the_visitors_address_and_its_mail_stays_in_the_visitors_box(): void
    {
        $listing = $this->inside('demoshop', static function (): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '純米酒', 'unit_amount' => 2200, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->orderBy('id')->firstOrFail(), 5);
            app(DirectMethodSettings::class)->update(['bank_transfer' => true, 'bank_days' => 7, 'bank_account' => ['bank' => '見本銀行', 'branch' => '本店', 'type' => 'ordinary', 'number' => '0000000', 'holder' => 'ミホン'],
                'cod' => false, 'cod_fees' => DirectMethodSettings::DEFAULT_COD_FEES, 'cod_limit' => 300000], 'test');

            return $listing->id;
        });

        $this->send('demoshop', 'POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1'])->assertRedirect();
        $this->send('demoshop', 'POST', '/checkout', [
            'buyer' => ['email' => 'neighbor@example.org', 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''],
            'same_as_buyer' => '1', 'payment' => 'bank_transfer',
        ])->assertRedirect('http://demoshop.localhost/checkout/confirm');
        $html = (string) $this->send('demoshop', 'GET', '/checkout/confirm')->assertOk()->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $html, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);
        $this->send('demoshop', 'POST', '/checkout/confirm', ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''])->assertRedirect('http://demoshop.localhost/checkout/complete');
        $this->send('demoshop', 'GET', '/checkout/complete')->assertOk()->assertSee('data-test="demo-direct-note"', false);

        // 注文は来訪者のアドレスで、打ち込んだ人のアドレスには台帳も無く、メールは送らずに来訪者の受け箱へ
        $token = (string) session(DemoVisitors::SESSION);
        $this->inside('demoshop', function () use ($token): void {
            $this->assertSame(1, CustomerAccount::query()->whereBlind('email', DemoVisitors::address($token))->count());
            $this->assertSame(0, CustomerAccount::query()->whereBlind('email', 'neighbor@example.org')->count());
            $this->assertSame(1, DemoMail::query()->where('mailbox', DemoVisitors::mailbox($token))->count());
        });
        $this->assertSame([], $this->sentMessages());
        $this->send('demoshop', 'GET', '/demo/mails')->assertOk()->assertSee('data-test="demo-mail"', false)->assertSee('純米酒');

        // 注文の知らせのメールは記録の受け手（ADR 0089 の Outbox）が送り、デモの店では受け箱に止まる。
        // 毎分の見回りが流し直しても、2 通目を置かず外へも送らない（受け手は送った記録で 1 回だけにする）
        $this->artisan('events:relay', ['--tenant' => 'demoshop'])->assertSuccessful();
        $this->inside('demoshop', function () use ($token): void {
            $this->assertSame(1, DemoMail::query()->where('mailbox', DemoVisitors::mailbox($token))->count());
            $this->assertSame(0, DB::table('event_retries')->count());
            $this->assertSame((int) DomainEvent::query()->max('sequence'), (int) DB::table('event_receiver_positions')->min('position'));
        });
        $this->assertSame([], $this->sentMessages());
    }

    public function test_the_mailbox_and_the_demo_member_answer_only_when_the_browser_opens_the_page(): void
    {
        // 受け箱にログインのコードのメールを置く
        $this->send('demoshop', 'POST', '/account/login', ['email' => 'someone@example.org'])->assertRedirect();
        $subject = (string) $this->inside('demoshop', static fn (): ?string => DemoMail::query()->value('subject'));

        // 同じオリジンの JavaScript（App のタグ）の fetch には、受け箱を見せず、デモの会員も作らない（ADR 0088。中身の無い 403）
        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty']);
        $fetched = $this->send('demoshop', 'GET', '/demo/mails')->assertForbidden();
        $this->assertSame('', (string) $fetched->getContent());
        $this->send('demoshop', 'POST', '/demo/member')->assertForbidden();
        $this->assertSame(0, $this->inside('demoshop', static fn (): int => CustomerAccount::query()->whereNotNull('member_since')->count()));

        // ブラウザが画面を開く時（リンク・帯のフォーム）は通り、受け箱はブラウザの控えに残さない
        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document']);
        $page = $this->send('demoshop', 'GET', '/demo/mails')->assertOk()->assertSee($subject);
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->send('demoshop', 'POST', '/demo/member')->assertRedirect();
        $this->assertSame(1, $this->inside('demoshop', static fn (): int => CustomerAccount::query()->whereNotNull('member_since')->count()));
    }

    public function test_a_visitor_applies_for_the_resale_lottery_of_the_sold_out_livehouse_show(): void
    {
        $this->requiresApps('tickets', 'lottery');
        // ライブハウスのデモ（売り切れた対バンで公式の定価リセールを使う）。写真は空のフォルダから（外へは出ない）
        FakeMedia::fake();
        Http::preventStrayRequests();
        FakeStripe::install();
        $photos = sys_get_temp_dir().'/demo-photos-'.bin2hex(random_bytes(4));
        mkdir($photos);

        try {
            $this->artisan('demo:shop', ['kind' => 'livehouse', '--tenant' => 'demoshop', '--photos' => $photos])->assertSuccessful();
        } finally {
            @rmdir($photos);
        }

        $keys = ['secret_key' => 'sk_test_'.str_repeat('c', 24), 'webhook_secret' => 'whsec_'.str_repeat('d', 24)];
        [$listing, $variant] = $this->inside('demoshop', static function () use ($keys): array {
            app(PaymentGatewayService::class)->configureStripe($keys, 'test');
            $friday = DemoShopBuilder::find(DemoShops::definition('livehouse'), 'friday') ?? throw new \LogicException('friday');

            return [$friday->id, (string) ListingVariant::query()->where('listing_id', $friday->id)->value('id')];
        });

        // デモの会員で入り、カードを預けた形にする（預ける画面は Stripe のテストの鍵の画面）
        $this->keepMember = true;
        $this->send('demoshop', 'POST', '/demo/member')->assertRedirect();
        $account = $this->accountOf((string) session(DemoVisitors::SESSION));
        $this->inside('demoshop', static function () use ($account): void {
            $owner = CustomerAccount::query()->findOrFail($account['id']);
            StoredPaymentMethod::create(['customer_account_id' => $owner->id, 'provider' => 'stripe', 'account' => 'test:own', 'customer_ref' => app(StoredPayments::class)->customerFor($owner),
                'method_ref' => 'pm_test_'.Str::random(10), 'status' => 'usable', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]);
        });

        // 商品の画面から、リセールの抽選に申し込む（第 1 希望・お名前・規約の同意 → 確かめ → 預けたカードで）
        $this->send('demoshop', 'GET', "/products/{$listing}")->assertOk()->assertSee('/p/lottery/resale/'.$listing, false)->assertSee('リセールの抽選に申し込む');
        $this->send('demoshop', 'GET', "/p/lottery/resale/{$listing}")->assertOk()->assertSee('data-test="lottery-resale-round"', false);
        $this->send('demoshop', 'POST', "/p/lottery/resale/{$listing}", ['first_variant_id' => $variant, 'quantity' => 1, 'name' => '夜鷹 響', 'agree' => '1'])
            ->assertRedirect("http://demoshop.localhost/p/lottery/resale/{$listing}/confirm");
        $this->send('demoshop', 'GET', "/p/lottery/resale/{$listing}/confirm")->assertOk()->assertSee('¥2,800 まで');
        $this->send('demoshop', 'POST', "/p/lottery/resale/{$listing}/place", ['use_card' => '1'])->assertRedirect('http://demoshop.localhost/p/lottery/done');

        // 申し込みと抽選を見る所は LotteryDemoParts（許す一覧の Lottery* で公開の木に入らない）
        if (! class_exists(LotteryDemoParts::class)) {
            $this->missingPart('tests/Feature/Plugin/LotteryDemoParts.php');
        }

        $lottery = new LotteryDemoParts;
        $entry = fn (): array => $this->inside('demoshop', static fn (): array => $lottery->onlyEntry());
        $this->assertSame(['applied', 2800], [$entry()['status'], $entry()['first_unit_amount']]);
        $drawId = $entry()['draw_id'];
        $this->assertTrue($this->inside('demoshop', static fn (): bool => $lottery->isResaleDraw($drawId)));

        // 毎時の抽選はデモの店でも走るが、リセールに出ているチケットが無いので当てず、請求もしない。毎時の戻しも売り切れのまま
        $this->travel(1)->hours();
        $this->artisan('lottery:run')->assertSuccessful();
        $this->artisan('demo:restock', ['--tenant' => ['demoshop']])->assertSuccessful();
        $this->assertSame('applied', $entry()['status']);
        $round = $this->inside('demoshop', static fn (): ?array => DomainEvent::query()->where('type', 'lottery.resale_round')->latest('sequence')->first()?->payload);
        // 回は引いた（出ているリセールが無い回は、候補を作らずに回の時刻と記録だけ）
        $this->assertSame([0, 0], [$round['listed'] ?? null, $round['won'] ?? null]);
        $this->assertSame(0, $this->inside('demoshop', static fn (): int => PaymentAttempt::query()->count()));
        $this->send('demoshop', 'GET', "/products/{$listing}")->assertOk()->assertSee('リセールの抽選に申し込む');
        $this->assertSame([], $this->sentMessages());
    }

    public function test_addresses_in_the_query_string_and_in_odd_forms_are_replaced_too(): void
    {
        // クエリ文字列だけで送る（本文にアドレスは無い。Laravel の input() はクエリ文字列も読む）
        $this->send('demoshop', 'POST', '/account/login?email=victim%40example.org')->assertRedirect();
        // 引用符と空白のある書き方（RFC では正しいアドレス）と、全角の＠（台帳の索引は NFKC で半角に寄せる）
        $this->flushSession();
        $this->send('demoshop', 'POST', '/account/login', ['email' => '"victim x"@example.org'])->assertRedirect();
        $this->flushSession();
        $this->send('demoshop', 'POST', '/account/login', ['email' => 'victim2＠example.org'])->assertRedirect();

        // 入れ子（注文の入力の buyer[email]）に、引用符つきの書き方。クエリ文字列にも別のアドレス
        $this->flushSession();
        $listing = $this->demoListing();
        $this->send('demoshop', 'POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1'])->assertRedirect();
        $this->send('demoshop', 'POST', '/checkout?contact=neighbor2%40example.org', $this->buyer('"neighbor x"@example.org'))->assertRedirect('http://demoshop.localhost/checkout/confirm');
        $this->placeConfirmed()->assertRedirect('http://demoshop.localhost/checkout/complete');
        $token = (string) session(DemoVisitors::SESSION);

        // 打ち込んだ本物のアドレスは、どの書き方でも台帳にもログインのコードの索引にも入らない（来訪者のアドレスだけ）
        $this->inside('demoshop', function () use ($token): void {
            $leaked = [];

            foreach (['victim@example.org', '"victim x"@example.org', 'victim2@example.org', '"neighbor x"@example.org', 'neighbor2@example.org'] as $real) {
                if (CustomerAccount::query()->whereBlind('email', $real)->exists() || CustomerLoginCode::query()->where('email_index', BlindIndex::of('email', $real))->exists()) {
                    $leaked[] = $real;
                }
            }

            $this->assertSame([], $leaked);

            $this->assertSame(1, CustomerAccount::query()->whereBlind('email', DemoVisitors::address($token))->count());
            $this->assertSame(3, CustomerLoginCode::query()->count());
        });
        $this->assertSame([], $this->sentMessages());
    }

    public function test_one_visitor_places_up_to_five_orders_a_day_and_others_still_can(): void
    {
        $listing = $this->demoListing(20);

        // 1 件ごとに店が入金を確かめる（同じメール・同じ接続元の入金待ちの上限〔ADR 0085。メール 3 つ・接続元 5 つ〕より先に、
        // デモの店の来訪者ごとの 1 日の上限が効くことを確かめるため。入金待ちのままなら 4 件目で銀行振込を選べなくなる）
        for ($i = 1; $i <= DemoOrders::PER_VISITOR_PER_DAY; $i++) {
            $this->orderOnce($listing)->assertRedirect('http://demoshop.localhost/checkout/complete');
            $this->confirmLatestPayment();
        }

        // 6 件目は断る（分かる文で。注文は作らない・在庫を押さえない）
        $this->orderOnce($listing)->assertRedirect('http://demoshop.localhost/cart')->assertSessionHas('cart_error', DemoOrders::REFUSED);
        $this->assertSame(5, $this->inside('demoshop', static fn (): int => Transaction::query()->count()));

        // ほかの来訪者（別のブラウザ）は注文できる
        $this->flushSession();
        $this->cart = null;
        $this->orderOnce($listing)->assertRedirect('http://demoshop.localhost/checkout/complete');
        $this->assertSame(6, $this->inside('demoshop', static fn (): int => Transaction::query()->count()));
    }

    public function test_the_demo_mark_is_refused_for_a_shop_that_may_be_running(): void
    {
        // お客さんの台帳がある店
        $this->tenant('demoaccount');
        $this->inside('demoaccount', static fn () => app(CustomerAccounts::class)->forEmail('hanako@example.org', 'storefront'));
        $this->artisan('shop:demo', ['id' => 'demoaccount', '--on' => true])->expectsOutputToContain('お客さんのデータ')->assertFailed();

        // デモの台本の外で作った商品がある店
        $this->tenant('demolisting');
        $this->inside('demolisting', static fn () => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '本物の商品', 'unit_amount' => 1000, 'status' => 'active']));
        $this->artisan('shop:demo', ['id' => 'demolisting', '--on' => true])->expectsOutputToContain('台本（demo:shop）の外で作った商品')->assertFailed();

        // 外へ送る App（計測のタグ）を使っている店
        $this->tenant('demotag');
        $this->inside('demotag', static fn () => app(PluginStates::class)->set('ga4', true, 'test'));
        $this->artisan('shop:demo', ['id' => 'demotag', '--on' => true])->expectsOutputToContain('外へ送る App')->assertFailed();

        foreach (['demoaccount', 'demolisting', 'demotag'] as $id) {
            $this->assertFalse(DemoShop::is(Tenant::query()->findOrFail($id)), $id);
        }

        // もう印のある店に付け直しても、来訪者の記録で断らない（何も変えない）
        $this->inside('demoshop', static fn () => app(CustomerAccounts::class)->forEmail(DemoVisitors::address(DemoVisitors::newToken()), 'storefront'));
        $this->artisan('shop:demo', ['id' => 'demoshop', '--on' => true])->expectsOutputToContain('もうデモの店です')->assertSuccessful();
    }

    public function test_apps_that_send_outside_cannot_be_turned_on_in_a_demo_shop(): void
    {
        $this->inside('demoshop', function (): void {
            $states = app(PluginStates::class);

            foreach (['shop-notify', 'line', 'ga4', 'meta-pixel'] as $plugin) {
                try {
                    $states->set($plugin, true, 'cli:test');
                    $this->fail("デモの店で {$plugin} を使うにできた");
                } catch (PluginRefusedInDemo) {
                    $this->assertFalse($states->enabled($plugin), $plugin);
                }
            }

            // 外へ送らない App は使える
            $states->set('faq', true, 'cli:test');
            $this->assertTrue($states->enabled('faq'));

            // 印を付ける前から使う設定が残っていても、送る口で止める（Slack へ出さない）
            PluginState::query()->create(['plugin' => 'shop-notify', 'enabled' => true, 'changed_at' => now()]);
            app(PluginSettings::class)->update('shop-notify', ['on_paid' => true, 'slack_webhook_url' => 'https://hooks.slack.com/services/T000/B000/demo'], 'cli:test');
            Http::fake();
            $this->assertFalse(app(ShopNotices::class)->deliver('test', null, 'demo-test'));
            Http::assertNothingSent();
        });

        // 管理画面の口（PluginStates::set）も同じ答え。デモでない店は今までどおり
        $this->inside('plainshop', static fn () => app(PluginStates::class)->set('ga4', true, 'test'));
        $this->assertTrue($this->inside('plainshop', static fn (): bool => app(PluginStates::class)->enabled('ga4')));
    }

    public function test_one_visitor_cannot_push_out_another_visitors_mails(): void
    {
        $this->inside('demoshop', function (): void {
            $a = DemoVisitors::address(DemoVisitors::newToken());
            $b = DemoVisitors::address(DemoVisitors::newToken());
            $mails = app(DemoMails::class);
            $mails->capture((new Email)->to($b)->subject('B のメール')->text('本文'));

            for ($i = 0; $i < DemoMails::PER_MAILBOX + 10; $i++) {
                $mails->capture((new Email)->to($a)->subject("A のメール {$i}")->text('本文'));
            }

            $this->assertSame(DemoMails::PER_MAILBOX, DemoMail::query()->where('mailbox', DemoVisitors::mailboxOf($a))->count());
            $this->assertSame(1, DemoMail::query()->where('mailbox', DemoVisitors::mailboxOf($b))->count());
        });
    }

    public function test_photos_and_robots_of_a_demo_shop_carry_noindex_too(): void
    {
        $this->assertSame('noindex, nofollow', $this->send('demoshop', 'GET', '/robots.txt')->headers->get('X-Robots-Tag'));
        $photo = $this->send('demoshop', 'GET', '/media/listings/'.strtolower((string) Str::ulid()).'/large');
        $this->assertSame('noindex, nofollow', $photo->headers->get('X-Robots-Tag'));
        $this->assertNull($this->send('plainshop', 'GET', '/robots.txt')->headers->get('X-Robots-Tag'));
    }

    public function test_the_demo_member_belongs_to_one_visitor_only(): void
    {
        $this->send('demoshop', 'GET', '/account/login')->assertOk()->assertSee('デモの会員でログイン');
        $this->send('demoshop', 'POST', '/demo/member')->assertRedirect();
        $first = $this->accountOf((string) session(DemoVisitors::SESSION));

        // 同じ来訪者がもう一度押しても同じ会員
        $this->send('demoshop', 'POST', '/demo/member')->assertRedirect();
        $this->assertSame($first['id'], $this->accountOf((string) session(DemoVisitors::SESSION))['id']);
        $this->assertTrue($first['member']);
        $this->assertSame(2, $this->inside('demoshop', static fn (): int => CustomerSession::query()->where('customer_account_id', $first['id'])->where('via', 'demo')->count()));

        // 別の来訪者は別の会員（名前や届け先を分け合わない）
        $this->flushSession();
        $this->send('demoshop', 'POST', '/demo/member')->assertRedirect();
        $second = $this->accountOf((string) session(DemoVisitors::SESSION));
        $this->assertNotSame($first['id'], $second['id']);

        // 接続元ごとに 1 時間 20 回まで
        for ($i = 0; $i < 25; $i++) {
            $this->flushSession();
            $this->send('demoshop', 'POST', '/demo/member', [], '198.51.100.7');
        }

        $this->assertSame(2 + 20, $this->inside('demoshop', static fn (): int => CustomerAccount::query()->whereNotNull('member_since')->count()));

        // デモでない店には無い
        $this->send('plainshop', 'POST', '/demo/member')->assertNotFound();
        $this->send('plainshop', 'GET', '/account/login')->assertOk()->assertDontSee('デモの会員でログイン');
    }

    public function test_every_page_carries_the_banner_and_noindex_only_in_the_demo_shop(): void
    {
        $home = $this->send('demoshop', 'GET', '/')->assertOk();
        $home->assertSee('data-test="demo-banner"', false)->assertSee('代金は実際には請求されません')->assertSee('<meta name="robots" content="noindex', false);
        $this->assertSame('noindex, nofollow', $home->headers->get('X-Robots-Tag'));
        $this->send('demoshop', 'GET', '/robots.txt')->assertOk()->assertSee('Disallow: /');
        $this->send('demoshop', 'GET', '/sitemap.xml')->assertNotFound();

        $plain = $this->send('plainshop', 'GET', '/')->assertOk();
        $plain->assertDontSee('data-test="demo-banner"', false);
        $this->assertNull($plain->headers->get('X-Robots-Tag'));
    }

    /**
     * デモの店に、銀行振込で買える商品を 1 つ作る（在庫 $stock）
     */
    private function demoListing(int $stock = 5): string
    {
        return $this->inside('demoshop', static function () use ($stock): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '純米酒', 'unit_amount' => 2200, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->orderBy('id')->firstOrFail(), $stock);
            app(DirectMethodSettings::class)->update(['bank_transfer' => true, 'bank_days' => 7, 'bank_account' => ['bank' => '見本銀行', 'branch' => '本店', 'type' => 'ordinary', 'number' => '0000000', 'holder' => 'ミホン'],
                'cod' => false, 'cod_fees' => DirectMethodSettings::DEFAULT_COD_FEES, 'cod_limit' => 300000], 'test');

            return $listing->id;
        });
    }

    /**
     * カートに入れて、銀行振込で注文する
     *
     * @return TestResponse<Response>
     */
    private function orderOnce(string $listing): TestResponse
    {
        $this->send('demoshop', 'POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1'])->assertRedirect();
        $this->send('demoshop', 'POST', '/checkout', $this->buyer('buyer@example.org'))->assertRedirect('http://demoshop.localhost/checkout/confirm');

        return $this->placeConfirmed();
    }

    /**
     * いちばん新しい注文の銀行振込の入金を、店の人が確かめる
     */
    private function confirmLatestPayment(): void
    {
        $this->inside('demoshop', static function (): void {
            $sale = Transaction::query()->latest('created_at')->latest('id')->firstOrFail();
            app(DirectPayments::class)->confirm($sale, (int) $sale->total_amount, false, 'operator:test');
        });
    }

    /**
     * 確かめの画面から注文を確定する（入力は済んでいる）
     *
     * @return TestResponse<Response>
     */
    private function placeConfirmed(): TestResponse
    {
        $html = (string) $this->send('demoshop', 'GET', '/checkout/confirm')->assertOk()->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $html, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);

        return $this->send('demoshop', 'POST', '/checkout/confirm', ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? '']);
    }

    /**
     * 注文の入力（銀行振込）
     *
     * @return array<string, mixed>
     */
    private function buyer(string $email): array
    {
        return [
            'buyer' => ['email' => $email, 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''],
            'same_as_buyer' => '1', 'payment' => 'bank_transfer',
        ];
    }

    /**
     * @return list<mixed>
     */
    private function sentMessages(): array
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages()->all();
    }

    /**
     * @return array{id: string, member: bool}
     */
    private function accountOf(string $token): array
    {
        $this->assertTrue(DemoVisitors::validToken($token));

        return $this->inside('demoshop', static function () use ($token): array {
            $account = CustomerAccount::query()->whereBlind('email', DemoVisitors::address($token))->firstOrFail();

            return ['id' => $account->id, 'member' => $account->member_since !== null];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $shop, string $method, string $path, array $data = [], ?string $ip = null): TestResponse
    {
        if ($method !== 'GET') {
            $this->travel(7)->seconds();
        }

        if ($this->cart !== null) {
            $this->withCookie(CartCookie::NAME, $this->cart);
        }

        if ($this->keepMember && $this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        $this->app['auth']->forgetGuards();
        $url = "http://{$shop}.localhost{$path}";
        $server = $ip === null ? [] : ['REMOTE_ADDR' => $ip];
        $response = $method === 'GET' ? $this->withServerVariables($server)->get($url) : $this->withServerVariables($server)->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(CartCookie::NAME);

        if ($cookie !== null) {
            $this->cart = (string) $cookie->getValue();
        }

        $member = $response->getCookie(MemberCookie::NAME);

        if ($this->keepMember && $member !== null) {
            $this->member = (string) $member->getValue() === '' ? null : (string) $member->getValue();
        }

        return $response;
    }
}
