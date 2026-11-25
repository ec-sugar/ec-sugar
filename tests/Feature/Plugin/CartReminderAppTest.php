<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Crypto\BlindIndex;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\Services\MarketingConsents;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartLine;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use EcSugar\PluginCartReminder\CartReminderAdminPage;
use EcSugar\PluginCartReminder\CartReminders;
use EcSugar\PluginCartReminder\Mail\CartReminderMail;
use EcSugar\PluginCartReminder\Models\CartReminder;
use EcSugar\PluginCartReminder\ReminderSender;
use EcSugar\PluginNewsletter\Models\NewsletterSubscriber;
use EcSugar\PluginNewsletter\SubscriberStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「カゴ落ちのお知らせ」（ADR 0067）。払われずに消えたショップの注文へ、待つ時間の後に 1 回だけお知らせを送る。
 * 同意（メルマガの App が核の口 MarketingConsent で答える）・送らない時（後から注文・7 日のうち・買えない・送り主の表示）・待つ時間の境界・
 * 同時に回る・送れなかった・「カートに戻す」のリンク（期限・ほかの店・売り切れと非公開を外す・回数）・配信の停止・平文のメールを残さない
 */
final class CartReminderAppTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const array LEGAL = [
        ['label' => '販売業者', 'value' => '株式会社かくざとう'],
        ['label' => '所在地', 'value' => '東京都渋谷区神宮前 1-2-3'],
        ['label' => '電話番号', 'value' => '03-1234-5678'],
    ];

    private string $mug;

    private string $tote;

    private ?string $cart = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        FakeStripe::install();
        $this->tenant('cra', 'かくざとう');
        [$this->mug, $this->tote] = $this->inside('cra', function (): array {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(ShopSettingsService::class)->setLegal(['items' => self::LEGAL], 'test');
            app(PluginStates::class)->set('newsletter', true, 'test');
            app(PluginStates::class)->set('cart-reminder', true, 'test');
            $seller = Seller::query()->value('id');
            $mug = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'マグカップ & ソーサー', 'unit_amount' => 2400, 'status' => 'active']);
            $tote = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => '帆布のトート', 'unit_amount' => 5400, 'status' => 'active']);
            ListingImage::create(['listing_id' => $mug->id, 'position' => 0, 'width' => 800, 'height' => 800, 'checksum' => str_repeat('a', 64)]);

            foreach ([$mug, $tote] as $listing) {
                app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 10);
            }

            return [$mug->id, $tote->id];
        });
        // App を使い始めた後に消えた注文だけが対象
        $this->travel(1)->minutes();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_consenting_buyer_gets_one_mail_after_the_wait_and_the_link_puts_the_items_back_in_the_cart(): void
    {
        $this->subscribe('hanako@example.com');
        $order = $this->order([$this->mug => 2, $this->tote => 1]);
        $this->cancel($order);

        // 待つ時間（既定 3 時間）の前は何も作らない
        $this->travel(2)->hours();
        $this->travel(59)->minutes();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertNotSent(CartReminderMail::class);
        $this->assertSame(0, $this->inside('cra', fn (): int => CartReminder::query()->count()));

        $this->travel(2)->minutes();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertSent(CartReminderMail::class, 1);
        $mail = Mail::sent(CartReminderMail::class)->first();
        $this->assertInstanceOf(CartReminderMail::class, $mail);
        $this->assertTrue($mail->hasTo('hanako@example.com'));

        // メールの中身: 今の名前と写真・カートに戻すのリンク・法の表示。値段・数・注文番号は載せない
        $html = $mail->render();
        $text = (string) view('cart-reminder::mail.reminder-text', $mail->content()->with)->render();
        $base = preg_quote(StorefrontUrls::base('cra.localhost'), '#');
        $this->assertMatchesRegularExpression("#\\A{$base}/p/cart-reminder/[A-Za-z0-9_-]{43}\\z#", $mail->cartUrl);
        $this->assertMatchesRegularExpression("#\\A{$base}/p/cart-reminder/unsubscribe/[A-Za-z0-9_-]{43}\\z#", $mail->unsubscribeUrl);
        $this->assertStringContainsString(StorefrontUrls::base('cra.localhost').'/media/listings/', $html);
        foreach (['マグカップ &amp; ソーサー', '帆布のトート', '株式会社かくざとう', '東京都渋谷区神宮前 1-2-3', '電話番号: 03-1234-5678', '配信の停止', e($mail->cartUrl)] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        foreach (['マグカップ & ソーサー', $mail->cartUrl, $mail->unsubscribeUrl, '株式会社かくざとう'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertStringNotContainsString('&amp;', $text);
        foreach (['¥', '2,400', strtoupper($order), 'hanako'] as $absent) {
            $this->assertStringNotContainsString($absent, $html.$text);
        }
        $this->assertSame('<'.$mail->unsubscribeUrl.'>', $mail->headers()->text['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $mail->headers()->text['List-Unsubscribe-Post']);
        $this->assertSame('[かくざとう] お買い物の続きのご案内', $mail->envelope()->subject);

        // もう一度回しても、同じ注文に 2 通目は無い
        $this->travel(1)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertSent(CartReminderMail::class, 1);

        // リンクを開くと、今の値段と在庫でカートを作り直してカートの画面へ（Cookie でつなぐ）
        $path = (string) parse_url($mail->cartUrl, PHP_URL_PATH);
        $this->shop('GET', $path)->assertRedirect('http://cra.localhost/cart')->assertSessionHas('cart_message')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNotNull($this->cart);
        $this->assertEquals([$this->mug => 2, $this->tote => 1], $this->cartLines());
        // 2 回開いても数は増えない
        $this->shop('GET', $path)->assertRedirect('http://cra.localhost/cart');
        $this->assertEquals([$this->mug => 2, $this->tote => 1], $this->cartLines());

        // 戻った後に払われた注文を、次の回で「払われた」に数える
        $paid = $this->order([$this->mug => 2], 'Hanako@Example.com');
        $this->pay($paid);
        $this->artisan('cart-reminders:run')->assertSuccessful();

        $this->inside('cra', function () use ($order, $paid): void {
            $row = CartReminder::query()->sole();
            $this->assertSame(CartReminder::SENT, $row->status);
            $this->assertSame($order, $row->transaction_id);
            $this->assertNotNull($row->returned_at);
            $this->assertSame($paid, $row->recovered_transaction_id);

            // 管理画面の数（直近 30 日）。行は注文番号と日時だけ
            $counts = [];
            foreach (app(CartReminderAdminPage::class)->filters() as $filter) {
                $counts[$filter->key] = $filter->count;
            }
            $this->assertSame(['sent' => 1, 'returned' => 1, 'recovered' => 1, 'skipped' => 0, 'all' => 1], $counts);

            // 新しい列とイベントに平文のメールが無い
            $stored = json_encode([DB::table('cart_reminders')->get(), DomainEvent::query()->get(['type', 'payload'])]);
            $this->assertIsString($stored);
            $this->assertStringNotContainsStringIgnoringCase('hanako', $stored);
        });
    }

    public function test_only_people_who_agreed_to_the_newsletter_get_a_mail(): void
    {
        // 同意していない・確かめ待ち・止めた人には送らない
        $this->subscribe('pending@example.com', SubscriberStatus::Pending);
        $this->subscribe('stopped@example.com', SubscriberStatus::Unsubscribed);
        foreach (['nobody@example.com', 'pending@example.com', 'stopped@example.com'] as $email) {
            $this->cancel($this->order([$this->mug => 1], $email));
        }
        $this->travel(4)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertNotSent(CartReminderMail::class);
        $this->assertSame(['no_consent', 'no_consent', 'no_consent'], $this->reasons());

        // メルマガの App を使っていなければ、同意している人にも送らない（誰にも送らない）
        $this->subscribe('hanako@example.com');
        $this->inside('cra', fn () => app(PluginStates::class)->set('newsletter', false, 'test'));
        $this->cancel($this->order([$this->mug => 1]));
        $this->travel(4)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertNotSent(CartReminderMail::class);
        $this->assertSame(['no_consent', 'no_consent', 'no_consent', 'no_consent'], $this->reasons());
        $this->assertFalse($this->inside('cra', fn (): bool => app(MarketingConsents::class)->available()));
    }

    public function test_a_later_order_a_recent_mail_items_that_cannot_be_bought_and_a_missing_sender_stop_the_mail(): void
    {
        foreach (['later@example.com', 'hanako@example.com', 'gone@example.com', 'nolegal@example.com'] as $email) {
            $this->subscribe($email);
        }

        // 後から同じメールで払われた注文がある（大文字小文字は同じメール）
        $this->cancel($this->order([$this->mug => 1], 'later@example.com'));
        $this->travel(1)->minutes();
        $this->pay($this->order([$this->mug => 1], 'LATER@example.com'));
        // 商品が今は 1 つも買えない（非公開）
        $this->cancel($this->order([$this->tote => 1], 'gone@example.com'));
        $this->inside('cra', fn () => Listing::query()->whereKey($this->tote)->update(['status' => 'draft']));
        $this->travel(4)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertNotSent(CartReminderMail::class);
        $this->assertEqualsCanonicalizing(['ordered_later', 'unavailable'], $this->reasons());

        // 7 日のうちに同じメールへ送っていれば送らない。7 日を過ぎれば送る
        $this->cancel($this->order([$this->mug => 1]));
        $this->travel(4)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertSent(CartReminderMail::class, 1);
        $this->travel(6)->days();
        $this->cancel($this->order([$this->mug => 1]));
        $this->travel(4)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertSent(CartReminderMail::class, 1);
        $this->assertEqualsCanonicalizing(['ordered_later', 'unavailable', 'recent'], $this->reasons());
        $this->travel(1)->days();
        $this->cancel($this->order([$this->mug => 1]));
        $this->travel(4)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertSent(CartReminderMail::class, 2);

        // 送り主の表示（特商法の表記）が足りなければ送らず、ホームのやることに出す
        $this->inside('cra', fn () => app(ShopSettingsService::class)->setLegal(['items' => [['label' => '販売業者', 'value' => '株式会社かくざとう']]], 'test'));
        $this->cancel($this->order([$this->mug => 1], 'nolegal@example.com'));
        $this->travel(4)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertSent(CartReminderMail::class, 2);
        $this->assertEqualsCanonicalizing(['ordered_later', 'unavailable', 'recent', 'no_sender'], $this->reasons());
        $this->assertSame(1, $this->inside('cra', fn (): int => app(CartReminderAdminPage::class)->attention()[0]->count));
    }

    public function test_the_wait_follows_the_setting_and_old_or_konbini_orders_are_left_alone(): void
    {
        $this->subscribe('hanako@example.com');
        $this->inside('cra', fn () => app(PluginSettings::class)->update('cart-reminder', ['wait' => 'h1'], 'test'));
        $order = $this->order([$this->mug => 1]);
        $this->cancel($order);

        $this->travel(59)->minutes();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertNotSent(CartReminderMail::class);
        $this->travel(1)->minutes();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertSent(CartReminderMail::class, 1);

        // コンビニ払いの入金待ちになった注文（期限切れの知らせが別にある）は送らない
        $this->travel(8)->days();
        $konbini = $this->order([$this->mug => 1]);
        $this->inside('cra', function () use ($konbini): void {
            app(PaymentService::class)->startCheckout(Transaction::query()->findOrFail($konbini), 'x', 'y');
            PaymentAttempt::query()->where('transaction_id', $konbini)->update(['pay_by' => CarbonImmutable::now()->addDays(3)]);
        });
        $this->cancel($konbini);
        $this->travel(2)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertSent(CartReminderMail::class, 1);
        $this->assertSame(1, $this->inside('cra', fn (): int => CartReminder::query()->count()));

        // 待つ時間＋ 24 時間より前に消えた注文は拾わない（毎時の処理が長く止まっていた時に、古いものをまとめて送らない）
        $old = $this->order([$this->mug => 1], 'old@example.com');
        $this->subscribe('old@example.com');
        $this->cancel($old);
        $this->travel(26)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertSent(CartReminderMail::class, 1);
        $this->assertSame(1, $this->inside('cra', fn (): int => CartReminder::query()->count()));
    }

    public function test_two_runs_at_the_same_time_send_one_mail_and_a_job_runs_once(): void
    {
        $this->subscribe('hanako@example.com');
        $this->cancel($this->order([$this->mug => 1]));
        $this->travel(4)->hours();

        $this->inside('cra', function (): void {
            // 1 つ目の処理が候補を読んだ直後に、2 つ目の処理が割り込んで先に行を作って送る
            $fired = false;
            DB::listen(function (QueryExecuted $query) use (&$fired): void {
                if ($fired || ! str_contains($query->sql, 'from "transactions" as "t" inner join "checkout_orders"')) {
                    return;
                }
                $fired = true;
                app(CartReminders::class)->run(CarbonImmutable::now());
            });
            $outer = app(CartReminders::class)->run(CarbonImmutable::now());
            $this->assertTrue($fired);
            $this->assertSame(0, $outer['queued']);
            $this->assertSame(1, CartReminder::query()->count());

            // 同じ記録の送る仕事がもう一度動いても送らない
            $this->assertSame('none', app(ReminderSender::class)->send((string) CartReminder::query()->value('id')));
        });
        Mail::assertSent(CartReminderMail::class, 1);
    }

    public function test_a_failed_send_is_marked_failed_and_is_not_sent_again(): void
    {
        $this->subscribe('hanako@example.com');
        $this->cancel($this->order([$this->mug => 1]));
        $this->travel(4)->hours();

        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('送り口が断った（宛先 hanako@example.com）'));
        $this->artisan('cart-reminders:run')->assertSuccessful();

        $this->inside('cra', function (): void {
            $row = CartReminder::query()->sole();
            $this->assertSame(CartReminder::FAILED, $row->status);
            $this->assertNull($row->token_hash);
            $this->assertNull($row->unsubscribe_hash);
        });

        // 次の回でも送り直さない（Mail::to は 1 回だけ）
        $this->travel(2)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        $this->assertSame(CartReminder::FAILED, $this->inside('cra', fn (): string => CartReminder::query()->sole()->status));
    }

    public function test_the_cart_link_refuses_expired_and_other_shop_links_drops_items_that_cannot_be_bought_and_is_rate_limited(): void
    {
        $this->subscribe('hanako@example.com');
        $this->cancel($this->order([$this->mug => 1, $this->tote => 2]));
        $this->travel(4)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        $path = (string) parse_url(Mail::sent(CartReminderMail::class)->first()->cartUrl, PHP_URL_PATH);

        // ほかの店では同じリンクは使えない（その店の DB には無い）
        $this->tenant('crb', 'ほかの店');
        $this->inside('crb', fn () => app(PluginStates::class)->set('cart-reminder', true, 'test'));
        $this->shop('GET', $path, shop: 'crb')->assertNotFound()->assertSee('このリンクは使えません');
        $this->shop('GET', '/p/cart-reminder/'.str_repeat('A', 43))->assertNotFound()->assertSee('このリンクは使えません');

        // 売り切れの商品は外し、残りを戻す（外した名前を一言で）
        $this->inside('cra', function (): void {
            $tote = ListingVariant::query()->where('listing_id', $this->tote)->firstOrFail();
            app(StockService::class)->adjust($tote, Location::query()->firstOrFail(), -10);
        });
        $this->shop('GET', $path)->assertRedirect('http://cra.localhost/cart')
            ->assertSessionHas('cart_message', fn (string $m): bool => str_contains($m, '帆布のトート') && str_contains($m, 'カートに戻せなかったため外しました'));
        $this->assertEquals([$this->mug => 1], $this->cartLines());

        // 全部買えなければカートには入れず、断りの一言
        $this->cart = null;
        $this->inside('cra', fn () => Listing::query()->whereKey($this->mug)->update(['status' => 'draft']));
        $this->shop('GET', $path)->assertRedirect('http://cra.localhost/cart')->assertSessionHas('cart_error');
        $this->assertNull($this->cart);

        // 期限（7 日）を過ぎたリンク
        $this->travel(7)->days();
        $this->shop('GET', $path)->assertNotFound()->assertSee('期限');

        // 接続元ごとに 1 時間 30 回まで（合い言葉の総当たりを止める。期限切れのリンクを開いた 1 回を含めて 30 回）
        for ($i = 0; $i < 29; $i++) {
            $this->shop('GET', '/p/cart-reminder/'.str_repeat('B', 43));
        }
        $this->shop('GET', '/p/cart-reminder/'.str_repeat('C', 43))->assertStatus(429);
    }

    public function test_the_unsubscribe_link_stops_the_newsletter_consent_and_no_more_mails_come(): void
    {
        $this->subscribe('hanako@example.com');
        $this->cancel($this->order([$this->mug => 1]));
        $this->travel(4)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        $path = (string) parse_url(Mail::sent(CartReminderMail::class)->first()->unsubscribeUrl, PHP_URL_PATH);

        // ショップが準備中に戻っても、停止の画面は開ける（受信拒否は必ず受ける）。「カートに戻す」は関所の内側
        $this->inside('cra', fn () => app(StorefrontAccessService::class)->setStatus(ShopStatus::Preparing, 'test'));
        $cart = (string) parse_url(Mail::sent(CartReminderMail::class)->first()->cartUrl, PHP_URL_PATH);
        $this->shop('GET', $cart)->assertRedirect('http://cra.localhost/password');

        // 開いただけでは止めない。メールのソフトのワンクリックで止まる
        $this->shop('GET', $path)->assertOk()->assertSee('data-test="cart-reminder-unsubscribe"', false)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(SubscriberStatus::Active, $this->subscriber('hanako@example.com')->status);
        $this->shop('POST', $path, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('配信を停止しました');
        $row = $this->subscriber('hanako@example.com');
        $this->assertSame(SubscriberStatus::Unsubscribed, $row->status);
        $this->assertSame('one_click', $row->unsubscribed_via);
        $this->assertNotNull($this->inside('cra', fn () => CartReminder::query()->sole()->unsubscribed_at));
        $this->shop('GET', $path)->assertOk()->assertSee('すでに停止しています');

        // 7 日の後に消えた注文にも、もう送らない
        $this->travel(8)->days();
        $this->cancel($this->order([$this->mug => 1]));
        $this->travel(4)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertSent(CartReminderMail::class, 1);
        $this->assertSame(['no_consent'], $this->reasons());
    }

    public function test_a_shop_that_stopped_using_the_app_sends_nothing_and_its_links_are_gone(): void
    {
        $this->subscribe('hanako@example.com');
        $this->cancel($this->order([$this->mug => 1]));
        $this->inside('cra', fn () => app(PluginStates::class)->set('cart-reminder', false, 'test'));
        $this->travel(4)->hours();
        $this->artisan('cart-reminders:run')->assertSuccessful();
        Mail::assertNotSent(CartReminderMail::class);
        $this->assertSame(0, $this->inside('cra', fn (): int => CartReminder::query()->count()));
        $this->shop('GET', '/p/cart-reminder/'.str_repeat('A', 43))->assertNotFound();
    }

    /**
     * お知らせのメールの受け取り（メルマガの App の購読）を、決まった状態で作る
     */
    private function subscribe(string $email, SubscriberStatus $status = SubscriberStatus::Active): void
    {
        $this->inside('cra', function () use ($email, $status): void {
            $now = CarbonImmutable::now();
            $row = new NewsletterSubscriber(['email' => $email, 'status' => $status, 'source' => 'form', 'consented_at' => $now]);
            $row->forceFill(match ($status) {
                SubscriberStatus::Active => ['confirmed_at' => $now],
                SubscriberStatus::Pending => ['token_hash' => hash('sha256', $email), 'expires_at' => $now->addDay()],
                SubscriberStatus::Unsubscribed => ['confirmed_at' => $now, 'unsubscribed_at' => $now, 'unsubscribed_via' => 'link'],
            })->save();
        });
    }

    private function subscriber(string $email): NewsletterSubscriber
    {
        return $this->inside('cra', fn () => NewsletterSubscriber::query()->where('email_index', BlindIndex::of('email', $email))->sole());
    }

    /**
     * ショップの注文を確定する（支払いの画面の前まで）
     *
     * @param  array<string, int>  $items  商品の ID ごとの数
     */
    private function order(array $items, string $email = 'hanako@example.com'): string
    {
        return $this->inside('cra', function () use ($items, $email): string {
            $carts = app(CartService::class);
            [$cart] = $carts->create();

            foreach ($items as $listing => $quantity) {
                $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $listing)->value('id'), $quantity);
            }

            $input = CheckoutInput::fromArray(['buyer' => ['email' => $email, 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null]);
            $this->assertNotNull($input);

            return app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), CheckoutSummary::fingerprintOf($carts->view($cart)))->id;
        });
    }

    /**
     * 払われないまま取り消す（期限切れの片付けと同じ道）
     */
    private function cancel(string $order): void
    {
        $this->inside('cra', function () use ($order): void {
            DB::transaction(function () use ($order): void {
                app(EventStore::class)->lock();
                app(PaymentNotices::class)->cancelUnpaidOrder(Transaction::query()->lockForUpdate()->findOrFail($order), 'system:expiry');
            });
        });
    }

    private function pay(string $order): void
    {
        $this->inside('cra', function () use ($order): void {
            $sale = Transaction::query()->findOrFail($order);
            app(PaymentService::class)->startCheckout($sale, 'x', 'y');
            $ref = PaymentAttempt::query()->where('transaction_id', $order)->sole()->provider_ref;
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$order, 'checkout.session.completed', $ref, $order, $sale->total_amount, 'jpy', 'pi_'.$order));
        });
    }

    /**
     * 送らなかった理由（作った順）
     *
     * @return list<string>
     */
    private function reasons(): array
    {
        return $this->inside('cra', fn (): array => CartReminder::query()->whereNotNull('reason')->orderBy('created_at')->orderBy('id')->pluck('reason')->all());
    }

    /**
     * ブラウザのカートの中身（商品の ID ごとの数）
     *
     * @return array<string, int>
     */
    private function cartLines(): array
    {
        return $this->inside('cra', function (): array {
            $cart = Cart::query()->where('token_hash', hash('sha256', (string) $this->cart))->sole();

            return CartLine::query()->where('cart_id', $cart->id)->pluck('quantity', 'listing_id')->map(static fn (mixed $q): int => (int) $q)->all();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $method, string $path, array $data = [], string $shop = 'cra'): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->cart !== null) {
            $this->withCookie(CartCookie::NAME, $this->cart);
        }

        $this->app['cookie']->flushQueuedCookies();
        $url = "http://{$shop}.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $this->cart = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->cart;

        return $response;
    }
}
