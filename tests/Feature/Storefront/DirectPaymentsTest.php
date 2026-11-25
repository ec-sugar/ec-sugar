<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Crypto\TenantCipher;
use App\Core\Discount\Models\CouponRedemption;
use App\Core\Discount\Services\CouponService;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Inventory\Enums\UnitStatus;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\InventoryUnit;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Enums\LedgerAccount;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Ledger\Models\LedgerPosting;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\ReturnRequest;
use App\Core\Order\Services\DirectPayments;
use App\Core\Order\Services\OrderReturns;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Enums\ReturnReason;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Payment\Services\PaymentService;
use App\Core\Points\Models\PointEntry;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\OrderStatus;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\Services\TransactionException;
use App\Core\Transaction\Services\TransactionService;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\PaymentChoices;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Members\Member;
use App\Storefront\Orders\OrderMail;
use App\Storefront\Orders\OrderReader;
use App\Storefront\Orders\SendOrderMail;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 銀行振込・代金引換（ADR 0074）。Stripe を通さない「店が受け取る支払い」。
 * 銀行振込は入金待ち → 店の人の入金の確認で確定・期限と猶予の後に自動で取り消し（在庫・クーポン・ポイントを戻す）。
 * 代引きは払う前に発送でき、店の人の入金の確認で確定・受取拒否で取り消し。ポイントは発送と入金の両方がそろった時に付く。
 * 振込先は店の鍵で暗号化して持ち、記録（イベント）には入れない
 */
final class DirectPaymentsTest extends TestCase
{
    use DatabaseTruncation;

    private const string ACCOUNT_NUMBER = '7654321';

    private const string HOLDER = 'カ）シュガーテスト';

    private Tenant $tenant;

    private ?string $token = null;

    private FakeStripe $stripe;

    private string $knit;

    /** @var array<string, mixed> */
    private array $valid = [
        'buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''],
        'same_as_buyer' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Queue::fake();
        $this->stripe = FakeStripe::install();
        config(['payment.awaiting_grace_hours' => 24]);
        // 2026-11-05（木）10:00（日本の時間）
        $this->travelTo(new CarbonImmutable('2026-11-05 10:00:00', 'Asia/Tokyo'));
        $this->tenant = Tenant::create(['id' => 'dpt', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'dpt.localhost']);
        $this->knit = $this->inside(function (): string {
            $knit = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($knit, Location::query()->orderBy('id')->firstOrFail(), 10);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => null]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(CouponService::class)->create(['code' => 'OFF100', 'kind' => 'fixed', 'value' => 100, 'name' => 'テスト'], 'test');
            app(PointSettings::class)->update(['enabled' => true, 'rate_bp' => 100], 'test');
            $this->useDirect();

            return $knit->id;
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->app['auth']->forgetGuards();
        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_bank_transfer_order_waits_for_the_shop_and_shows_the_account_on_every_theme_without_stripe(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();

        // 4 つのテーマとも、注文の入力に 3 つの選び方（決済の画面で選ぶ・銀行振込・代引き〔手数料つき〕）
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('GET', '/checkout')->assertOk()
                ->assertSee('data-test="payment-choice"', false)
                ->assertSee('data-test="payment-stripe"', false)
                ->assertSee('data-test="payment-bank_transfer"', false)
                ->assertSee('代金引換（手数料 ¥330）');
        }

        $this->send('POST', '/checkout', [...$this->valid, 'payment' => 'bank_transfer'])->assertRedirect('http://dpt.localhost/checkout/confirm');

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('GET', '/checkout/confirm')->assertOk()
                ->assertSee('銀行振込（お振込み先は')
                ->assertSee('ご注文から 7 日後の 23:59 までにお振込みください')
                ->assertSee('ボタンを押すとご注文が確定し、お振込み先をお知らせします。')
                ->assertDontSee('data-test="confirm-cod-fee"', false);
        }

        // 確定すると Stripe へは進まず、受け付けた画面に振込先と額と期限
        $this->tenant->forceFill(['theme' => 'default'])->save();
        $this->send('POST', '/checkout/confirm', $this->confirm())->assertRedirect('http://dpt.localhost/checkout/complete');
        $this->assertSame([], array_filter($this->stripe->requests, static fn (array $r): bool => str_ends_with($r['url'], '/v1/checkout/sessions')));

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('GET', '/checkout/complete')->assertOk()
                ->assertSee('data-test="bank-account"', false)
                ->assertSee(self::ACCOUNT_NUMBER)
                ->assertSee(self::HOLDER)
                ->assertSee('¥4,950')
                ->assertSee('2026/11/12 23:59')
                ->assertDontSee('data-test="pay"', false)
                ->assertDontSee('data-test="payment-due"', false);
        }

        // 「お支払いへ進む」を送っても、Stripe の画面は作らない
        $this->send('POST', '/checkout/pay')->assertRedirect('http://dpt.localhost/checkout/complete');

        $this->inside(function (): void {
            $sale = Transaction::query()->sole();
            $attempt = PaymentAttempt::query()->sole();
            $this->assertSame([Phase::Agreement, OrderStatus::AwaitingPayment, 4950], [$sale->phase, app(SaleOrderService::class)->status($sale), (int) $sale->total_amount]);
            $this->assertSame(['direct', 'direct', 'awaiting', 'bank_transfer', 4950], [$attempt->provider, $attempt->kind, $attempt->status, $attempt->method, $attempt->amount]);
            // 払う期限は注文の日から 7 日後の 23:59:59（日本時間）
            $this->assertSame('2026-11-12 23:59:59', $attempt->pay_by?->setTimezone('Asia/Tokyo')->format('Y-m-d H:i:s'));
            $this->assertSame('bank_transfer', DB::table('checkout_orders')->where('transaction_id', $sale->id)->value('pays_with'));
            // 在庫は押さえたまま、台帳は売掛のまま（受け取りは無い）
            $this->assertSame(1, (int) InventoryStock::query()->where('listing_id', $this->knit)->sum('reserved'));
            $this->assertSame(0, app(LedgerService::class)->netPaid($sale));
            // 振込先は暗号化して持ち（注文の時の写し）、記録には入らない
            $this->assertNoPlainAccount();
            // 新しい支払いの画面は作らない（二重に払われない）
            try {
                app(PaymentService::class)->startCheckout($sale, 'x', 'y');
                $this->fail('銀行振込の注文に支払いの画面を作った');
            } catch (PaymentUnavailableException $e) {
                $this->assertSame(PaymentService::DIRECT, $e->getMessage());
            }

            // メール: 振込先・額・期限
            $mail = new OrderMail('order_awaiting_payment', '店', app(OrderReader::class)->read($sale), 'https://dpt.localhost/orders/x');
            $mail->assertSeeInText(self::ACCOUNT_NUMBER);
            $mail->assertSeeInText('お振込み期限: 2026/11/12 23:59');
            $mail->assertSeeInHtml(self::HOLDER);
            $mail->assertDontSeeInText('コンビニ');
        });
        Queue::assertPushed(SendOrderMail::class, static fn (SendOrderMail $job): bool => $job->kind === 'order_awaiting_payment');
        Queue::assertNotPushed(SendOrderMail::class, static fn (SendOrderMail $job): bool => $job->kind === 'order_paid');

        $this->inside(function (): void {
            $sale = Transaction::query()->sole();

            // 入金の確認の前は発送できない
            try {
                app(SaleOrderService::class)->ship($sale, (string) Fulfillment::query()->value('id'), 'yamato', '1', 'operator:test');
                $this->fail('入金の前に発送できた');
            } catch (TransactionException $e) {
                $this->assertSame('入金の前は発送できません', $e->getMessage());
            }

            // 入金を確かめる → 台帳に店の直接の受け取り（決済代行の口座は通らない）、入金済み
            $confirmed = app(DirectPayments::class)->confirm($sale, 4950, false, 'operator:test');
            $this->assertSame([Phase::Payment, OrderStatus::Paid], [$confirmed->phase, app(SaleOrderService::class)->status($confirmed)]);
            $this->assertSame(4950, app(LedgerService::class)->netPaid($confirmed));
            $journal = LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Payment)->sole();
            $this->assertSame('direct-'.$sale->id, $journal->reference);
            $this->assertFalse(LedgerPosting::query()->where('journal_id', $journal->id)->where('account', LedgerAccount::Provider->value)->exists());
            $this->assertSame('paid', PaymentAttempt::query()->sole()->status);
            $this->assertEquals(['attempt' => PaymentAttempt::query()->value('id'), 'provider' => 'direct', 'amount' => 4950, 'method' => 'bank_transfer'],
                DomainEvent::query()->where('type', 'payment.received')->sole()->payload);

            // 二重の確認は断る（台帳も 1 回だけ）
            try {
                app(DirectPayments::class)->confirm($sale, 4950, false, 'operator:test');
                $this->fail('二度目の確認を受けた');
            } catch (TransactionException $e) {
                $this->assertSame(DirectPayments::ALREADY_PAID, $e->getMessage());
            }

            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Payment)->count());
            // 発送できるようになる
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale->id), (string) Fulfillment::query()->value('id'), 'yamato', '1', 'operator:test');
        });
        // 今の PaymentConfirmed と同じ後ろの流れ（ご注文の確認のメール）
        Queue::assertPushed(SendOrderMail::class, static fn (SendOrderMail $job): bool => $job->kind === 'order_paid');
    }

    public function test_a_different_amount_needs_the_extra_confirmation_and_the_ledger_takes_the_order_amount(): void
    {
        $this->inside(function (): void {
            $sale = $this->place($this->cart(), 'bank_transfer');

            foreach ([4949, 5000] as $received) {
                try {
                    app(DirectPayments::class)->confirm($sale, $received, false, 'operator:test');
                    $this->fail("{$received} 円をそのまま受けた");
                } catch (ValidationException $e) {
                    $this->assertStringContainsString('注文の額（¥4,950）', $e->errors()['amount'][0]);
                }
            }

            $this->assertSame('awaiting', PaymentAttempt::query()->sole()->status);

            app(DirectPayments::class)->confirm($sale, 5000, true, 'operator:test');
            $this->assertSame(4950, app(LedgerService::class)->netPaid($sale));
            $this->assertSame(5000, DomainEvent::query()->where('type', 'payment.received')->sole()->payload['received']);
        });
    }

    public function test_a_bank_transfer_lapses_only_after_the_deadline_and_grace_and_gives_back_stock_coupon_and_points(): void
    {
        [$sale, $member] = $this->inside(function (): array {
            $member = $this->member('hanako@example.com');
            app(PointService::class)->adjust($member, 300, 'テスト', 'test');
            $cart = $this->cart();
            app(CartService::class)->applyCoupon($cart, 'OFF100');
            $sale = $this->place($cart->fresh() ?? $cart, 'bank_transfer', points: 300);
            $this->assertSame(4950 - 100 - 300, (int) $sale->total_amount);
            $this->assertSame(0, app(PointService::class)->balance($member->id));

            return [$sale, $member];
        });

        // 期限（11-12 23:59:59）と猶予 24 時間のちょうど 1 秒前: まだ待つ
        $this->travelTo(new CarbonImmutable('2026-11-13 23:59:58', 'Asia/Tokyo'));
        $this->artisan('storefront:expire-unpaid-orders')->assertSuccessful();
        $this->inside(fn () => $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($sale->id)->status));

        // ちょうど: 取り消して在庫・クーポン・ポイントを戻し、お客さんへ知らせる
        $this->travelTo(new CarbonImmutable('2026-11-13 23:59:59', 'Asia/Tokyo'));
        $this->artisan('storefront:expire-unpaid-orders')->assertSuccessful();
        $this->inside(function () use ($sale, $member): void {
            $cancelled = Transaction::query()->findOrFail($sale->id);
            $this->assertSame(TransactionStatus::Cancelled, $cancelled->status);
            $this->assertSame('expired', PaymentAttempt::query()->sole()->status);
            $this->assertSame(0, (int) InventoryStock::query()->where('listing_id', $this->knit)->sum('reserved'));
            $this->assertSame('released', CouponRedemption::query()->sole()->status);
            $this->assertSame(300, app(PointService::class)->balance($member->id));
            $this->assertSame(['payment_lapsed'], DomainEvent::query()->where('type', 'payment.order_expired')->get()->map(static fn (DomainEvent $e): mixed => $e->payload['reason'] ?? null)->all());

            // 取り消した後の入金の確認は断る（受け取ったお金は外で返す）
            try {
                app(DirectPayments::class)->confirm($cancelled, (int) $cancelled->total_amount, false, 'operator:test');
                $this->fail('取り消した注文の入金を受けた');
            } catch (TransactionException $e) {
                $this->assertSame(DirectPayments::CLOSED, $e->getMessage());
            }

            $mail = new OrderMail('order_payment_lapsed', '店', app(OrderReader::class)->read($cancelled), 'https://dpt.localhost/orders/x');
            $mail->assertSeeInText('お振込みが確認できなかったため');
        });
        Queue::assertPushed(SendOrderMail::class, static fn (SendOrderMail $job): bool => $job->kind === 'order_payment_lapsed');
    }

    public function test_confirmation_and_expiry_at_the_same_time_only_one_wins(): void
    {
        $this->inside(function (): void {
            $first = $this->place($this->cart(), 'bank_transfer');
            $second = $this->place($this->cart(), 'bank_transfer');
            $late = new CarbonImmutable('2026-11-14 00:00:00', 'Asia/Tokyo');

            // 確認が先: 期限切れは何もしない
            app(DirectPayments::class)->confirm($first, 4950, false, 'operator:test');
            $this->assertSame('closed', app(DirectPayments::class)->expireIfLapsed($first, $late));
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($first->id)->status);

            // 期限切れの処理そのものも、期限（11-12 23:59:59）と猶予 24 時間のちょうどから（見回りの絞り込みに頼らない）
            $this->assertSame('waiting', app(DirectPayments::class)->expireIfLapsed($second, new CarbonImmutable('2026-11-13 23:59:58', 'Asia/Tokyo')));
            $this->assertSame(TransactionStatus::Open, Transaction::query()->findOrFail($second->id)->status);

            // 期限切れが先: 確認は断る
            $this->assertSame('cancelled', app(DirectPayments::class)->expireIfLapsed($second, new CarbonImmutable('2026-11-13 23:59:59', 'Asia/Tokyo')));
            $this->expectTransactionError(fn () => app(DirectPayments::class)->confirm($second, 4950, false, 'operator:test'), DirectPayments::CLOSED);
            $this->assertSame(0, app(LedgerService::class)->netPaid(Transaction::query()->findOrFail($second->id)));

            // 確認も期限切れも連鎖のロックを先に取る: ほかの書き込みが握っている間は待つ（同じ状態を 2 つが同時に読まない）
            $third = $this->place($this->cart(), 'bank_transfer');
            config(['database.connections.tenant_other' => config('database.connections.tenant')]);
            $other = DB::connection('tenant_other');
            $other->beginTransaction();

            try {
                $other->select('SELECT pg_advisory_xact_lock(?)', [$this->chainLockKey()]);
                DB::statement("SET lock_timeout = '300ms'");

                foreach ([fn () => app(DirectPayments::class)->confirm($third, 4950, false, 'operator:test'), fn () => app(DirectPayments::class)->expireIfLapsed($third, $late)] as $write) {
                    try {
                        $write();
                        $this->fail('連鎖のロックを待たずに書いた');
                    } catch (QueryException $e) {
                        $this->assertSame('55P03', $e->getCode());
                    }
                }
            } finally {
                $other->rollBack();
                DB::statement('SET lock_timeout = 0');
                DB::purge('tenant_other');
            }

            $this->assertSame('awaiting', PaymentAttempt::query()->where('transaction_id', $third->id)->sole()->status);
        });
    }

    public function test_cod_fee_tiers_and_the_limit_have_exact_boundaries_and_bad_settings_are_refused(): void
    {
        $this->inside(function (): void {
            $settings = app(DirectMethodSettings::class);
            // 段: 1 万円未満 330・3 万円未満 440・10 万円未満 660・それより上 1,100。上限 30 万円（手数料を含む）
            $this->assertSame([null, 330, 330, 440, 440, 660, 1100, 1100, null], array_map($settings->codFee(...), [0, 1, 9999, 10000, 29999, 30000, 100000, 298900, 298901]));

            $bad = [
                'tiers out of order' => ['cod_fees' => [['below' => 30000, 'fee' => 440], ['below' => 10000, 'fee' => 330], ['below' => null, 'fee' => 660]]],
                'last tier has a limit' => ['cod_fees' => [['below' => 10000, 'fee' => 330]]],
                'a middle tier without a limit' => ['cod_fees' => [['below' => null, 'fee' => 330], ['below' => null, 'fee' => 440]]],
                'negative fee' => ['cod_fees' => [['below' => null, 'fee' => -1]]],
                'no tiers' => ['cod_fees' => []],
                'limit zero' => ['cod_limit' => 0],
                'bank days 0' => ['bank_days' => 0],
                'bank days 15' => ['bank_days' => 15],
                'bank without account' => ['bank_account' => null],
                'account number with letters' => ['bank_account' => [...$this->account(), 'number' => '12a4567']],
                'account holder with a newline' => ['bank_account' => [...$this->account(), 'holder' => "カ）\nテスト"]],
                'unknown account type' => ['bank_account' => [...$this->account(), 'type' => 'foreign']],
            ];

            foreach ($bad as $case => $change) {
                try {
                    $settings->update([...$this->directSettings(), ...$change], 'operator:test');
                    $this->fail("{$case} を受けた");
                } catch (ValidationException) {
                    $this->addToAssertionCount(1);
                }
            }

            // 同じ値の保存は記録を増やさない。振込先は記録に入らない（変えたかだけ）
            $before = DomainEvent::query()->where('type', 'payment.direct_methods_updated')->count();
            $settings->update($this->directSettings(), 'operator:test');
            $this->assertSame($before, DomainEvent::query()->where('type', 'payment.direct_methods_updated')->count());
            $settings->update([...$this->directSettings(), 'bank_days' => 3], 'operator:test');
            $this->assertEquals(['bank_transfer' => true, 'bank_days' => 3, 'bank_account_changed' => false, 'cod' => true, 'cod_tiers' => 4, 'cod_limit' => 300000, 'members_only' => false],
                DomainEvent::query()->where('type', 'payment.direct_methods_updated')->orderByDesc('sequence')->first()?->payload);
            $this->assertNoPlainAccount();
        });
    }

    public function test_a_cod_order_ships_before_payment_and_the_fee_is_a_standard_rate_line(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', [...$this->valid, 'payment' => 'cod'])->assertRedirect('http://dpt.localhost/checkout/confirm');

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('GET', '/checkout/confirm')->assertOk()
                ->assertSee('data-test="confirm-cod-fee"', false)
                ->assertSee('代引き手数料 ¥330 を含みます')
                ->assertSee('¥5,280')
                ->assertSee('ボタンを押すとご注文が確定します。代金は商品のお届け時にお支払いください。');
        }

        $this->tenant->forceFill(['theme' => 'default'])->save();
        $this->send('POST', '/checkout/confirm', $this->confirm())->assertRedirect('http://dpt.localhost/checkout/complete');
        $this->send('GET', '/checkout/complete')->assertOk()->assertSee('data-test="cod-due"', false)->assertSee('¥5,280')->assertDontSee('data-test="pay"', false);

        $this->inside(function (): void {
            $sale = Transaction::query()->sole();
            $fee = TransactionLine::query()->where('transaction_id', $sale->id)->where('kind', 'cod_fee')->sole();
            $this->assertSame([LineType::Adjustment, 330, 1000, '代引き手数料'], [$fee->line_type, (int) $fee->amount, (int) $fee->tax_rate_bp, $fee->description]);
            $this->assertSame([5280, Phase::Payment, OrderStatus::CashOnDelivery], [(int) $sale->total_amount, $sale->phase, app(SaleOrderService::class)->status($sale)]);
            $this->assertSame(['awaiting', 'cod', null], [PaymentAttempt::query()->sole()->status, PaymentAttempt::query()->sole()->method, PaymentAttempt::query()->sole()->pay_by]);

            // ご注文の状況・メールにも手数料の行と「届けた時に払う」
            $view = app(OrderReader::class)->read($sale);
            $this->assertSame([['title' => '代引き手数料', 'amountLabel' => '¥330']], $view->charges);
            $this->assertTrue($view->payment?->onDelivery);
            $mail = new OrderMail('order_paid', '店', $view, 'https://dpt.localhost/orders/x');
            $mail->assertSeeInText('代金引換で受け付けました');
            $mail->assertDontSeeInText('お支払いを受け付けました');

            // 払う前に発送でき、ポイントのない注文はそのまま。完了は入金の確認の後
            $shipped = app(SaleOrderService::class)->ship($sale, (string) Fulfillment::query()->value('id'), 'yamato', '1', 'operator:test');
            $this->assertSame(Phase::Fulfillment, $shipped->phase);
            $this->expectTransactionError(fn () => app(SaleOrderService::class)->complete($shipped, 'operator:test'), '代引きの入金を確認してから、完了にしてください（「代引きの入金を確認した」）。');

            app(DirectPayments::class)->confirm($shipped, 5280, false, 'operator:test');
            $this->assertSame(5280, app(LedgerService::class)->netPaid($shipped));
            $this->assertSame(Phase::Settlement, app(SaleOrderService::class)->complete(Transaction::query()->findOrFail($sale->id), 'operator:test')->phase);
            $this->expectTransactionError(fn () => app(DirectPayments::class)->confirm(Transaction::query()->findOrFail($sale->id), 5280, false, 'operator:test'), DirectPayments::ALREADY_PAID);
        });
        // 受け付けた時に 1 回だけ「ご注文ありがとうございます」（代引きの入金の確認では送らない）
        $this->assertCount(1, Queue::pushed(SendOrderMail::class, static fn (SendOrderMail $job): bool => $job->kind === 'order_paid'));
    }

    public function test_cod_cannot_be_chosen_for_gifts_other_kinds_or_over_the_limit_and_the_reason_is_shown(): void
    {
        $this->inside(function (): void {
            $choices = app(PaymentChoices::class);
            $cart = $this->cart();
            $view = app(CartService::class)->view($cart);

            // 贈り物
            $gift = $this->input('cod', gift: true);
            $offer = $choices->offer($view, $gift);
            $this->assertFalse($offer->allows('cod'));
            $this->assertSame(PaymentChoices::COD_GIFT, $offer->reason('cod'));
            // 贈り物では銀行振込も選べない（同じ理由の出し方）。決済の画面で選ぶ手段は選べる
            $this->assertSame([false, PaymentChoices::BANK_GIFT, true], [$offer->allows('bank_transfer'), $offer->reason('bank_transfer'), $offer->allows('stripe')]);

            foreach (['cod' => PaymentChoices::COD_GIFT, 'bank_transfer' => PaymentChoices::BANK_GIFT] as $method => $reason) {
                try {
                    $this->place($cart, $method, gift: true);
                    $this->fail("贈り物を {$method} で確定した");
                } catch (OrderException $e) {
                    $this->assertStringContainsString($reason, $e->getMessage());
                }
            }

            // お届け日を選んだ注文には銀行振込を出さない（振込を待ってから発送する。代引きはお届け日を選べる）
            $dated = CheckoutInput::fromArray([...$this->input('bank_transfer')->toArray(), 'delivery' => ['date' => '2026-11-10', 'slot' => null]]);
            $this->assertNotNull($dated);
            $datedOffer = $choices->offer($view, $dated);
            $this->assertSame([false, PaymentChoices::BANK_DATED, true], [$datedOffer->allows('bank_transfer'), $datedOffer->reason('bank_transfer'), $datedOffer->allows('cod')]);

            // 予約・デジタルの品（App が足す種類。ふつうの商品でない品）。予約の品（booking）は App「予約」がカートに入れさせないので、
            // ここではカートに入る架空の種類で確かめる
            DB::table('listings')->where('id', $this->knit)->update(['kind' => 'rental']);
            $this->assertSame(PaymentChoices::COD_KIND, $choices->offer(app(CartService::class)->view($cart), $this->input('cod'))->reason('cod'));
            DB::table('listings')->where('id', $this->knit)->update(['kind' => 'product']);

            // 上限の額（手数料を含めて 1 円でも超えると選べない）
            app(DirectMethodSettings::class)->update([...$this->directSettings(), 'cod_limit' => 5279], 'operator:test');
            $this->assertStringContainsString('¥5,279 までのご注文', (string) $choices->offer(app(CartService::class)->view($cart, $this->input('cod')->destination()), $this->input('cod'))->reason('cod'));
            app(DirectMethodSettings::class)->update([...$this->directSettings(), 'cod_limit' => 5280], 'operator:test');
            $this->assertTrue($choices->offer(app(CartService::class)->view($cart, $this->input('cod')->destination()), $this->input('cod'))->allows('cod'));

            // 使わない店では出さない（送られてきても確定しない）
            app(DirectMethodSettings::class)->update([...$this->directSettings(), 'cod' => false], 'operator:test');
            $this->assertNull($choices->offer($view, $this->input('cod'))->reason('cod'));
            $this->assertFalse($choices->offer($view, $this->input('cod'))->allows('cod'));
        });

        // 注文の入力で送っても断り、理由を欄の誤りにする。使わない店の入力の画面には代引きを出さない
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        $this->send('POST', '/checkout', [...$this->valid, 'payment' => 'cod'])->assertRedirect()->assertSessionHasErrors(['payment' => 'このお支払い方法は、現在ご利用いただけません。']);
        $this->send('GET', '/checkout')->assertOk()->assertDontSee('data-test="payment-cod"', false)->assertSee('data-test="payment-bank_transfer"', false);
    }

    public function test_cash_on_delivery_with_a_gift_card_uses_the_card_and_collects_only_the_rest(): void
    {
        $this->inside(function (): void {
            app(DirectMethodSettings::class)->update($this->directSettings(), 'operator:test');
            $card = app(GiftCardService::class)->issue(2000, 180, null, 'test')['card'];
            $cart = $this->cart();
            $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null,
                'payment' => 'cod', 'gift_card_id' => $card->id]);
            $this->assertNotNull($input);

            // 4,400 + 送料 550 = 4,950。ギフトカード 2,000 は手数料の前の合計に充て、代引き手数料 330 は残りのお支払いに入る
            $view = app(CartService::class)->view($cart, $input->destination(), 'hanako@example.com', 0, $card->id);
            $offer = app(PaymentChoices::class)->offer($view, $input);
            $withFee = $view->withPaymentFee($offer->fee('cod'));
            $this->assertSame([2000, 5280, 3280], [$withFee->giftCard->applied, $withFee->total(), $withFee->due()]);

            $fingerprint = CheckoutSummary::fingerprintOf($withFee, app(PaymentMethodSettings::class)->forCheckout($withFee->total()), null, '', CheckoutSummary::paymentKey('cod', $offer->bankDays, $withFee->paymentFee));
            $sale = app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $fingerprint);

            // カードから引き、代引きで受け取るのは残りだけ
            $this->assertSame(2000, OrderGiftCard::appliedTo($sale->id));
            $this->assertSame(0, app(GiftCardService::class)->balance($card->id));
            $this->assertSame(['cod', 3280], [PaymentAttempt::query()->sole()->method, PaymentAttempt::query()->sole()->amount]);
        });
    }

    public function test_an_order_paid_whole_by_gift_card_offers_no_cash_on_delivery_and_places_with_cod_left_in_the_input(): void
    {
        $this->inside(function (): void {
            app(DirectMethodSettings::class)->update($this->directSettings(), 'operator:test');
            $card = app(GiftCardService::class)->issue(10000, 180, null, 'test')['card'];
            $cart = $this->cart();
            $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null,
                'payment' => 'cod', 'gift_card_id' => $card->id]);
            $this->assertNotNull($input);

            // 4,950 を全部ギフトカードで払う: 払うものが無いので、銀行振込・代引きを出さない（確かめの画面と確定で手段が食い違わない）
            $view = app(CartService::class)->view($cart, $input->destination(), 'hanako@example.com', 0, $card->id);
            $this->assertSame([4950, 0], [$view->giftCard->applied, $view->due()]);
            $offer = app(PaymentChoices::class)->offer($view, $input);
            $this->assertFalse($offer->allows('cod'));
            $this->assertFalse($offer->allows('bank_transfer'));

            // 入力に代引きが残っていても、確かめの画面と同じ決め方（払う額 0 円は決済代行・手数料なし）の指紋で確定できる
            $fingerprint = CheckoutSummary::fingerprintOf($view, app(PaymentMethodSettings::class)->forCheckout($view->due()), null, '', CheckoutSummary::paymentKey(CheckoutInput::PAY_STRIPE, $offer->bankDays, 0));
            $sale = app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $fingerprint);
            $this->assertSame([4950, 0, Phase::Payment], [OrderGiftCard::appliedTo($sale->id), PaymentAttempt::query()->count(), Transaction::query()->findOrFail($sale->id)->phase]);
        });
    }

    public function test_the_fingerprint_holds_the_method_the_days_and_the_fee(): void
    {
        $this->inside(function (): void {
            $cart = $this->cart();
            $input = $this->input('cod');
            $view = app(CartService::class)->view($cart, $input->destination());
            $stripe = CheckoutSummary::fingerprintOf($view, app(PaymentMethodSettings::class)->forCheckout($view->total()));
            $bank = CheckoutSummary::fingerprintOf($view, null, null, '', CheckoutSummary::paymentKey('bank_transfer', 7, 0));
            $cod = CheckoutSummary::fingerprintOf($view->withPaymentFee(330), null, null, '', CheckoutSummary::paymentKey('cod', 7, 330));
            $this->assertCount(3, array_unique([$stripe, $bank, $cod]));

            // 確かめた手段と違う手段の指紋では確定しない（決済の画面で選ぶ注文の指紋で代引きに）
            $this->expectOrderChanged(fn () => $this->place($cart, 'cod', fingerprint: $stripe));
            // 確かめた後に手数料の段が変わった
            app(DirectMethodSettings::class)->update([...$this->directSettings(), 'cod_fees' => [['below' => null, 'fee' => 440]]], 'operator:test');
            $this->expectOrderChanged(fn () => $this->place($cart, 'cod', fingerprint: $cod));
            // 確かめた後に振込の期限の日数が変わった
            app(DirectMethodSettings::class)->update([...$this->directSettings(), 'bank_days' => 3], 'operator:test');
            $this->expectOrderChanged(fn () => $this->place($cart, 'bank_transfer', fingerprint: $bank));
            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_points_are_earned_only_when_both_shipped_and_paid(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            $balance = static fn (): int => app(PointService::class)->balance($member->id);

            // 代引き: 発送しても払う前は付けない。入金の確認で付ける（付く予定は商品の代金の 1%。手数料・送料は入らない）
            $cod = $this->place($this->cart(), 'cod');
            $this->assertSame(44, app(PointService::class)->forOrder($cod->id)['earn'] ?? null);
            $this->shipAll($cod);
            $this->assertSame(0, $balance());
            app(DirectPayments::class)->confirm(Transaction::query()->findOrFail($cod->id), 5280, false, 'operator:test');
            $this->assertSame(44, $balance());

            // 代引き: 払ってから発送しても、全部発送した時に付く（入金の確認では付けない）
            $paidFirst = $this->place($this->cart(), 'cod');
            app(DirectPayments::class)->confirm($paidFirst, 5280, false, 'operator:test');
            $this->assertSame(1, PointEntry::query()->where('kind', 'earn')->count());
            $this->shipAll($paidFirst);
            $this->assertSame(88, $balance());

            // 銀行振込: 入金の確認の後に発送した時
            $bank = $this->place($this->cart(), 'bank_transfer');
            app(DirectPayments::class)->confirm($bank, 4950, false, 'operator:test');
            $this->assertSame(2, PointEntry::query()->where('kind', 'earn')->count());
            $this->shipAll($bank);
            $this->assertSame(132, $balance());
            $this->assertSame(3, PointEntry::query()->where('kind', 'earn')->count());
        });
    }

    public function test_a_refused_cod_parcel_cancels_the_order_and_restocks_or_writes_off_the_items(): void
    {
        $this->inside(function (): void {
            $member = $this->member('hanako@example.com');
            app(PointService::class)->adjust($member, 200, 'テスト', 'test');
            $cart = $this->cart();
            app(CartService::class)->applyCoupon($cart, 'OFF100');
            $restocked = $this->place($cart->fresh() ?? $cart, 'cod', points: 200);

            // 発送の前は受取拒否にできない（ふつうの取消を使う）
            $this->expectTransactionError(fn () => app(DirectPayments::class)->refuseCod($restocked, true, 'operator:test'), 'まだ発送していない代引きの注文は、「注文を取り消す」で取り消してください。');
            $this->shipAll($restocked);
            $refused = app(DirectPayments::class)->refuseCod(Transaction::query()->findOrFail($restocked->id), true, 'operator:test');
            $this->assertSame(TransactionStatus::Cancelled, $refused->status);
            $this->assertSame([10, 0], $this->stock());
            $this->assertSame('released', CouponRedemption::query()->sole()->status);
            $this->assertSame(200, app(PointService::class)->balance($member->id));
            $this->assertEquals(['restock' => true, 'attempts_expired' => 1], DomainEvent::query()->where('type', 'order.cod_refused')->sole()->payload);
            $this->assertSame(['cancelled'], Fulfillment::query()->where('transaction_id', $restocked->id)->get()->map(static fn (Fulfillment $f): string => $f->status->value)->all());
            $this->assertSame(0, app(LedgerService::class)->netPaid($refused));
            // 二度目は断る
            $this->expectTransactionError(fn () => app(DirectPayments::class)->refuseCod($refused, true, 'operator:test'), 'この注文は受取拒否にできません（終わっているか、取り消されています）。');

            // 在庫に戻さない: 戻した後に同じ数を減らす（破損・不良の記録）
            $writtenOff = $this->place($this->cart(), 'cod');
            $this->shipAll($writtenOff);
            app(DirectPayments::class)->refuseCod(Transaction::query()->findOrFail($writtenOff->id), false, 'operator:test');
            $this->assertSame([9, 0], $this->stock());
            $this->assertEquals(['delta' => -1, 'reason' => 'damage'], array_intersect_key((array) DomainEvent::query()->where('type', 'inventory.adjusted')->sole()->payload, ['delta' => 1, 'reason' => 1]));

            // 入金を確かめた代引きは受取拒否にできない
            $paid = $this->place($this->cart(), 'cod');
            $this->shipAll($paid);
            app(DirectPayments::class)->confirm(Transaction::query()->findOrFail($paid->id), 5280, false, 'operator:test');
            $this->expectTransactionError(fn () => app(DirectPayments::class)->refuseCod(Transaction::query()->findOrFail($paid->id), true, 'operator:test'), '入金を確認した代引きの注文は、受取拒否にできません（返品・返金で扱います）。');
        });
    }

    public function test_a_refusal_without_restocking_writes_off_only_shipped_boxes_and_disposes_units(): void
    {
        $this->inside(function (): void {
            // 2 つの拠点から 1 点ずつ（受渡が 2 つ）。1 つだけ発送して受取拒否・在庫に戻さない: 減らすのは発送した箱の品だけ
            $seller = Seller::query()->firstOrFail();
            [$first, $second] = [Location::query()->orderBy('id')->firstOrFail(), Location::create(['seller_id' => $seller->id, 'name' => '倉庫 2', 'kind' => 'warehouse'])];
            $hat = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => '帽子', 'unit_amount' => 3000, 'status' => 'active']);
            app(StockService::class)->receive($hat, $first, 1);
            app(StockService::class)->receive($hat, $second, 1);
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $hat->id)->value('id'), 2);
            $sale = $this->place($cart, 'cod');
            $boxes = Fulfillment::query()->where('transaction_id', $sale->id)->orderBy('created_at')->orderBy('id')->get();
            $this->assertCount(2, $boxes);
            app(SaleOrderService::class)->ship($sale, $boxes[0]->id, 'yamato', '1', 'operator:test');
            app(DirectPayments::class)->refuseCod(Transaction::query()->findOrFail($sale->id), false, 'operator:test');
            $shippedFrom = $boxes[0]->location_id;
            $stocks = InventoryStock::query()->where('listing_id', $hat->id)->get()->mapWithKeys(static fn (InventoryStock $s): array => [$s->location_id => [(int) $s->quantity, (int) $s->reserved]])->all();
            $this->assertSame([0, 0], $stocks[$shippedFrom]);
            $this->assertSame([1, 0], $stocks[$shippedFrom === $first->id ? $second->id : $first->id]);
            $this->assertSame(1, DomainEvent::query()->where('type', 'inventory.adjusted')->count());

            // 個体で持つ品: 在庫に戻さないなら廃棄、戻すなら売れる状態に（戻す時と対称に）
            foreach ([false => UnitStatus::Disposed, true => UnitStatus::Available] as $restock => $expected) {
                $unit = InventoryUnit::create(['listing_id' => $hat->id, 'location_id' => $first->id, 'identifier' => 'SN-'.(int) $restock, 'status' => UnitStatus::Available]);
                $order = app(TransactionService::class)->open('sale', $seller, null, [LineDraft::fromListing($hat, 1, 1000, $first, $unit)], actor: 'test');
                $line = $order->lines()->where('line_type', LineType::Item)->firstOrFail();
                $box = app(FulfillmentService::class)->create($order, FulfillmentMethod::Shipping, [$line->id => 1], $first, ['name' => '山田 花子', 'zip' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1'], 'test');
                PaymentAttempt::create(['transaction_id' => $order->id, 'provider' => 'direct', 'provider_ref' => 'direct-'.$order->id, 'checkout_url' => '', 'amount' => (int) $order->total_amount,
                    'currency' => 'JPY', 'status' => 'awaiting', 'expires_at' => now(), 'kind' => 'direct', 'methods' => 'cod', 'method' => 'cod']);
                app(TransactionService::class)->transition($order, Phase::Payment, 'test');
                app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($order->id), $box->id, 'yamato', '1', 'operator:test');
                app(DirectPayments::class)->refuseCod(Transaction::query()->findOrFail($order->id), (bool) $restock, 'operator:test');
                $this->assertSame($expected, $unit->refresh()->status);
            }

            $this->assertSame(1, DomainEvent::query()->where('type', 'inventory.unit_disposed')->count());
        });
    }

    public function test_refunds_of_direct_payments_are_recorded_by_the_shop_and_cancel_waits_until_all_is_back(): void
    {
        $this->inside(function (): void {
            $sale = $this->place($this->cart(), 'bank_transfer');

            // 払う前は返金の記録はできない
            $this->expectConflict(fn () => app(DirectPayments::class)->recordRefund($sale, 100, 'operator:test'), PaymentRefunds::NOT_DIRECT);
            app(DirectPayments::class)->confirm($sale, 4950, false, 'operator:test');

            // 受け取ったお金が残る間は取り消せない（Stripe へは頼まない）
            $this->expectTransactionError(fn () => app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale->id), 'operator:test'),
                '銀行振込・代金引換で受け取った代金を先にお客さんへ返金し、「返金を記録する」で返金した額を記録してから取り消してください。');

            $first = app(DirectPayments::class)->recordRefund($sale, 1000, 'operator:test');
            $this->assertSame(['external', 'succeeded', 'recorded', 1000], [$first->purpose, $first->status, $first->resolution, $first->amount]);
            $this->assertSame(3950, app(LedgerService::class)->netPaid($sale));
            $this->expectConflict(fn () => app(DirectPayments::class)->recordRefund($sale, 3951, 'operator:test'), PaymentRefunds::DIRECT_OVER_PAID);
            app(DirectPayments::class)->recordRefund($sale, 3950, 'operator:test');
            $this->assertSame(0, app(LedgerService::class)->netPaid($sale));
            // 台帳の返金も店の直接の受け取りの口座で（決済代行の口座は通らない）
            $refunds = LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Refund)->pluck('id');
            $this->assertCount(2, $refunds);
            $this->assertFalse(LedgerPosting::query()->whereIn('journal_id', $refunds)->where('account', LedgerAccount::Provider->value)->exists());
            $this->assertSame([1000, 3950], DomainEvent::query()->where('type', 'payment.refund_recorded')->orderBy('sequence')->get()->map(static fn ($e) => $e->payload['amount'])->all());

            $result = app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale->id), 'operator:test');
            $this->assertSame(TransactionStatus::Cancelled, $result['transaction']->status);
            $this->assertSame([10, 0], $this->stock());
            $this->assertSame(2, Refund::query()->count());
        });
    }

    public function test_a_refund_recorded_after_shipping_is_kept_as_a_return(): void
    {
        $this->inside(function (): void {
            $sale = $this->place($this->cart(), 'bank_transfer');
            app(DirectPayments::class)->confirm($sale, 4950, false, 'operator:test');
            $this->shipAll($sale);

            // 発送の後に店が返した額を記録する（前は返し方が空で、記録が型の誤りで落ちていた）
            $refund = app(DirectPayments::class)->recordRefund(Transaction::query()->findOrFail($sale->id), 1000, 'operator:test');
            $this->assertNotNull($refund->return_id);
            $this->assertSame(3950, app(LedgerService::class)->netPaid($sale));
            $event = DomainEvent::query()->where('type', 'order.return_recorded')->sole();
            $this->assertSame(['outside', 1000, 'external'], [$event->payload['channel'], $event->payload['amount'], $event->payload['reason']]);
        });
    }

    public function test_after_an_amount_only_refund_of_a_bank_transfer_the_item_goes_back_to_stock_for_the_rest_recorded_outside(): void
    {
        $this->inside(function (): void {
            // 銀行振込 4,950 を受け取って発送した後、お詫びに金額だけ 1,000 をお店から返したと記録した。その後ニットが返ってきて在庫に戻す
            $sale = $this->place($this->cart(), 'bank_transfer');
            app(DirectPayments::class)->confirm($sale, 4950, false, 'operator:test');
            $this->shipAll($sale);
            $sale = Transaction::query()->findOrFail($sale->id);
            app(OrderReturns::class)->record($sale, new ReturnRequest([], false, 1000, ReturnReason::Goodwill, 'outside'), 1000, 'operator:test');
            $position = (int) TransactionLine::query()->where('transaction_id', $sale->id)->where('line_type', LineType::Item)->value('position');
            $item = static fn (string $channel): ReturnRequest => new ReturnRequest([$position => ['quantity' => 1, 'restock' => true, 'addons' => false]], false, 0, ReturnReason::Returned, $channel);
            [$before] = $this->stock();

            // Stripe で受けた支払いは無いので Stripe では返せない。外で返した記録の既定は残りの 3,950（品の額 4,400 ではない）
            $this->expectConflict(fn () => app(OrderReturns::class)->record($sale, $item('provider'), null, 'operator:test'), OrderReturns::NO_PROVIDER);
            $plan = app(OrderReturns::class)->preview($sale, $item('outside'));
            $this->assertSame([3950, 4400], [$plan->total, $plan->goodsFull]);
            $refund = app(OrderReturns::class)->record($sale, $item('outside'), $plan->total, 'operator:test');

            $this->assertSame([3950, 'succeeded', 'outside'], [$refund?->amount, $refund?->status, $refund?->channel]);
            $this->assertSame($before + 1, $this->stock()[0]);
            $this->assertSame(0, app(LedgerService::class)->netPaid(Transaction::query()->findOrFail($sale->id)));
            $this->assertSame([], array_filter(app(LedgerService::class)->balances(Transaction::query()->findOrFail($sale->id))));
            // 台帳の返金は店の直接の受け取りの口座で（決済代行の口座は通らない）。Stripe には頼まない
            $refunds = LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Refund)->pluck('id');
            $this->assertFalse(LedgerPosting::query()->whereIn('journal_id', $refunds)->where('account', LedgerAccount::Provider->value)->exists());
            $this->assertSame([], $this->stripe->refunds);
        });
    }

    public function test_the_storefront_works_without_stripe_when_only_direct_payments_are_used(): void
    {
        $this->inside(fn () => app(PaymentGatewayService::class)->removeStripe('test'));
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
        $html = (string) $this->send('GET', '/checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-test="payment-stripe"', $html);
        $this->assertStringContainsString('data-test="payment-bank_transfer"', $html);
        // 決済の画面で選ぶ（Stripe の鍵が無い）は確定しない。銀行振込は確定する
        $this->send('POST', '/checkout', [...$this->valid, 'payment' => 'bank_transfer'])->assertRedirect('http://dpt.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertOk()->assertDontSee('data-test="payment-unavailable"', false);
        $this->send('POST', '/checkout/confirm', $this->confirm())->assertRedirect('http://dpt.localhost/checkout/complete');
        $this->inside(fn () => $this->assertSame('bank_transfer', PaymentAttempt::query()->sole()->method));
    }

    public function test_a_shop_with_only_one_direct_method_and_no_stripe_takes_orders_with_that_method(): void
    {
        $this->inside(fn () => app(PaymentGatewayService::class)->removeStripe('test'));
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();

        foreach (['bank_transfer' => ['cod' => false], 'cod' => ['bank_transfer' => false]] as $method => $off) {
            $this->inside(fn () => app(DirectMethodSettings::class)->update([...$this->directSettings(), ...$off], 'test'));
            // 選べる手段が 1 つでも、どの手段で払うかを見せる（選んである）
            $this->send('GET', '/checkout')->assertOk()->assertSee('data-test="payment-'.$method.'"', false)->assertDontSee('data-test="payment-stripe"', false);
            // 選び方を送らない（既定は決済の画面で選ぶ）入力も、その手段に決め直してセッションに置く
            $this->send('POST', '/checkout', $this->valid)->assertRedirect('http://dpt.localhost/checkout/confirm');
            $this->assertSame($method, session('checkout')['payment'] ?? null);
            $this->send('GET', '/checkout/confirm')->assertOk()->assertDontSee('data-test="payment-unavailable"', false);
        }

        // 前の入力（決済の画面で選ぶ）をセッションに置いたまま確かめの画面を開いても、その手段で決め直す
        session()->put('checkout', [...session('checkout'), 'payment' => 'stripe']);
        $this->send('GET', '/checkout/confirm')->assertOk()->assertDontSee('data-test="payment-unavailable"', false)->assertSee('data-test="confirm-cod-fee"', false);
        $this->send('POST', '/checkout/confirm', $this->confirm())->assertRedirect('http://dpt.localhost/checkout/complete');
        $this->inside(fn () => $this->assertSame('cod', PaymentAttempt::query()->sole()->method));
    }

    // --- 補助 ---

    private function useDirect(): void
    {
        app(DirectMethodSettings::class)->update($this->directSettings(), 'test');
    }

    /**
     * @return array<string, mixed>
     */
    private function directSettings(): array
    {
        return ['bank_transfer' => true, 'bank_days' => 7, 'bank_account' => $this->account(), 'cod' => true, 'cod_fees' => DirectMethodSettings::DEFAULT_COD_FEES, 'cod_limit' => 300000];
    }

    /**
     * @return array{bank: string, branch: string, type: string, number: string, holder: string}
     */
    private function account(): array
    {
        return ['bank' => 'シュガー銀行', 'branch' => '渋谷支店', 'type' => 'ordinary', 'number' => self::ACCOUNT_NUMBER, 'holder' => self::HOLDER];
    }

    /**
     * 振込先が平文で表に残らず、どのイベントにも入っていない
     */
    private function assertNoPlainAccount(): void
    {
        foreach (['payment_method_settings', 'payment_attempts'] as $table) {
            foreach (DB::table($table)->whereNotNull('bank_account')->pluck('bank_account') as $raw) {
                $this->assertStringStartsWith(TenantCipher::PREFIX, (string) $raw);
                $this->assertStringNotContainsString(self::ACCOUNT_NUMBER, (string) $raw);
            }
        }

        foreach (DomainEvent::query()->get() as $event) {
            $payload = json_encode($event->payload, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString(self::ACCOUNT_NUMBER, (string) $payload, $event->type);
            $this->assertStringNotContainsString('シュガー銀行', (string) $payload, $event->type);
        }
    }

    private function member(string $email): CustomerAccount
    {
        $account = app(CustomerAccounts::class)->forEmail($email, 'test');
        CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);
        $account = CustomerAccount::query()->findOrFail($account->id);
        Auth::guard('customer')->setUser(new Member($account, 'dpt'));

        return $account;
    }

    private function cart(): Cart
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->knit)->value('id'), 1);

        return $cart;
    }

    private function input(string $payment, int $points = 0, bool $gift = false): CheckoutInput
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null,
            'gift' => $gift ? ['sender' => 'はなこ', 'message' => 'おめでとう'] : null,
            'points' => $points > 0 ? ['mode' => 'some', 'amount' => $points] : null,
            'payment' => $payment]);
        $this->assertNotNull($input);

        return $input;
    }

    /**
     * 確かめの画面と同じ決め方で指紋を作って確定する（指紋を渡せば、それで）
     */
    private function place(Cart $cart, string $payment, int $points = 0, ?string $fingerprint = null, bool $gift = false): Transaction
    {
        $input = $this->input($payment, $points, $gift);

        if ($fingerprint === null) {
            $view = app(CartService::class)->view($cart, $input->destination(), 'hanako@example.com', $input->pointsRequested());
            $offer = app(PaymentChoices::class)->offer($view, $input);
            $view = $view->withPaymentFee($payment === 'cod' ? $offer->fee('cod') : 0);
            $fingerprint = CheckoutSummary::fingerprintOf($view, app(PaymentMethodSettings::class)->forCheckout($view->total()), null, '', CheckoutSummary::paymentKey($payment, $offer->bankDays, $view->paymentFee));
        }

        return app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $fingerprint);
    }

    private function shipAll(Transaction $sale): void
    {
        foreach (Fulfillment::query()->where('transaction_id', $sale->id)->get() as $fulfillment) {
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale->id), $fulfillment->id, 'yamato', '1234', 'operator:test');
        }
    }

    /**
     * @return array{0: int, 1: int} [数, 押さえている数]
     */
    private function stock(): array
    {
        $stock = InventoryStock::query()->where('listing_id', $this->knit)->sole();

        return [(int) $stock->quantity, (int) $stock->reserved];
    }

    private function chainLockKey(): int
    {
        return (int) (new \ReflectionClassConstant(EventStore::class, 'APPEND_LOCK'))->getValue();
    }

    private function expectTransactionError(Closure $work, string $message): void
    {
        try {
            $work();
            $this->fail("断られなかった: {$message}");
        } catch (TransactionException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function expectConflict(Closure $work, string $message): void
    {
        try {
            $work();
            $this->fail("断られなかった: {$message}");
        } catch (PaymentUnavailableException $e) {
            $this->assertSame([409, $message], [$e->status(), $e->getMessage()]);
        }
    }

    private function expectOrderChanged(Closure $work): void
    {
        try {
            $work();
            $this->fail('確認した内容と違う注文を確定した');
        } catch (OrderException $e) {
            $this->assertTrue($e->changed);
        }
    }

    /**
     * 確認の画面を開き、確定のフォームの値を取り出す
     *
     * @return array<string, string>
     */
    private function confirm(): array
    {
        $html = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $html, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);

        return ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''];
    }

    /**
     * Cookie（カートの合い言葉とセッション）を持ち越して送る
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        $url = "http://dpt.localhost{$path}";
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
