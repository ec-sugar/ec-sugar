<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\ShopHome;
use App\Core\Crypto\TenantCipher;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Enums\LedgerAccount;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\DirectPayments;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\PaymentChoices;
use App\Storefront\Checkout\PlaceOrder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の銀行振込・代金引換（ADR 0074）。設定・入金の確認・受取拒否・返金の記録はお金の段階と直前の再確認、
 * 振込先はお金の段階の人にだけ返す。ほかの店の注文には届かない。注文の一覧の「お支払いで絞る」とホームのやること
 */
final class DirectPaymentAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private Operator $operator;

    private SoftwareAuthenticator $authenticator;

    /** 確定した注文の数（お客さんのメールを注文ごとに分ける） */
    private int $orders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        Queue::fake();
        $this->travelTo(new CarbonImmutable('2026-11-05 10:00:00', 'Asia/Tokyo'));

        foreach (['dpa', 'dpb'] as $shop) {
            $this->tenant($shop);
            $this->inside($shop, function (): void {
                $knit = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
                app(StockService::class)->receive($knit, Location::query()->orderBy('id')->firstOrFail(), 10);
                app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => null]);
                app(DirectMethodSettings::class)->update(self::settings(), 'test');
            });
        }
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_settings_need_money_and_a_fresh_confirmation_and_the_account_is_shown_only_to_money(): void
    {
        [$this->operator, $this->authenticator] = $this->enrolledOperator();
        $this->send('GET', '/api/tenants/dpa/payment-settings')->assertOk()
            ->assertJsonPath('direct.bank_transfer', true)
            ->assertJsonPath('direct.bank_account.number', '7654321')
            ->assertJsonPath('direct.cod_fees.0', ['below' => 10000, 'fee' => 330])
            ->assertJsonPath('direct.account_types.ordinary', '普通');

        $changed = [...self::settings(), 'bank_account' => [...self::settings()['bank_account'], 'number' => '1112223']];
        $this->send('PUT', '/api/tenants/dpa/payment-settings/direct', $changed)->assertStatus(423);
        $this->reconfirm($this->operator, $this->authenticator);
        $this->send('PUT', '/api/tenants/dpa/payment-settings/direct', $changed)->assertOk()->assertJsonPath('direct.bank_account.number', '1112223');
        $this->send('PUT', '/api/tenants/dpa/payment-settings/direct', [...$changed, 'bank_days' => 15])->assertUnprocessable()->assertJsonValidationErrors('bank_days');

        $this->inside('dpa', function (): void {
            $raw = (string) DB::table('payment_method_settings')->value('bank_account');
            $this->assertStringStartsWith(TenantCipher::PREFIX, $raw);
            $this->assertStringNotContainsString('1112223', $raw);
            $event = DomainEvent::query()->where('type', 'payment.direct_methods_updated')->orderByDesc('sequence')->firstOrFail();
            $this->assertTrue($event->payload['bank_account_changed']);
            $this->assertStringNotContainsString('1112223', (string) json_encode($event->payload));
            $this->assertStringStartsWith('operator:', (string) $event->actor);
        });

        // 「銀行振込・代金引換は会員だけ」（ADR 0085）: 既定は切る。入れたら、送らない保存では今のまま。形の違う値は断る
        $this->send('GET', '/api/tenants/dpa/payment-settings')->assertOk()->assertJsonPath('direct.members_only', false);
        $this->send('PUT', '/api/tenants/dpa/payment-settings/direct', [...$changed, 'members_only' => true])->assertOk()->assertJsonPath('direct.members_only', true);
        $this->send('PUT', '/api/tenants/dpa/payment-settings/direct', [...$changed, 'cod_limit' => 200000])->assertOk()->assertJsonPath('direct.members_only', true);
        $this->send('PUT', '/api/tenants/dpa/payment-settings/direct', [...$changed, 'members_only' => 'yes'])->assertUnprocessable()->assertJsonValidationErrors('members_only');
        $this->send('GET', '/api/tenants/dpb/payment-settings')->assertOk()->assertJsonPath('direct.members_only', false);
        $this->inside('dpa', fn () => $this->assertTrue(DomainEvent::query()->where('type', 'payment.direct_methods_updated')->orderByDesc('sequence')->firstOrFail()->payload['members_only']));

        // 閲覧・運用の人には振込先を返さない（「入っている」だけ）。変えられない
        $this->send('POST', '/auth/logout')->assertNoContent();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        foreach (['read', 'operate'] as $level) {
            app(OperatorAccess::class)->grant($staff, 'dpa', $level, 'test');
            $response = $this->send('GET', '/api/tenants/dpa/payment-settings')->assertOk()
                ->assertJsonPath('direct.bank_account', null)
                ->assertJsonPath('direct.bank_account_set', true);
            $this->assertStringNotContainsString('1112223', (string) $response->getContent());
            $this->send('PUT', '/api/tenants/dpa/payment-settings/direct', self::settings())->assertForbidden();
        }
    }

    public function test_confirming_refusing_and_recording_refunds_need_money_and_a_fresh_confirmation(): void
    {
        [$bank, $cod] = $this->inside('dpa', fn (): array => [$this->place('bank_transfer'), $this->place('cod')]);
        [$staff, $staffKey] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);

        // 閲覧・運用の人は押せない（画面にも「入金を確認した」は出るが、操作はお金の段階だけ）
        foreach (['read', 'operate'] as $level) {
            $access->grant($staff, 'dpa', $level, 'test');
            $this->send('GET', "/api/tenants/dpa/transactions/{$bank}")->assertOk()->assertJsonPath('transaction.actions.confirm_payment', true);
            $this->send('POST', "/api/tenants/dpa/transactions/{$bank}/direct-payment/confirm", ['amount' => 4950])->assertForbidden();
            $this->send('POST', "/api/tenants/dpa/transactions/{$cod}/cod/refuse", ['restock' => true])->assertForbidden();
            $this->send('POST', "/api/tenants/dpa/transactions/{$bank}/direct-refunds", ['amount' => 100])->assertForbidden();
        }

        // お金の段階でも、直前の再確認が要る
        $access->grant($staff, 'dpa', 'money', 'test');
        $this->send('POST', "/api/tenants/dpa/transactions/{$bank}/direct-payment/confirm", ['amount' => 4950])->assertStatus(423);
        $this->inside('dpa', fn () => $this->assertSame('awaiting', PaymentAttempt::query()->where('transaction_id', $bank)->sole()->status));

        $this->reconfirm($staff, $staffKey);
        // 額が違えば確かめの一文（多い・少ないは店が外で調整）
        $this->send('POST', "/api/tenants/dpa/transactions/{$bank}/direct-payment/confirm", ['amount' => 5000])->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
        $this->send('POST', "/api/tenants/dpa/transactions/{$bank}/direct-payment/confirm", ['amount' => 5000, 'accept_difference' => true])->assertOk()
            ->assertJsonPath('transaction.order_status', 'paid')
            ->assertJsonPath('transaction.payment.method', 'bank_transfer')
            ->assertJsonPath('transaction.actions.confirm_payment', false)
            ->assertJsonPath('transaction.actions.record_refund', true)
            ->assertJsonPath('transaction.direct_refundable', 4950)
            ->assertJsonPath('transaction.refundable_amount', 0);
        // 二度目は 409（もう確かめてある）
        $this->send('POST', "/api/tenants/dpa/transactions/{$bank}/direct-payment/confirm", ['amount' => 4950])->assertStatus(409);

        // 返金の記録（受け取った額まで）
        $this->send('POST', "/api/tenants/dpa/transactions/{$bank}/direct-refunds", ['amount' => 4951])->assertStatus(409);
        $this->send('POST', "/api/tenants/dpa/transactions/{$bank}/direct-refunds", ['amount' => 4950])->assertOk()
            ->assertJsonPath('transaction.refunds.0.purpose', 'external')
            ->assertJsonPath('transaction.refunds.0.resolution', 'recorded')
            ->assertJsonPath('transaction.actions.record_refund', false);
        // 店が直に返したお金なので、台帳の返金は店の口座から（決済代行の預かりからではない）
        $this->inside('dpa', function () use ($bank): void {
            $accounts = DB::table('ledger_postings')->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
                ->where('ledger_postings.transaction_id', $bank)->where('ledger_journals.type', JournalType::Refund->value)->pluck('ledger_postings.account')->all();
            $this->assertNotSame([], $accounts);
            $this->assertNotContains(LedgerAccount::Provider->value, $accounts);
            $this->assertSame(0, app(LedgerService::class)->netPaid(Transaction::query()->findOrFail($bank)));
        });

        // 代引き: 発送の前は受取拒否を出さない・断る。発送した後に受取拒否
        $this->send('GET', "/api/tenants/dpa/transactions/{$cod}")->assertOk()
            ->assertJsonPath('transaction.order_status', 'cod_pending')
            ->assertJsonPath('transaction.payment.on_delivery', true)
            ->assertJsonPath('transaction.actions.ship', true)
            ->assertJsonPath('transaction.actions.refuse_cod', false);
        $this->send('POST', "/api/tenants/dpa/transactions/{$cod}/cod/refuse", ['restock' => true])->assertStatus(409);
        $this->send('POST', "/api/tenants/dpa/transactions/{$cod}/ship", ['fulfillment_id' => $this->inside('dpa', fn () => Fulfillment::query()->where('transaction_id', $cod)->value('id')), 'carrier' => 'yamato', 'tracking_number' => null])->assertOk()
            ->assertJsonPath('transaction.actions.refuse_cod', true)
            ->assertJsonPath('transaction.actions.confirm_payment', true)
            ->assertJsonPath('transaction.actions.complete', false);
        $this->send('POST', "/api/tenants/dpa/transactions/{$cod}/cod/refuse", ['restock' => 'x'])->assertUnprocessable();
        $this->send('POST', "/api/tenants/dpa/transactions/{$cod}/cod/refuse", ['restock' => false])->assertOk()->assertJsonPath('transaction.order_status', 'cancelled');
    }

    public function test_other_shops_orders_are_not_reached(): void
    {
        [$bank] = $this->inside('dpa', fn (): array => [$this->place('bank_transfer')]);
        [$this->operator, $this->authenticator] = $this->enrolledOperator();
        $this->reconfirm($this->operator, $this->authenticator);

        // ほかの店の経路から、ID を知っていても届かない
        $this->send('POST', "/api/tenants/dpb/transactions/{$bank}/direct-payment/confirm", ['amount' => 4950])->assertNotFound();
        $this->send('POST', "/api/tenants/dpb/transactions/{$bank}/direct-refunds", ['amount' => 100])->assertNotFound();
        $this->send('POST', "/api/tenants/dpb/transactions/{$bank}/cod/refuse", ['restock' => true])->assertNotFound();
        $this->inside('dpa', fn () => $this->assertSame('awaiting', PaymentAttempt::query()->sole()->status));
        // 与えられていない店は 404
        $this->send('POST', '/auth/logout')->assertNoContent();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'dpa', 'money', 'test');
        $this->send('POST', "/api/tenants/dpb/transactions/{$bank}/direct-payment/confirm", ['amount' => 4950])->assertNotFound();
    }

    public function test_the_list_filters_by_payment_and_the_home_shows_what_to_do(): void
    {
        [$bank, $cod, $shippedCod] = $this->inside('dpa', function (): array {
            $bank = $this->place('bank_transfer');
            $cod = $this->place('cod');
            $shipped = $this->place('cod');
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($shipped), (string) Fulfillment::query()->where('transaction_id', $shipped)->value('id'), 'yamato', null, 'operator:test');
            $this->place('bank_transfer');

            return [$bank, $cod, $shipped];
        });
        [$this->operator] = $this->enrolledOperator();

        $all = $this->send('GET', '/api/tenants/dpa/transactions')->assertOk()
            ->assertJsonPath('payment_counts', ['bank_awaiting' => 2, 'cod_unpaid' => 2])
            ->assertJsonPath('order_counts.cod_pending', 1);
        $this->assertSame(4, $all->json('meta.total'));
        $this->assertSame(2, $this->send('GET', '/api/tenants/dpa/transactions?payment=cod_unpaid')->assertOk()->json('meta.total'));
        $this->assertEqualsCanonicalizing([$cod, $shippedCod], array_column((array) $this->send('GET', '/api/tenants/dpa/transactions?payment=cod_unpaid')->json('transactions'), 'id'));
        $this->send('GET', '/api/tenants/dpa/transactions?payment=other')->assertUnprocessable();

        // ホームのやること: 期限の近い振込はまだ無い（期限は 7 日後）。発送を待つ代引き 1・入金の確認を待つ代引き 1
        $this->send('GET', '/api/tenants/dpa/overview')->assertOk()
            ->assertJsonPath('todo.to_ship', 0)
            ->assertJsonPath('todo.to_ship_cod', 1)
            ->assertJsonPath('todo.bank_awaiting', 2)
            ->assertJsonPath('todo.bank_due_soon', 0)
            ->assertJsonPath('todo.cod_to_confirm', 1);

        // 期限（11-12 23:59）まで 2 日を切ると数える（ちょうど 2 日前から）
        $this->inside('dpa', function () use ($bank): void {
            $home = app(ShopHome::class);
            $this->assertSame(0, $home->todo(new CarbonImmutable('2026-11-10 23:59:58', 'Asia/Tokyo'))['bank_due_soon']);
            $this->assertSame(2, $home->todo(new CarbonImmutable('2026-11-10 23:59:59', 'Asia/Tokyo'))['bank_due_soon']);
            // 入金を確かめた振込は数えない
            app(DirectPayments::class)->confirm(Transaction::query()->findOrFail($bank), 4950, false, 'operator:test');
            $this->assertSame(1, $home->todo(new CarbonImmutable('2026-11-11 00:00:00', 'Asia/Tokyo'))['bank_due_soon']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(): array
    {
        return ['bank_transfer' => true, 'bank_days' => 7, 'bank_account' => ['bank' => 'シュガー銀行', 'branch' => '渋谷支店', 'type' => 'ordinary', 'number' => '7654321', 'holder' => 'カ）シュガーテスト'],
            'cod' => true, 'cod_fees' => DirectMethodSettings::DEFAULT_COD_FEES, 'cod_limit' => 300000];
    }

    /**
     * 店の中で、確かめの画面と同じ決め方の指紋で注文を確定する。お客さんは注文ごとに別のメール（同じメールの入金待ちは 3 つまで）
     */
    private function place(string $payment): string
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $carts->add($cart, (string) ListingVariant::query()->value('id'), 1);
        $email = 'hanako+'.(++$this->orders).'@example.com';
        $input = CheckoutInput::fromArray(['buyer' => ['email' => $email, 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null, 'payment' => $payment]);
        $this->assertNotNull($input);
        $view = $carts->view($cart, $input->destination(), $email);
        $offer = app(PaymentChoices::class)->offer($view, $input);
        $view = $view->withPaymentFee($payment === 'cod' ? $offer->fee('cod') : 0);
        $fingerprint = CheckoutSummary::fingerprintOf($view, app(PaymentMethodSettings::class)->forCheckout($view->total()), null, '', CheckoutSummary::paymentKey($payment, $offer->bankDays, $view->paymentFee));

        return app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $fingerprint)->id;
    }
}
