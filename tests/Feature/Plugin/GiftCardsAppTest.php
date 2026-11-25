<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Services\ShopHome;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Outbox\EventRelay;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\GiftCard\Models\GiftCard;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\RefundResult;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionException;
use App\Models\Tenant;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Checkout\OrderException;
use Carbon\CarbonImmutable;
use Closure;
use EcSugar\PluginGiftCards\GiftCardIssuer;
use EcSugar\PluginGiftCards\GiftCardPurchases;
use EcSugar\PluginGiftCards\GiftCardSettings;
use EcSugar\PluginGiftCards\Mail\GiftCardMail;
use EcSugar\PluginGiftCards\Models\GiftCardOrder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * App「ギフトカード」（ADR 0076）。売る画面（会員でもゲストでも・カード払いだけ・消費税なし）と、払いが確かになったら 1 枚だけ発行して
 * コードをメールで届けること（コードは画面・記録・ログに無く、DB はハッシュと末尾だけ）。払われずに消えた注文には出さない。届かなかったら見回りが出し直す
 */
final class GiftCardsAppTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        FakeStripe::install();
        Mail::fake();
        $this->tenant = Tenant::create(['id' => 'gca', 'name' => '花の店']);
        $this->tenant->domains()->create(['domain' => 'gca.localhost']);
        $this->inside(function (): void {
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            // コンビニ払いを使う店でも、ギフトカードの注文にはカードだけ
            app(PaymentMethodSettings::class)->update(['konbini' => true, 'paypay' => false, 'konbini_days' => 3], 'test');
            app(PluginStates::class)->set('gift-cards', true, 'test');
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        CarbonImmutable::setTestNow();
        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_guest_buys_a_card_pays_by_card_and_the_code_is_mailed_once_without_being_kept(): void
    {
        // テストの鍵の店: 売る画面に「いまはテストの支払いです」（本物として売らない）
        $this->send('GET', '/p/gift-cards')->assertOk()->assertSee('¥3,000')->assertSee('¥5,000')->assertSee('¥10,000')->assertSee('発行から 180 日')->assertSee('いまはテストの支払いです');

        // 選択肢に無い額面・壊れたメールは断る
        $this->send('POST', '/p/gift-cards', ['amount' => 4000, 'name' => '山田 花子', 'email' => 'hanako@example.com'])->assertSessionHasErrors('amount');
        $this->send('POST', '/p/gift-cards', ['amount' => 5000, 'name' => '山田 花子', 'email' => 'hanako@example.com', 'recipient_email' => 'not-mail'])->assertSessionHasErrors('recipient_email');

        $this->send('POST', '/p/gift-cards', ['amount' => 5000, 'name' => '山田 花子', 'email' => 'hanako@example.com', 'recipient_email' => 'taro@example.com', 'message' => "お誕生日\nおめでとう"])
            ->assertRedirect('/p/gift-cards/confirm');
        $this->send('GET', '/p/gift-cards/confirm')->assertOk()->assertSee('ギフトカード ¥5,000 × 1')->assertSee('消費税がかかりません')->assertSee('払い戻し');
        $place = $this->send('POST', '/p/gift-cards/place');
        $place->assertRedirect();
        $this->assertStringStartsWith('https://checkout.stripe.com/', (string) $place->headers->get('Location'));

        [$sale, $attempt] = $this->inside(function (): array {
            $sale = Transaction::query()->sole();
            $line = $sale->lines()->sole();
            // 1 行・消費税なし・送料なし・受渡はデジタル
            $this->assertSame([5000, 0, 5000, 0, 'gift_card'], [(int) $sale->total_amount, (int) $sale->tax_amount, (int) $line->amount, (int) $line->tax_rate_bp, $line->kind]);
            $this->assertSame(FulfillmentMethod::Digital, Fulfillment::query()->where('transaction_id', $sale->id)->sole()->method);
            $this->assertSame('checkout', CheckoutOrder::query()->where('transaction_id', $sale->id)->value('pays_with'));
            // 店がコンビニ払いを使っていても、カードだけ（後から払いが届く手段は出さない）
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale->id)->sole();
            $this->assertSame('card', $attempt->methods);
            $order = GiftCardOrder::query()->findOrFail($sale->id);
            $this->assertSame(['taro@example.com', "お誕生日\nおめでとう", 180], [$order->recipient_email, $order->message, $order->valid_days]);
            // 贈る相手とメッセージは暗号化して持つ
            $raw = (array) DB::table('gift_cards_orders')->first();
            $this->assertStringNotContainsString('taro@example.com', (string) json_encode($raw));
            $this->assertSame(0, GiftCard::query()->count());

            return [$sale, $attempt];
        });

        // 払われた知らせが 2 回来ても、発行は 1 枚・メールは 1 回ずつ
        $this->inside(function () use ($sale, $attempt): void {
            $notices = app(PaymentNotices::class);
            $this->assertSame('paid', $notices->confirmPaid('stripe', 'evt_1', 'checkout.session.completed', $attempt->provider_ref, $sale->id, 5000, 'jpy', 'pi_gift'));
            $this->assertSame('seen', $notices->confirmPaid('stripe', 'evt_1', 'checkout.session.completed', $attempt->provider_ref, $sale->id, 5000, 'jpy', 'pi_gift'));
            $this->assertSame('already_paid', $notices->confirmPaid('stripe', 'evt_2', 'checkout.session.completed', $attempt->provider_ref, $sale->id, 5000, 'jpy', 'pi_gift'));
            event(new PaymentConfirmed($sale->id));
        });

        $code = null;
        Mail::assertSent(GiftCardMail::class, 2);
        Mail::assertSent(GiftCardMail::class, function (GiftCardMail $mail) use (&$code): bool {
            if ($mail->kind === 'buyer') {
                $code = $mail->facts['code'];
            }

            return $mail->hasTo($mail->kind === 'buyer' ? 'hanako@example.com' : 'taro@example.com');
        });
        Mail::assertSent(GiftCardMail::class, static fn (GiftCardMail $mail): bool => $mail->kind === 'recipient' && $mail->facts['message'] === "お誕生日\nおめでとう" && $mail->facts['buyerName'] === '山田 花子');
        $this->assertIsString($code);
        $this->assertMatchesRegularExpression('/\A[2-9A-HJKMNP-Z]{4}(-[2-9A-HJKMNP-Z]{4}){3}\z/', $code);

        $this->inside(function () use ($sale, $code): void {
            $card = GiftCard::query()->sole();
            $bare = str_replace('-', '', $code);
            // DB はハッシュと末尾 4 文字だけ。額面・期限・売った注文・届けた印
            $this->assertSame([GiftCardService::hash($bare), substr($bare, -4), 5000, $sale->id], [$card->code_hash, $card->last4, $card->amount, $card->purchase_transaction_id]);
            // 届けた印は宛先ごと
            $this->assertNotNull($card->mailed_at);
            $this->assertNotNull($card->recipient_mailed_at);
            // Stripe のテストのモード（cs_test_）で払われたので、テストの印（本番の鍵の店では使えない）
            $this->assertTrue($card->test);
            $this->assertSame(5000, app(GiftCardService::class)->balance($card->id));
            $this->assertSame($card->id, app(GiftCardService::class)->find($code)?->id);

            // 注文はデジタルの受渡を渡し終えて完了
            $this->assertSame(TransactionStatus::Completed, Transaction::query()->findOrFail($sale->id)->status);

            // コードは記録・カードの行のどこにも無い
            foreach (['gift_cards', 'gift_card_entries', 'domain_events', 'gift_cards_orders'] as $table) {
                $this->assertStringNotContainsString($bare, (string) json_encode(DB::table($table)->get()), $table);
            }
            $this->assertSame(1, DomainEvent::query()->where('type', 'gift_card.issued')->count());
        });

        // コードはお客さんの画面（お支払いの後の画面）にも出ない
        $this->assertStringNotContainsString($code, (string) $this->send('GET', '/p/gift-cards/done')->assertOk()->getContent());
    }

    public function test_an_order_that_is_never_paid_gets_no_card_and_the_sweep_does_not_issue_one(): void
    {
        $this->inside(function (): void {
            // まだ払われていない注文（支払いの画面を開いたまま）: 知らせが紛れても出さない
            $open = $this->order(5000);
            $this->assertFalse(app(GiftCardIssuer::class)->paid($open->id));
            $this->assertSame(['issued' => 0, 'resent' => 0], app(GiftCardIssuer::class)->catchUp(CarbonImmutable::now()));
            $this->assertSame(0, GiftCard::query()->count());

            // 払われずに消えた注文
            $sale = $this->order(3000);
            $this->assertTrue(app(SaleOrderService::class)->cancelIfUnpaid($sale, 'system:expiry'));

            $this->assertFalse(app(GiftCardIssuer::class)->paid($sale->id));
            $this->assertSame(['issued' => 0, 'resent' => 0], app(GiftCardIssuer::class)->catchUp(CarbonImmutable::now()));
            $this->assertSame(0, GiftCard::query()->count());
        });

        Mail::assertNothingSent();
    }

    public function test_a_card_whose_mail_failed_is_resent_with_a_new_code_by_the_sweep(): void
    {
        $this->inside(function (): void {
            $sale = $this->order(3000);
            EventRelay::muting(['gift-cards.issue'], function () use ($sale): void {
                $attempt = PaymentAttempt::query()->where('transaction_id', $sale->id)->sole();
                app(PaymentNotices::class)->confirmPaid('stripe', 'evt_x', 'checkout.session.completed', $attempt->provider_ref, $sale->id, 3000, 'jpy', 'pi_x');
            });

            // 払われた知らせの処理が落ちた（発行していない）: 見回りが発行して届ける
            $this->assertSame(0, GiftCard::query()->count());
            $this->assertSame(['issued' => 1, 'resent' => 0], app(GiftCardIssuer::class)->catchUp(CarbonImmutable::now()));
            $card = GiftCard::query()->sole();
            $this->assertNotNull($card->mailed_at);

            // 届けた印の無いカード（メールを送れなかった）: 15 分の後に、まだ使われていなければコードを出し直して送り直す
            DB::table('gift_cards')->where('id', $card->id)->update(['mailed_at' => null]);
            $before = $card->code_hash;
            $this->assertSame(['issued' => 0, 'resent' => 0], app(GiftCardIssuer::class)->catchUp(CarbonImmutable::now()));
            $this->assertSame(['issued' => 0, 'resent' => 1], app(GiftCardIssuer::class)->catchUp(CarbonImmutable::now()->addMinutes(16)));
            $after = GiftCard::query()->findOrFail($card->id);
            $this->assertNotSame($before, $after->code_hash);
            $this->assertNotNull($after->mailed_at);
            $this->assertSame(1, DomainEvent::query()->where('type', 'gift_card.code_reissued')->count());
        });

        Mail::assertSent(GiftCardMail::class, 2);
    }

    public function test_a_live_key_shop_shows_no_test_notice(): void
    {
        config(['payment.live_keys_allowed' => true]);
        $this->inside(fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'live', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])], 'test'));

        $this->send('GET', '/p/gift-cards')->assertOk()->assertDontSee('いまはテストの支払いです');
    }

    public function test_a_card_is_not_issued_while_the_order_is_being_cancelled_and_digital_delivery_refuses_it(): void
    {
        $this->inside(function (): void {
            $sale = $this->paidWithoutIssuing(3000);
            // 払われた直後（発行の前）に店の人が取消を押し、返金が途中
            DB::transaction(function () use ($sale): void {
                app(EventStore::class)->lock();
                $locked = Transaction::query()->lockForUpdate()->findOrFail($sale->id);
                app(PaymentRefunds::class)->openForCancel($locked, PaymentAttempt::query()->where('transaction_id', $sale->id)->sole(), 'pi_cancel', 'operator:test');
            });

            // 錠の中で取消の途中を見て、発行しない（見回りも）
            $this->assertFalse(app(GiftCardIssuer::class)->paid($sale->id));
            $this->assertSame(['issued' => 0, 'resent' => 0], app(GiftCardIssuer::class)->catchUp(CarbonImmutable::now()));
            $this->assertSame(0, GiftCard::query()->count());

            // デジタルの受渡を渡すのも、発送と同じ守りで断る
            try {
                app(SaleOrderService::class)->deliverDigital(Transaction::query()->findOrFail($sale->id), 'test');
                $this->fail('取消の途中の注文を渡した');
            } catch (TransactionException $e) {
                $this->assertStringContainsString('取消と返金の処理中', $e->getMessage());
            }
        });

        Mail::assertNothingSent();
    }

    public function test_sales_are_capped_per_day_by_email_and_source_and_a_refunded_sale_stops_its_card(): void
    {
        $this->inside(function (): void {
            app(PluginSettings::class)->update('gift-cards', ['daily_limit' => '20000'], 'test');
            $purchases = app(GiftCardPurchases::class);
            $purchases->place(10000, '山田 花子', 'hanako@example.com', null, null, null, Str::random(43), '198.51.100.1');
            $purchases->place(10000, '山田 花子', 'HANAKO@example.com', null, null, null, Str::random(43), '198.51.100.2');

            // 同じメール（大文字・小文字は同じ）で 24 時間に 20,000 円を超える
            $this->assertOrderRefused(fn () => $purchases->place(3000, '山田 花子', 'hanako@example.com', null, null, null, Str::random(43), '203.0.113.1'));
            // 別のメールでも、同じ接続元から超える
            $purchases->place(10000, '別の人', 'other@example.com', null, null, null, Str::random(43), '203.0.113.5');
            $purchases->place(10000, '三人目', 'third@example.com', null, null, null, Str::random(43), '203.0.113.5');
            $this->assertOrderRefused(fn () => $purchases->place(3000, '四人目', 'fourth@example.com', null, null, null, Str::random(43), '203.0.113.5'));
            // 取り消した注文は数えない
            $first = Transaction::query()->orderBy('created_at')->firstOrFail();
            app(SaleOrderService::class)->cancelIfUnpaid($first, 'test');
            $purchases->place(3000, '山田 花子', 'hanako@example.com', null, null, null, Str::random(43), '203.0.113.9');
            $this->assertSame(5, GiftCardOrder::query()->count());
        });

        // 売った注文が外で返金されたら、発行したカードを自動で止めてホームのやることに出す
        $this->inside(function (): void {
            $sale = $this->paidWithoutIssuing(3000);
            $this->assertTrue(app(GiftCardIssuer::class)->paid($sale->id));
            $card = GiftCard::query()->where('purchase_transaction_id', $sale->id)->sole();
            app(PaymentNotices::class)->refundUpdated('stripe', 'evt_refund', 'refund.created', new RefundResult('re_gc', 'succeeded', null, 3000, 'pi_x'));
            app(PaymentRefunds::class)->resolve(Refund::query()->sole(), 'recorded', 3000, 'test');

            $card = GiftCard::query()->findOrFail($card->id);
            $this->assertSame('refund', $card->stopped_reason);
            $this->assertSame(1, app(ShopHome::class)->todo()['gift_cards_stopped']);
            // 店の人が確かめて残高を 0 にすると、やることから消える
            app(GiftCardService::class)->adjust($card, -3000, '返金されたため', 'operator:test');
            $this->assertSame(0, app(ShopHome::class)->todo()['gift_cards_stopped']);
        });
    }

    public function test_a_code_is_reissued_at_most_three_times(): void
    {
        $this->inside(function (): void {
            $sale = $this->paidWithoutIssuing(3000);
            app(GiftCardIssuer::class)->paid($sale->id);
            $card = GiftCard::query()->sole();
            DB::table('gift_cards')->where('id', $card->id)->update(['mailed_at' => null, 'reissues' => 3]);

            $this->assertNull(app(GiftCardService::class)->reissueUndelivered(GiftCard::query()->findOrFail($card->id), 'test'));
            $this->assertSame(['issued' => 0, 'resent' => 0], app(GiftCardIssuer::class)->catchUp(CarbonImmutable::now()->addHour()));
        });
    }

    public function test_the_settings_choose_the_denominations_and_the_days_within_the_limits(): void
    {
        $this->inside(function (): void {
            $settings = app(GiftCardSettings::class);
            $this->assertSame([[3000, 5000, 10000], 180], [$settings->denominations(), $settings->validDays()]);

            app(PluginSettings::class)->update('gift-cards', ['denominations' => '10000,1000,2000,60000,2000', 'valid_days' => '365'], 'test');
            // 範囲の外（60,000）と重なりは捨て、小さい順に
            $this->assertSame([[1000, 2000, 10000], 365], [$settings->denominations(), $settings->validDays()]);

            app(PluginSettings::class)->update('gift-cards', ['denominations' => '', 'valid_days' => '2000'], 'test');
            $this->assertSame([[3000, 5000, 10000], 180], [$settings->denominations(), $settings->validDays()]);
        });
    }

    /**
     * 払われた知らせを、発行の受け手を止めて確定する（発行の前の様子を作る）
     */
    private function paidWithoutIssuing(int $amount): Transaction
    {
        $sale = $this->order($amount);
        // 払われた知らせの受け手（発行と、核の注文の知らせのメール）が何もしなかった形
        EventRelay::muting(['gift-cards.issue', 'core.order-mail.paid'], function () use ($sale): void {
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale->id)->sole();
            app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$sale->id, 'checkout.session.completed', $attempt->provider_ref, $sale->id, (int) $sale->total_amount, 'jpy', 'pi_x');
        });

        return Transaction::query()->findOrFail($sale->id);
    }

    private function assertOrderRefused(Closure $place): void
    {
        try {
            $place();
            $this->fail('1 日の上限を超えて売った');
        } catch (OrderException $e) {
            $this->assertStringContainsString('1 日にお求めいただけるのは ¥20,000 まで', $e->getMessage());
        }
    }

    /**
     * App の画面を通さずに、ギフトカードの注文を作って支払いの画面まで（ゲスト）
     */
    private function order(int $amount): Transaction
    {
        $sale = app(GiftCardPurchases::class)->place($amount, '山田 花子', 'hanako@example.com', null, null, null, Str::random(43));
        app(PaymentService::class)->startCheckout($sale, 'x', 'y');

        return $sale;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        $url = "http://gca.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();

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
