<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\SalesPeriod;
use App\Admin\Services\SalesReport;
use App\Admin\Services\ShopHome;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Services\OrderReturns;
use App\Core\Order\Services\ReturnPlanner;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\OrderReturn;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentService;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeStripe;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 注文の画面の「返金する」（ADR 0048）。確かめの画面 → 実行。お金の段階の人だけ、実行は直前の再確認の後。
 * 返金の履歴（日時・額・状態・誰が）、注文の一覧の「一部返金」「全額返金」の札、ホームの売上は返金を引いた額（お客さんへのメールは OrderReturnTest）
 */
final class OrderReturnAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BODY = 'FakeForTestsOnly000';

    private FakeStripe $stripe;

    private Operator $owner;

    private SoftwareAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->stripe = FakeStripe::install();
        $this->tenant('rta');
        $this->tenant('rtb');
        foreach (['rta', 'rtb'] as $shop) {
            $this->inside($shop, fn () => app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', self::BODY.'1234']), 'webhook_secret' => implode('_', ['whsec', self::BODY.'1234'])]));
        }
        [$this->owner, $this->authenticator] = $this->enrolledOperator();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_money_staff_confirm_then_refund_part_and_then_the_rest_and_the_order_shows_it(): void
    {
        [$sale, $position] = $this->shippedOrder('rta');
        $base = "/api/tenants/rta/transactions/{$sale}";

        $this->send('GET', $base)->assertOk()
            ->assertJsonPath('transaction.returns.returnable', true)
            ->assertJsonPath('transaction.returns.available', 8800)
            ->assertJsonPath('transaction.returns.provider', true)
            ->assertJsonPath('transaction.returns.items.0.returnable', 2)
            ->assertJsonPath('transaction.refund_summary.state', null);

        // 確かめの画面（書き込まない）
        $body = ['items' => [['position' => $position, 'quantity' => 1, 'restock' => true]], 'reason' => 'returned', 'channel' => 'provider'];
        $this->send('POST', "{$base}/returns/preview", $body)->assertOk()
            ->assertJsonPath('plan.total', 4400)
            ->assertJsonPath('plan.taxes.0.rate_bp', 1000)
            ->assertJsonPath('plan.taxes.0.tax', 400)
            ->assertJsonPath('plan.lines.0.restock', true);
        $this->inside('rta', fn () => $this->assertSame(0, OrderReturn::query()->count()));

        // 実行は直前の再確認が要る
        $this->send('POST', "{$base}/returns", $body + ['expected_total' => 4400])->assertStatus(423);
        $this->reconfirm($this->owner, $this->authenticator);
        // 確かめた合計と違えば断る（ほかの返金が入った）
        $this->send('POST', "{$base}/returns", $body + ['expected_total' => 4399])->assertStatus(409)->assertJsonPath('user_message', OrderReturns::CHANGED);

        $this->send('POST', "{$base}/returns", $body + ['expected_total' => 4400])->assertOk()
            ->assertJsonPath('transaction.refund_summary.state', 'partial')
            ->assertJsonPath('transaction.refund_summary.refunded', 4400)
            ->assertJsonPath('transaction.returns.available', 4400)
            ->assertJsonPath('transaction.returns.history.0.amount', 4400)
            ->assertJsonPath('transaction.returns.history.0.by', 'Hana')
            ->assertJsonPath('transaction.returns.history.0.reason_label', '返品')
            ->assertJsonPath('transaction.returns.history.0.refund.status', 'succeeded')
            ->assertJsonPath('transaction.returns.history.0.lines.0.restock', true)
            ->assertJsonPath('transaction.order_status', 'shipped');
        $this->send('GET', '/api/tenants/rta/transactions')->assertJsonPath('transactions.0.refund_state', 'partial');
        // ホームの売上は返した額を引く（注文は数に残す）。偽の Stripe の支払いはテストのモードの印が付くので、本番の支払いの印に置き換えて数える
        $this->inside('rta', function (): void {
            // 見分けは支払いの試みの test_mode 列（番号の書き方では見分けない）
            PaymentAttempt::query()->update(['test_mode' => false]);
            $this->assertSame(['orders' => 1, 'sales' => 4400], app(ShopHome::class)->stats()['today']);
            // 売上の集計（台帳から）も返金を引き、商品別は返した数とその割合の額を引く
            $summary = app(SalesReport::class)->summary(SalesPeriod::of('today'));
            $this->assertSame([8800, 4400, 4400], [$summary['totals']['gross'], $summary['totals']['refunds'], $summary['totals']['sales']]);
            $this->assertSame([1, 4400], [$summary['products']['by_amount'][0]['quantity'], $summary['products']['by_amount'][0]['amount']]);
        });

        // 残り全部（金額だけ）で全額返金の札
        // 確かめた合計を送らない実行は断る
        $this->send('POST', "{$base}/returns", ['items' => [], 'amount' => 4400, 'reason' => 'goodwill', 'channel' => 'outside'])->assertStatus(422);
        $this->send('POST', "{$base}/returns", ['items' => [], 'amount' => 4400, 'reason' => 'goodwill', 'channel' => 'outside', 'expected_total' => 4400])->assertOk()
            ->assertJsonPath('transaction.refund_summary.state', 'full')
            ->assertJsonPath('transaction.returns.available', 0)
            ->assertJsonPath('transaction.returns.history.0.refund.channel', 'outside');
        $this->send('GET', '/api/tenants/rta/transactions')->assertJsonPath('transactions.0.refund_state', 'full');
        $this->inside('rta', fn () => $this->assertSame(0, app(ShopHome::class)->stats()['today']['sales']));
    }

    public function test_after_an_amount_only_refund_the_screen_offers_what_is_left_and_the_shop_can_return_the_item_for_nothing(): void
    {
        [$sale, $position] = $this->shippedOrder('rta');
        $base = "/api/tenants/rta/transactions/{$sale}";
        $this->reconfirm($this->owner, $this->authenticator);
        // ニット 2 点 8,800。お詫びに金額だけ 5,000 を返した（返せる残り 3,800）
        $this->send('POST', "{$base}/returns", ['items' => [], 'amount' => 5000, 'reason' => 'goodwill', 'channel' => 'provider', 'expected_total' => 5000])->assertOk();
        $item = ['items' => [['position' => $position, 'quantity' => 1, 'restock' => true]], 'reason' => 'returned', 'channel' => 'provider'];

        // 確かめの画面: 品の額 4,400・上限（既定）3,800
        $this->send('POST', "{$base}/returns/preview", $item)->assertOk()
            ->assertJsonPath('plan.total', 3800)
            ->assertJsonPath('plan.goods', ['full' => 4400, 'limit' => 3800, 'amount' => 3800]);
        // 負の額は入力の検査で、上限を越える額は見せてよい文で断る
        $this->send('POST', "{$base}/returns/preview", $item + ['goods_amount' => -1])->assertStatus(422)->assertJsonValidationErrors('goods_amount');
        $this->send('POST', "{$base}/returns/preview", $item + ['goods_amount' => 3801])->assertStatus(409)->assertJsonPath('user_message', sprintf(ReturnPlanner::GOODS_OVER, '¥3,800'));

        // 0 円: 品だけ在庫に戻す（返金の記録は増えず、返品の履歴に 0 円で残る）
        $this->send('POST', "{$base}/returns/preview", $item + ['goods_amount' => 0])->assertOk()->assertJsonPath('plan.total', 0)->assertJsonPath('plan.lines.0.restock', true);
        $this->send('POST', "{$base}/returns", $item + ['goods_amount' => 0, 'expected_total' => 0])->assertOk()
            ->assertJsonPath('transaction.returns.history.0.amount', 0)
            ->assertJsonPath('transaction.returns.history.0.lines.0.restock', true)
            ->assertJsonPath('transaction.returns.available', 3800);
        $this->inside('rta', fn () => $this->assertSame([2, 1], [OrderReturn::query()->count(), Refund::query()->count()]));
    }

    public function test_the_input_is_checked_and_only_money_staff_reach_it_within_their_own_shop(): void
    {
        [$sale, $position] = $this->shippedOrder('rta');
        $base = "/api/tenants/rta/transactions/{$sale}";
        $this->reconfirm($this->owner, $this->authenticator);

        // 外でした返金の理由は選べない・数は 1 以上・返し方は 2 つだけ
        $this->send('POST', "{$base}/returns/preview", ['items' => [], 'amount' => 100, 'reason' => 'external', 'channel' => 'provider'])->assertStatus(422);
        $this->send('POST', "{$base}/returns/preview", ['items' => [['position' => $position, 'quantity' => 0]], 'reason' => 'returned', 'channel' => 'provider'])->assertStatus(422);
        $this->send('POST', "{$base}/returns/preview", ['items' => [], 'amount' => 100, 'reason' => 'returned', 'channel' => 'cash'])->assertStatus(422);
        // 何も選ばない・発送した数を越える
        $this->send('POST', "{$base}/returns/preview", ['items' => [], 'reason' => 'returned', 'channel' => 'provider'])->assertStatus(409);
        $this->send('POST', "{$base}/returns/preview", ['items' => [['position' => $position, 'quantity' => 3]], 'reason' => 'returned', 'channel' => 'provider'])->assertStatus(409);

        // ほかの店の取引には届かない（店の境界）
        $this->send('POST', "/api/tenants/rtb/transactions/{$sale}/returns", ['items' => [], 'amount' => 100, 'reason' => 'goodwill', 'channel' => 'provider', 'expected_total' => 100])->assertNotFound();

        // 運用の段階の人は返金できない（確かめの画面も）
        $this->send('POST', '/auth/logout')->assertNoContent();
        [$staff, $authenticator] = $this->enrolledOperator('operate@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'rta', 'operate', 'test');
        $this->reconfirm($staff, $authenticator);
        $this->send('POST', "{$base}/returns/preview", ['items' => [], 'amount' => 100, 'reason' => 'goodwill', 'channel' => 'provider'])->assertForbidden();
        $this->send('POST', "{$base}/returns", ['items' => [], 'amount' => 100, 'reason' => 'goodwill', 'channel' => 'provider'])->assertForbidden();
        $this->inside('rta', fn () => $this->assertSame(0, Refund::query()->count()));
    }

    public function test_a_declined_refund_is_asked_again_from_the_order_screen(): void
    {
        [$sale] = $this->shippedOrder('rta');
        $base = "/api/tenants/rta/transactions/{$sale}";
        $this->reconfirm($this->owner, $this->authenticator);
        $this->stripe->respond = static fn (): array => ['{"error":{"type":"invalid_request_error","code":"charge_disputed","message":"x"}}', 400, []];
        $this->send('POST', "{$base}/returns", ['items' => [], 'amount' => 1000, 'reason' => 'goodwill', 'channel' => 'provider', 'expected_total' => 1000])->assertStatus(409);
        $refund = $this->inside('rta', fn (): string => Refund::query()->sole()->id);
        $this->send('GET', $base)->assertJsonPath('transaction.refunds.0.retryable', true)->assertJsonPath('transaction.returns.history.0.refund.status', 'failed');

        $this->stripe->respond = null;
        $this->send('POST', "{$base}/refunds/{$refund}/retry", ['channel' => 'provider'])->assertOk()
            ->assertJsonPath('transaction.refunds.0.retryable', false)
            ->assertJsonPath('transaction.refunds.1.status', 'succeeded')
            ->assertJsonPath('transaction.refund_summary.state', 'partial');
        $this->send('POST', "{$base}/refunds/{$refund}/retry", ['channel' => 'provider'])->assertStatus(409);
    }

    public function test_only_what_was_shipped_can_be_returned_when_the_parcels_are_split(): void
    {
        // 2 点を 2 つの荷物に分け、1 つ目だけ発送した
        [$sale, $position] = $this->shippedOrder('rta', parcels: 2);
        $base = "/api/tenants/rta/transactions/{$sale}";
        $this->send('GET', $base)->assertJsonPath('transaction.returns.items.0.shipped', 1)->assertJsonPath('transaction.returns.items.0.returnable', 1);

        $this->send('POST', "{$base}/returns/preview", ['items' => [['position' => $position, 'quantity' => 2]], 'reason' => 'returned', 'channel' => 'provider'])->assertStatus(409)
            ->assertJsonPath('user_message', '「ニット」は 1 点まで返品できます（発送した数 − 返品済みの数）。');
        $this->send('POST', "{$base}/returns/preview", ['items' => [['position' => $position, 'quantity' => 1]], 'reason' => 'returned', 'channel' => 'provider'])->assertOk()
            ->assertJsonPath('plan.total', 4400);
    }

    /**
     * ニット 2 点（4,400 × 2）を払って発送した注文（お客さんのメールあり）。parcels が 2 なら 1 点ずつの荷物に分け、1 つ目だけ発送する
     *
     * @return array{0: string, 1: int}
     */
    private function shippedOrder(string $shop, int $parcels = 1): array
    {
        return $this->inside($shop, function () use ($parcels): array {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->orderBy('id')->firstOrFail();
            $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($listing, $location, 5);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com', 'address' => ['zip' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1']]);
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 2, 1000, $location)], actor: 'test');
            $line = $sale->lines()->where('line_type', LineType::Item)->sole();
            for ($i = 0; $i < $parcels; $i++) {
                app(FulfillmentService::class)->create($sale, FulfillmentMethod::Shipping, [$line->id => intdiv(2, $parcels)], $location, ['name' => '山田 花子', 'city' => '渋谷区'], 'test');
            }

            $session = app(PaymentService::class)->startCheckout($sale, 'x', 'y');
            $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$sale->id, 'checkout.session.completed', $session->ref, $sale->id, 8800, 'jpy', 'pi_'.$sale->id));
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale->id), (string) Fulfillment::query()->where('transaction_id', $sale->id)->orderBy('id')->value('id'), 'yamato', '1234', 'test');

            return [$sale->id, $line->position];
        });
    }
}
