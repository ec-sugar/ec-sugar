<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Plugin\PluginTenants;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Orders\OrderMail;
use Carbon\CarbonImmutable;
use EcSugar\PluginGift\GiftRounds;
use EcSugar\PluginGift\Mail\GiftMail;
use EcSugar\PluginGift\Models\GiftOrder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 贈り物の App（ADR 0055 の区切り 2）: 期限の 3 日前の知らせ・期限を過ぎた贈り物の取り消しと全額の返金（1 回だけ。同時に 2 つ走っても 1 回。
 * 返金できなければ止めて、店に知らせる）、買う人の取り消し、店の人の取り消しと合わせ、受け取られた知らせ、管理画面の表示
 */
final class GiftExpiryTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const array BUYER = ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''];

    private const array RECIPIENT = ['name' => '鈴木 一郎', 'phone' => '0111234567', 'postal' => '0600001', 'prefecture' => '北海道', 'city' => '札幌市中央区', 'line1' => '北一条西 2-1', 'line2' => ''];

    private FakeStripe $stripe;

    private ?string $cart = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->stripe = FakeStripe::install();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Asia/Tokyo'));
        $this->tenant('gex', '贈り物の店');
        $this->inside('gex', function (): void {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(ShopSettingsService::class)->setShipping(['fee' => 800, 'free_over' => null], 'test');
            app(PluginStates::class)->set('gift', true, 'test');
            app(PluginSettings::class)->update('gift', ['expiry_days' => '7'], 'test');
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_the_buyer_is_reminded_three_days_before_and_the_gift_is_refunded_once_at_the_deadline(): void
    {
        [$order, $listing] = $this->paidGift();
        $this->inside('gex', fn () => $this->assertSame('2026-11-12 10:00', GiftOrder::query()->where('transaction_id', $order)->sole()->expires_at?->timezone('Asia/Tokyo')->format('Y-m-d H:i')));

        // 4 日前: まだ知らせない
        $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00', 'Asia/Tokyo'));
        $this->runGifts();
        Mail::assertNotSent(GiftMail::class);

        // 3 日前を過ぎた: 1 回だけ知らせる（受け取りのリンクを添える）
        $this->travelTo(CarbonImmutable::parse('2026-11-09 11:00', 'Asia/Tokyo'));
        $this->runGifts();
        $this->runGifts();
        Mail::assertSent(GiftMail::class, 1);
        Mail::assertSent(GiftMail::class, fn (GiftMail $mail): bool => $mail->kind === 'expiring' && $mail->hasTo('hanako@example.com') && str_contains((string) $mail->facts['link'], '/p/gift/'));

        // 期限の前は取り消さない
        $this->assertSame([], $this->stripe->refunds);

        // 期限を過ぎた: 取り消して全額を返金し、在庫を戻す。何度流しても返金は 1 回
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:01', 'Asia/Tokyo'));
        $this->runGifts();
        $this->runGifts();
        $this->assertCount(1, $this->stripe->refunds);
        $this->assertSame(5800, $this->stripe->refunds[0]['amount']);
        $this->inside('gex', function () use ($order, $listing): void {
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($order)->status);
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $listing)->sum('reserved'));
            $gift = GiftOrder::query()->where('transaction_id', $order)->sole();
            $this->assertSame('expired', $gift->status);
            $this->assertNotNull($gift->closed_at);
            $this->assertSame('system:gifts', DomainEvent::query()->where('type', 'gift.cancelled')->sole()->actor);
        });
        Mail::assertSent(GiftMail::class, fn (GiftMail $mail): bool => $mail->kind === 'expired');

        // 受け取る人の画面は「期限が過ぎました」。受け取れない
        $token = $this->tokenOf($order);
        $this->assertStringContainsString('data-test="gift-expired"', (string) $this->shop('GET', '/p/gift/'.$token)->assertOk()->getContent());
        $this->shop('POST', '/p/gift/'.$token, ['address' => self::RECIPIENT]);
        $this->shop('POST', '/p/gift/'.$token.'/confirm');
        $this->inside('gex', fn () => $this->assertTrue(Fulfillment::query()->where('transaction_id', $order)->sole()->address === null));
    }

    public function test_a_shop_that_stops_using_the_app_still_reminds_and_refunds_waiting_gifts(): void
    {
        [$order, $listing] = $this->paidGift();
        $token = $this->tokenOf($order);
        $this->inside('gex', fn () => app(PluginStates::class)->set('gift', false, 'test'));

        // 受け取りの画面は止まる
        $this->shop('GET', '/p/gift/'.$token)->assertNotFound();

        // 期限の知らせは送る（開かないリンクは載せない）
        $this->travelTo(CarbonImmutable::parse('2026-11-09 11:00', 'Asia/Tokyo'));
        $this->runGifts();
        Mail::assertSent(GiftMail::class, fn (GiftMail $mail): bool => $mail->kind === 'expiring' && $mail->facts['link'] === null && $mail->facts['manage'] === null);

        // 期限で取り消して全額を返金し、在庫を戻す（1 回だけ）
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:01', 'Asia/Tokyo'));
        $this->runGifts();
        $this->runGifts();
        $this->assertCount(1, $this->stripe->refunds);
        $this->inside('gex', function () use ($order, $listing): void {
            $this->assertSame('expired', GiftOrder::query()->where('transaction_id', $order)->value('status'));
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $listing)->sum('reserved'));
            // 片付けが済んだら、もう回さない
            $this->assertFalse(GiftRounds::hasLeftover());
        });
        $this->assertSame(0, app(PluginTenants::class)->each('gift', static fn () => null, static fn (): bool => GiftRounds::hasLeftover()));
    }

    public function test_two_runs_at_once_refund_only_once(): void
    {
        [$order] = $this->paidGift();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00', 'Asia/Tokyo'));

        // 1 つ目の処理が決済代行へ返金を頼んでいる間に、2 つ目の処理が走る
        $inner = null;
        $this->stripe->before = function () use (&$inner): void {
            $inner = $this->inside('gex', fn (): array => app(GiftRounds::class)->run(CarbonImmutable::now()));
            tenancy()->initialize(Tenant::query()->findOrFail('gex'));
        };
        $outer = $this->inside('gex', fn (): array => app(GiftRounds::class)->run(CarbonImmutable::now()));

        $this->assertSame(0, $inner['expired'] ?? null);
        $this->assertSame(1, $outer['expired']);
        $this->assertCount(1, $this->stripe->refunds);
        $this->inside('gex', fn () => $this->assertSame('expired', GiftOrder::query()->where('transaction_id', $order)->value('status')));
    }

    public function test_a_failed_refund_stops_the_gift_and_tells_the_shop_without_refunding_again(): void
    {
        [$order] = $this->paidGift();
        $this->stripe->refundStatus = 'failed';
        $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00', 'Asia/Tokyo'));

        $this->runGifts();
        $this->inside('gex', function () use ($order): void {
            $this->assertSame('stopped', GiftOrder::query()->where('transaction_id', $order)->value('status'));
            // 注文は開いたまま（発送はできない）
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($order)->status);
        });
        $requests = count($this->stripe->requests);

        // 次の回も返金を頼み直さない
        $this->runGifts();
        $this->assertSame($requests, count($this->stripe->requests));
        Mail::assertNotSent(GiftMail::class, fn (GiftMail $mail): bool => $mail->kind === 'expired');

        // 店の人へ: ホームの「やること」と App の画面
        $this->enrolledOperator();
        $this->send('GET', '/api/tenants/gex/apps/gift/records?filter=stopped')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('rows.0.cells.status.label', '返金できなかった（要確認）');
        $this->send('GET', "/api/tenants/gex/transactions/{$order}")->assertOk()->assertJsonPath('transaction.gift.status', 'stopped');

        // 店の人が注文の画面で取り消し直す（返金が通る）と、贈り物も「取り消し」に合わせる
        $this->stripe->refundStatus = 'succeeded';
        $this->inside('gex', fn () => app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($order), 'operator:test'));
        $this->runGifts();
        $this->inside('gex', fn () => $this->assertSame('cancelled', GiftOrder::query()->where('transaction_id', $order)->value('status')));
    }

    public function test_the_buyer_cancels_before_it_is_claimed_but_not_after(): void
    {
        [$order, $listing] = $this->paidGift();

        // 買う人（このセッションで買った）が取り消す → 全額を返金、在庫を戻す、リンクは使えない
        $this->shop('GET', '/p/gift/orders/'.$order.'/cancel')->assertOk();
        $this->shop('POST', '/p/gift/orders/'.$order.'/cancel')->assertRedirect('http://gex.localhost/p/gift/orders/'.$order);
        $this->assertCount(1, $this->stripe->refunds);
        $this->inside('gex', function () use ($order, $listing): void {
            $this->assertSame('cancelled', GiftOrder::query()->where('transaction_id', $order)->value('status'));
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $listing)->sum('reserved'));
        });
        Mail::assertSent(GiftMail::class, fn (GiftMail $mail): bool => $mail->kind === 'cancelled');
        $this->shop('POST', '/p/gift/orders/'.$order.'/cancel');
        $this->assertCount(1, $this->stripe->refunds);

        // 受け取られた後は、買う人は取り消せない
        $this->cart = null;
        [$second] = $this->paidGift();
        $token = $this->tokenOf($second);
        $this->shop('POST', '/p/gift/'.$token, ['address' => self::RECIPIENT]);
        $this->shop('POST', '/p/gift/'.$token.'/confirm');
        Mail::assertSent(GiftMail::class, fn (GiftMail $mail): bool => $mail->kind === 'claimed' && ! str_contains(implode("\n", $mail->facts['items']), '鈴木'));
        $this->shop('GET', '/p/gift/orders/'.$second.'/cancel')->assertNotFound();
        $this->shop('POST', '/p/gift/orders/'.$second.'/cancel');
        $this->assertCount(1, $this->stripe->refunds);
        $this->inside('gex', fn () => $this->assertSame('claimed', GiftOrder::query()->where('transaction_id', $second)->value('status')));
    }

    public function test_the_admin_sees_the_gift_state_and_can_ship_only_after_it_is_claimed(): void
    {
        [$order] = $this->paidGift();
        $this->enrolledOperator();

        $detail = $this->send('GET', "/api/tenants/gex/transactions/{$order}")->assertOk();
        $detail->assertJsonPath('transaction.gift', ['status' => 'waiting', 'label' => '贈り物・受け取り待ち', 'expires_on' => '2026年11月12日'])
            ->assertJsonPath('transaction.actions.ship', false)->assertJsonPath('transaction.fulfillments.0.awaiting_address', true);

        // App の画面: 受け取り待ちの一覧。差出人・メッセージは出さない
        $this->send('GET', '/api/tenants/gex/app-pages')->assertOk()->assertJsonFragment(['plugin' => 'gift', 'title' => '贈り物']);
        $list = $this->send('GET', '/api/tenants/gex/apps/gift/records')->assertOk();
        $list->assertJsonPath('filter', 'waiting')->assertJsonPath('meta.total', 1)->assertJsonPath('rows.0.cells.order.id', $order);
        $id = (string) $list->json('rows.0.id');
        $record = $this->send('GET', "/api/tenants/gex/apps/gift/records/{$id}")->assertOk();
        $this->assertSame([], $record->json('record.actions'));
        foreach ([$list->getContent(), $record->getContent()] as $body) {
            foreach (['はなこ', 'おめでとう', '山田', 'hanako'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $body);
            }
        }

        // 受け取られると発送できる
        $token = $this->tokenOf($order);
        $this->shop('POST', '/p/gift/'.$token, ['address' => self::RECIPIENT]);
        $this->shop('POST', '/p/gift/'.$token.'/confirm');
        $this->send('GET', "/api/tenants/gex/transactions/{$order}")->assertOk()
            ->assertJsonPath('transaction.gift.label', '贈り物・受け取り済み')->assertJsonPath('transaction.actions.ship', true);
        $this->inside('gex', fn () => $this->assertFalse(Fulfillment::query()->where('transaction_id', $order)->sole()->awaitingAddress()));
    }

    /**
     * 贈り物を注文して払う
     *
     * @return array{0: string, 1: string} 注文と商品
     */
    private function paidGift(): array
    {
        $listing = $this->inside('gex', function (): string {
            $listing = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '花束', 'unit_amount' => 5000, 'status' => 'active']);
            app(StockService::class)->receive($listing, Location::query()->firstOrFail(), 5);

            return $listing->id;
        });
        $this->shop('POST', '/cart/items', ['listing_id' => $listing, 'quantity' => '1']);
        $this->shop('POST', '/checkout', ['buyer' => self::BUYER, 'same_as_buyer' => '0', 'gift' => ['on' => '1', 'sender' => 'はなこ', 'message' => 'おめでとう']])->assertRedirect();
        $confirm = (string) $this->shop('GET', '/checkout/confirm')->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $confirm, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $confirm, $fingerprint);
        $before = $this->inside('gex', fn (): array => CheckoutOrder::query()->pluck('transaction_id')->all());
        $this->shop('POST', '/checkout/confirm', ['order_key' => $key[1], 'fingerprint' => $fingerprint[1]])->assertStatus(303);
        // 時計を止めているので、作った時刻ではなく増えた注文で見分ける
        $order = $this->inside('gex', fn (): string => (string) CheckoutOrder::query()->whereNotIn('transaction_id', $before)->sole()->transaction_id);

        $this->inside('gex', function () use ($order): void {
            $ref = (string) PaymentAttempt::query()->where('transaction_id', $order)->value('provider_ref');
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$order, 'checkout.session.completed', $ref, $order, 5800, 'jpy', 'pi_'.$order));
            $this->assertSame('waiting', GiftOrder::query()->where('transaction_id', $order)->value('status'));
        });
        Mail::assertSent(OrderMail::class, fn (OrderMail $mail): bool => $mail->order->orderId === $order && $mail->order->gift?->url !== null);

        return [$order, $listing];
    }

    private function tokenOf(string $order): string
    {
        return $this->inside('gex', fn (): string => (string) GiftOrder::query()->where('transaction_id', $order)->sole()->token_sealed);
    }

    private function runGifts(): void
    {
        $this->artisan('gifts:run')->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->cart !== null) {
            $this->withCookie(CartCookie::NAME, $this->cart);
        }

        $url = "http://gex.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();
        $this->cart = $response->getCookie(CartCookie::NAME)?->getValue() ?? $this->cart;

        return $response;
    }
}
