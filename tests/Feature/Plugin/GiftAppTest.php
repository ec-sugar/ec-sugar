<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Discount\Services\CouponService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Orders\OrderMail;
use App\Storefront\Orders\OrderReader;
use EcSugar\PluginGift\GiftCheckout;
use EcSugar\PluginGift\GiftOrders;
use EcSugar\PluginGift\GiftRefused;
use EcSugar\PluginGift\Models\GiftOrder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 贈り物の App（ADR 0055 の区切り 1）: 注文の入力で「贈り物として送る」を選ぶと、お届け先の無い受渡で注文になり、
 * 払いが確かになったら受け取りのリンクができる。受け取る人はリンクからお届け先を入れ、確定で受渡のお届け先になる（1 回だけ）。
 * 受け取る人の画面に値段と買う人の名前と連絡先を出さない。リンクは作り直すと前のものが使えない。使っていない店では選べない
 */
final class GiftAppTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    private const array BUYER = ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => 'ビル 5F'];

    private const array RECIPIENT = ['name' => '鈴木 一郎', 'phone' => '0111234567', 'postal' => '0600001', 'prefecture' => '北海道', 'city' => '札幌市中央区', 'line1' => '北一条西 2-1', 'line2' => ''];

    private FakeStripe $stripe;

    private ?string $cart = null;

    private string $host = 'gfa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->stripe = FakeStripe::install();

        foreach (['gfa', 'gfb'] as $shop) {
            $this->tenant($shop, "贈り物の店 {$shop}");
            $this->inside($shop, function (): void {
                app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
                app(ShopSettingsService::class)->setShipping(['fee' => 800, 'free_over' => null], 'test');
                app(PluginStates::class)->set('gift', true, 'test');
            });
        }
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_shop_without_the_app_cannot_choose_a_gift(): void
    {
        $this->inside('gfa', fn () => app(PluginStates::class)->set('gift', false, 'test'));
        $listing = $this->listing('焼き菓子の詰め合わせ', 3000);
        $this->send('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);

        $this->assertStringNotContainsString('data-test="gift-fields"', (string) $this->send('GET', '/checkout')->assertOk()->getContent());

        // 欄が無くても送られてきたら断る（お届け先を入れてもらう）
        $this->send('POST', '/checkout', ['buyer' => self::BUYER, 'same_as_buyer' => '1', 'gift' => ['on' => '1', 'sender' => 'はなこ']])
            ->assertRedirect()->assertSessionHasErrors('gift.on');
        $this->assertSame(404, $this->send('GET', '/p/gift/account')->getStatusCode());
    }

    public function test_a_gift_waits_for_the_recipient_who_sees_no_prices_and_fills_in_the_address_once(): void
    {
        Mail::fake();
        $listing = $this->listing('焼き菓子の詰め合わせ', 3000);
        $this->send('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '2']);

        // どのテーマの注文の入力にも「贈り物として送る」と、お届け先の欄の印
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->inside('gfa', fn () => Tenant::query()->findOrFail('gfa')->forceFill(['theme' => $theme])->save());
            $html = (string) $this->send('GET', '/checkout')->assertOk()->getContent();
            $this->assertStringContainsString('data-test="gift-fields"', $html, $theme);
            $this->assertStringContainsString('data-ship-to', $html, $theme);
        }

        // お届け先は入れない。メッセージは文のまま（HTML は効かない）
        $order = $this->placeGift('はなこより', "お誕生日おめでとう！\n<b>いつもありがとう</b>");

        $this->inside('gfa', function () use ($order): void {
            $fulfillment = Fulfillment::query()->where('transaction_id', $order)->sole();
            $this->assertTrue($fulfillment->awaitingAddress());
            $this->assertSame(1, DomainEvent::query()->where('type', 'order.gift_placed')->where('aggregate_id', $order)->count());
            $gift = GiftOrder::query()->where('transaction_id', $order)->sole();
            $this->assertSame(['preparing', null], [$gift->status, $gift->token_hash]);
            // 差出人とメッセージは店の鍵で暗号化（平文は残らない）
            $raw = (array) DB::table('gift_orders')->where('id', $gift->id)->first();
            $this->assertStringNotContainsString('はなこ', (string) $raw['sender']);
            $this->assertStringNotContainsString('お誕生日', (string) $raw['message']);
            $this->assertSame('はなこより', $gift->sender);
            // 送料はいつもの送料（今は全国一律）
            $this->assertSame(6800, (int) Transaction::query()->findOrFail($order)->total_amount);
        });

        // 払う前: 受け付けた画面は「お支払いの確認待ち」、リンクはまだ無い
        $complete = (string) $this->send('GET', '/checkout/complete')->assertOk()->getContent();
        $this->assertStringContainsString('data-gift-status="preparing"', $complete);
        $this->assertStringNotContainsString('data-test="gift-link"', $complete);

        $this->pay($order, 6800);
        [$token, $mail] = $this->linkFromMail($order);

        $this->inside('gfa', function () use ($order, $token): void {
            $gift = GiftOrder::query()->where('transaction_id', $order)->sole();
            $this->assertSame('waiting', $gift->status);
            $this->assertSame(hash('sha256', $token), $gift->token_hash);
            $this->assertStringNotContainsString($token, (string) DB::table('gift_orders')->where('id', $gift->id)->value('token_sealed'));
            $this->assertEqualsWithDelta(now()->addDays(14)->getTimestamp(), $gift->expires_at?->getTimestamp(), 5);

            // お届け先が入るまで発送できない
            try {
                app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($order), (string) Fulfillment::query()->where('transaction_id', $order)->value('id'), 'yamato', '1', 'operator:test');
                $this->fail('お届け先の無い贈り物を発送できた');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('お届け先が決まっていない', $e->getMessage());
            }
        });

        // 知らせのメールにリンク・LINE で送る・作り直す
        $this->assertStringContainsString('line.me/R/share?text=', (string) $mail->order->gift?->lineUrl());
        $this->assertNotNull($mail->order->gift?->manageUrl);

        // 受け付けた画面（App のタグを差し込む画面）には、受け取りのリンクそのものを出さず、買う人だけが開ける画面への入口だけ
        $complete = (string) $this->send('GET', '/checkout/complete')->getContent();
        $this->assertStringContainsString('data-gift-status="waiting"', $complete);
        $this->assertStringNotContainsString($token, $complete);
        $this->assertStringContainsString('/p/gift/orders/'.$order, $complete);
        $manage = (string) $this->send('GET', '/p/gift/orders/'.$order)->assertOk()->getContent();
        $this->assertStringContainsString($token, $manage);

        // 受け取る人の画面: 差出人・メッセージ・商品の名前と数。値段・買う人の名前と連絡先は出さない
        $this->flushSession();
        $page = $this->send('GET', '/p/gift/'.$token)->assertOk();
        $page->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('noindex', (string) $page->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $html = (string) $page->getContent();
        foreach (['はなこより', 'お誕生日おめでとう', '&lt;b&gt;いつもありがとう&lt;/b&gt;', '焼き菓子の詰め合わせ × 2', 'data-test="gift-address-form"'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach (['¥', '3,000', '6,800', '山田', 'hanako@example.com', '神宮前', '09012345678', '<b>いつも'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, $secret);
        }

        // 足りないお届け先は断る（入れた値は残す）
        $this->send('POST', '/p/gift/'.$token, ['address' => [...self::RECIPIENT, 'city' => '']])->assertRedirect('http://gfa.localhost/p/gift/'.$token);
        $this->assertStringContainsString('data-test="address-error-city"', (string) $this->send('GET', '/p/gift/'.$token)->getContent());
        $this->inside('gfa', fn () => $this->assertTrue(Fulfillment::query()->where('transaction_id', $order)->sole()->awaitingAddress()));

        // 入れて確かめる → 確定
        $this->send('POST', '/p/gift/'.$token, ['address' => self::RECIPIENT])->assertRedirect('http://gfa.localhost/p/gift/'.$token.'/confirm');
        $confirm = (string) $this->send('GET', '/p/gift/'.$token.'/confirm')->assertOk()->getContent();
        $this->assertStringContainsString('鈴木 一郎', $confirm);
        $this->inside('gfa', fn () => $this->assertTrue(Fulfillment::query()->where('transaction_id', $order)->sole()->awaitingAddress()));
        $this->send('POST', '/p/gift/'.$token.'/confirm')->assertRedirect('http://gfa.localhost/p/gift/'.$token);

        $this->inside('gfa', function () use ($order): void {
            $fulfillment = Fulfillment::query()->where('transaction_id', $order)->sole();
            $this->assertSame(['鈴木 一郎', '0600001', '北海道'], [$fulfillment->address['name'] ?? null, $fulfillment->address['zip'] ?? null, $fulfillment->address['prefecture'] ?? null]);
            $this->assertStringNotContainsString('鈴木', (string) DB::table('fulfillments')->where('id', $fulfillment->id)->value('address'));
            $this->assertSame('claimed', GiftOrder::query()->where('transaction_id', $order)->value('status'));
            // 記録に個人情報を入れない
            foreach (DomainEvent::query()->whereIn('type', ['gift.placed', 'gift.link_created', 'gift.claimed', 'fulfillment.address_set'])->get() as $event) {
                $this->assertStringNotContainsString('鈴木', (string) json_encode($event->payload, JSON_UNESCAPED_UNICODE));
            }
        });
        $this->assertStringContainsString('data-test="gift-claimed"', (string) $this->send('GET', '/p/gift/'.$token)->getContent());

        // 2 回目（リンクを知った別の人）は書き換えられない
        $this->send('POST', '/p/gift/'.$token, ['address' => [...self::RECIPIENT, 'name' => '別の人']]);
        $this->send('POST', '/p/gift/'.$token.'/confirm');
        $this->inside('gfa', fn () => $this->assertSame('鈴木 一郎', Fulfillment::query()->where('transaction_id', $order)->sole()->address['name'] ?? null));

        // お届け先が入れば発送できる。受け取る人の画面は「発送しました」と送り状の番号だけ。発送の知らせには値段を出さない
        $this->inside('gfa', fn () => app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($order), (string) Fulfillment::query()->where('transaction_id', $order)->value('id'), 'yamato', '1234-5678', 'operator:test'));
        $shipped = (string) $this->send('GET', '/p/gift/'.$token)->getContent();
        $this->assertStringContainsString('data-test="gift-shipped"', $shipped);
        $this->assertStringContainsString('1234-5678', $shipped);
        Mail::assertSent(OrderMail::class, function (OrderMail $mail): bool {
            if ($mail->kind !== 'order_shipped') {
                return false;
            }

            $mail->assertDontSeeInText('¥')->assertDontSeeInHtml('¥')->assertSeeInText('焼き菓子の詰め合わせ × 2');

            return true;
        });
    }

    public function test_the_link_works_only_for_its_shop_and_renewing_it_stops_the_old_one(): void
    {
        Mail::fake();
        $listing = $this->listing('花束', 5000);
        $this->send('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);
        $order = $this->placeGift('', '');
        $this->pay($order, 5800);
        [$token] = $this->linkFromMail($order);

        // 差出人を空にすると、ご依頼主のお名前
        $this->inside('gfa', fn () => $this->assertSame('山田 花子', GiftOrder::query()->where('transaction_id', $order)->sole()->sender));

        // ほかの店では使えない・違う token は使えない（同じ「使えません」で、あるかどうかを教えない）
        $this->host = 'gfb';
        $this->assertSame(404, $this->send('GET', '/p/gift/'.$token)->getStatusCode());
        $this->host = 'gfa';
        $wrong = substr($token, 0, -1).($token[-1] === 'A' ? 'B' : 'A');
        $this->assertStringContainsString('data-gift-state="invalid"', (string) $this->send('GET', '/p/gift/'.$wrong)->assertNotFound()->getContent());

        // ほかの人（セッションも署名も無い）は買う人の画面を開けない
        $this->flushSession();
        $this->send('GET', '/p/gift/orders/'.$order)->assertNotFound();
        $this->send('POST', '/p/gift/orders/'.$order.'/renew')->assertNotFound();

        // 署名つきのリンク（メール）で開くと、作り直せる
        $signed = $this->inside('gfa', fn (): string => GiftCheckout::signedManagePath($order));
        $this->send('GET', $signed)->assertOk();
        $this->send('POST', '/p/gift/orders/'.$order.'/renew')->assertRedirect('http://gfa.localhost/p/gift/orders/'.$order);
        $renewed = (string) $this->send('GET', '/p/gift/orders/'.$order)->getContent();
        $this->assertStringNotContainsString($token, $renewed);
        preg_match('#/p/gift/([A-Za-z0-9_-]{43})"#', $renewed, $m);
        $this->assertNotEmpty($m[1] ?? null);

        // 前のリンクは使えず、新しいリンクで受け取れる
        $this->assertSame(404, $this->send('GET', '/p/gift/'.$token)->getStatusCode());
        $this->send('POST', '/p/gift/'.$token, ['address' => self::RECIPIENT]);
        $this->send('POST', '/p/gift/'.$token.'/confirm');
        $this->inside('gfa', fn () => $this->assertTrue(Fulfillment::query()->where('transaction_id', $order)->sole()->awaitingAddress()));
        $this->assertStringContainsString('data-test="gift-address-form"', (string) $this->send('GET', '/p/gift/'.$m[1])->assertOk()->getContent());
        $this->inside('gfa', fn () => $this->assertSame(1, DomainEvent::query()->where('type', 'gift.link_renewed')->count()));
    }

    public function test_a_gift_being_cancelled_cannot_be_claimed_even_mid_refund(): void
    {
        Mail::fake();
        $listing = $this->listing('花束', 5000);
        $this->send('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);
        $order = $this->placeGift('はなこ', '');
        $this->pay($order, 5800);
        [$token] = $this->linkFromMail($order);
        $this->send('POST', '/p/gift/'.$token, ['address' => self::RECIPIENT]);

        // 店の人が取り消して返金を頼んでいる間に、受け取る人が確定を押す → 断る（受け取ったのに取り消される、を作らない）
        $claimed = null;
        $this->stripe->before = function () use ($token, &$claimed): void {
            $this->inside('gfa', function () use ($token, &$claimed): void {
                try {
                    app(GiftOrders::class)->claim($token, self::RECIPIENT);
                    $claimed = true;
                } catch (GiftRefused) {
                    $claimed = false;
                }
            });
            tenancy()->initialize(Tenant::query()->findOrFail('gfa'));
        };
        $this->inside('gfa', fn () => app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($order), 'operator:test'));
        $this->assertFalse($claimed);
        $this->assertCount(1, $this->stripe->refunds);

        // 取り消した後も受け取れない。画面は「取り消されました」
        $this->send('POST', '/p/gift/'.$token.'/confirm');
        $this->inside('gfa', fn () => $this->assertTrue(Fulfillment::query()->where('transaction_id', $order)->sole()->address === null));
        $this->assertStringContainsString('data-test="gift-cancelled"', (string) $this->send('GET', '/p/gift/'.$token)->getContent());
    }

    public function test_a_coupon_works_on_a_gift_and_the_order_page_links_to_the_buyers_gift_page(): void
    {
        Mail::fake();
        $this->inside('gfa', fn () => app(CouponService::class)->create(['code' => 'welcome-500', 'kind' => 'fixed', 'value' => 500, 'name' => 'テスト'], 'test'));
        $listing = $this->listing('花束', 5000);
        $this->send('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);
        $this->send('POST', '/cart/coupon', ['code' => 'welcome-500'])->assertRedirect();

        // クーポンの値引きと、お届け先の無い受渡が両方効く（ADR 0054 の 9）
        $order = $this->placeGift('はなこ', '');
        $this->inside('gfa', function () use ($order): void {
            $transaction = Transaction::query()->findOrFail($order);
            $this->assertSame(5300, (int) $transaction->total_amount);
            $this->assertSame(-500, (int) $transaction->lines()->where('kind', 'discount')->sole()->amount);
            $this->assertTrue(Fulfillment::query()->where('transaction_id', $order)->sole()->awaitingAddress());
        });
        $this->pay($order, 5300);
        [$token] = $this->linkFromMail($order);

        // ご注文の確認（メールの署名つきのリンク）に、App のご注文のリンクで贈り物の画面への入口。別のセッションでも開ける（署名つき）
        $this->flushSession();
        $path = $this->inside('gfa', fn (): string => OrderReader::link(Transaction::query()->findOrFail($order), ''));
        $page = (string) $this->send('GET', $path)->assertOk()->getContent();
        $this->assertStringContainsString('data-test="order-link-gift"', $page);
        // ご注文の状況には受け取りのリンクそのものは出さない（入口だけ）
        $this->assertStringNotContainsString($token, $page);
        preg_match('#href="(/p/gift/orders/[^"]+)"#', $page, $link);
        $this->flushSession();
        $this->assertStringContainsString($token, (string) $this->send('GET', html_entity_decode($link[1]))->assertOk()->getContent());
    }

    public function test_the_message_refuses_control_and_direction_marks_and_long_text(): void
    {
        $listing = $this->listing('花束', 5000);
        $this->send('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);

        foreach ([['sender' => "はな\u{202E}こ", 'message' => ''], ['sender' => 'はなこ', 'message' => "あ\u{0007}い"], ['sender' => 'はなこ', 'message' => str_repeat('あ', 501)], ['sender' => str_repeat('あ', 51), 'message' => '']] as $gift) {
            $this->send('POST', '/checkout', ['buyer' => self::BUYER, 'same_as_buyer' => '0', 'gift' => ['on' => '1', ...$gift]])->assertSessionHasErrors();
        }

        // 改行は使える（\r\n は \n に）
        $this->send('POST', '/checkout', ['buyer' => self::BUYER, 'same_as_buyer' => '0', 'gift' => ['on' => '1', 'sender' => 'はなこ', 'message' => "1 行目\r\n2 行目"]])
            ->assertRedirect('http://gfa.localhost/checkout/confirm');
        $this->assertStringContainsString("1 行目\n2 行目", (string) $this->send('GET', '/checkout/confirm')->getContent());
    }

    public function test_the_recipient_page_has_rate_limits(): void
    {
        $wrong = str_repeat('a', 43);

        for ($i = 0; $i < 60; $i++) {
            $this->send('GET', '/p/gift/'.$wrong)->assertNotFound();
        }

        $this->send('GET', '/p/gift/'.$wrong)->assertStatus(429);

        // token ごとの送る回数
        Cache::store()->flush();
        for ($i = 0; $i < 10; $i++) {
            $this->send('POST', '/p/gift/'.$wrong, ['address' => self::RECIPIENT])->assertRedirect();
        }

        $this->send('POST', '/p/gift/'.$wrong, ['address' => self::RECIPIENT])->assertStatus(429);
    }

    public function test_a_konbini_gift_gets_its_link_only_after_the_money_arrives(): void
    {
        Mail::fake();
        $this->inside('gfa', fn () => app(PaymentMethodSettings::class)->update(['konbini' => true, 'paypay' => false, 'konbini_days' => 3], 'test'));
        $listing = $this->listing('焼き菓子の詰め合わせ', 3000);
        $this->send('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);
        $order = $this->placeGift('はなこより', 'おめでとう');

        // コンビニ払いの払込票が出た（入金待ち。ADR 0058）: 受け取りのリンクはまだ作らない
        $this->inside('gfa', function () use ($order): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $order)->sole();
            $this->assertSame('card,konbini:3', $attempt->methods);
            $this->assertSame('awaiting', app(PaymentNotices::class)->awaitPayment('stripe', 'evt_wait_'.$order, 'checkout.session.completed', $attempt->provider_ref, $order, 3800, 'jpy', 'konbini', now()->addDays(3)->toImmutable(), null));
            $gift = GiftOrder::query()->where('transaction_id', $order)->sole();
            $this->assertSame(['preparing', null], [$gift->status, $gift->token_hash]);
        });
        Mail::assertNotSent(OrderMail::class, fn (OrderMail $mail): bool => $mail->kind === 'order_paid');

        // お店で払われた: リンクを作り、知らせのメールに載せる
        $this->pay($order, 3800);
        $this->inside('gfa', fn () => $this->assertSame('waiting', GiftOrder::query()->where('transaction_id', $order)->sole()->status));
        $this->linkFromMail($order);
    }

    public function test_a_free_gift_gets_its_link_at_once(): void
    {
        Mail::fake();
        $this->inside('gfa', function (): void {
            app(ShopSettingsService::class)->setShipping(['fee' => 0, 'free_over' => null], 'test');
            app(ShopSettingsService::class)->setSellFreeItems(true, 'test');
        });
        $listing = $this->listing('試供品', 0);
        $this->send('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);
        $order = $this->placeGift('はなこ', '', free: true);

        $this->inside('gfa', fn () => $this->assertSame('waiting', GiftOrder::query()->where('transaction_id', $order)->value('status')));
        $this->linkFromMail($order);
    }

    /**
     * 贈り物として注文を確定する（お届け先は入れない）
     */
    private function placeGift(string $sender, string $message, bool $free = false): string
    {
        $this->send('POST', '/checkout', ['buyer' => self::BUYER, 'same_as_buyer' => '0', 'recipient' => ['name' => ''], 'gift' => ['on' => '1', 'sender' => $sender, 'message' => $message]])
            ->assertRedirect('http://gfa.localhost/checkout/confirm');
        $confirm = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
        $this->assertStringContainsString('data-test="confirm-gift"', $confirm);
        $this->assertStringContainsString('贈り物（お届け先は受け取る方が入力します）', $confirm);
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $response = $this->send('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]]);
        $free ? $response->assertRedirect('http://gfa.localhost/checkout/complete') : $response->assertStatus(303);

        return $this->inside('gfa', fn (): string => (string) CheckoutOrder::query()->latest('created_at')->value('transaction_id'));
    }

    private function pay(string $order, int $amount): void
    {
        $this->inside('gfa', function () use ($order, $amount): void {
            $ref = (string) PaymentAttempt::query()->where('transaction_id', $order)->value('provider_ref');
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$order, 'checkout.session.completed', $ref, $order, $amount, 'jpy', 'pi_test_1'));
        });
    }

    /**
     * 注文の知らせのメールから受け取りのリンクの token を読む
     *
     * @return array{0: string, 1: OrderMail}
     */
    private function linkFromMail(string $order): array
    {
        $found = null;
        Mail::assertSent(OrderMail::class, function (OrderMail $mail) use ($order, &$found): bool {
            if ($mail->kind === 'order_paid' && $mail->order->orderId === $order) {
                $found = $mail;

                return true;
            }

            return false;
        });
        $this->assertInstanceOf(OrderMail::class, $found);
        $url = (string) $found->order->gift?->url;
        $this->assertMatchesRegularExpression('#\Ahttp://gfa\.localhost/p/gift/[A-Za-z0-9_-]{43}\z#', $url);
        $found->assertSeeInText($url)->assertSeeInHtml($url, false);

        return [substr($url, -43), $found];
    }

    private function listing(string $title, int $amount): string
    {
        return $this->inside('gfa', function () use ($title, $amount): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 5);

            return $listing->id;
        });
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

        $url = "http://{$this->host}.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $this->cart = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->cart;

        return $response;
    }
}
