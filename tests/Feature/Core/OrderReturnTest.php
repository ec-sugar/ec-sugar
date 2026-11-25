<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Discount\Models\CouponRedemption;
use App\Core\Discount\Services\CouponService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Ledger\Models\LedgerPosting;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Addons\AddonSelection;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\AddonService;
use App\Core\Money\Allocation;
use App\Core\Order\ReturnRequest;
use App\Core\Order\Services\OrderReturns;
use App\Core\Order\Services\RefundReconciler;
use App\Core\Order\Services\ReturnPlanner;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Order\Services\ShippingDifferenceRefunds;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Enums\ReturnReason;
use App\Core\Payment\Events\RefundSettled;
use App\Core\Payment\Models\OrderReturn;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\RefundLine;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\RefundDeclined;
use App\Core\Payment\RefundResult;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Payment\Services\PaymentNotices;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Payment\Services\PaymentService;
use App\Core\Payment\Services\RefundRetries;
use App\Core\Payment\Services\ReturnSettlement;
use App\Core\Points\Models\OrderPoints;
use App\Core\Points\Models\PointEntry;
use App\Core\Points\Services\PointService;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Tax\TaxClass;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TaxRounding;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\Services\TransactionException;
use App\Core\Transaction\Totals;
use App\Models\Tenant;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartService;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Members\Member;
use App\Storefront\Orders\OrderMail;
use App\Storefront\Orders\SentMail;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 返品・一部返金・済んだ注文の返金（ADR 0048。お金の核）。取引を開き直さず、返金の 1 回と返金の行を足す。
 * 額は値引き・使ったポイントの割り振りを引いた実の額、消費税は税率ごと。台帳には返金が成功してから返金の仕訳と税率ごとの調整の仕訳。
 * 返金の合計は受け取った額を越えない（サービスと DB の両方）。ポイントは返した額の割合で動き、全額なら今までの全額の取り消しと同じ
 */
final class OrderReturnTest extends TestCase
{
    use DatabaseTruncation;

    private const string ACTOR = 'operator:01jtestoperator000000000000';

    private Tenant $tenant;

    private string $knit;

    private string $cookie;

    private FakeStripe $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->stripe = FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'ort', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'ort.localhost']);
        [$this->knit, $this->cookie] = $this->inside(function (): array {
            $seller = Seller::query()->value('id');
            $knit = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            $cookie = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'クッキー缶', 'unit_amount' => 1080, 'status' => 'active', 'tax_class' => TaxClass::Reduced]);
            $location = Location::query()->orderBy('id')->firstOrFail();
            app(StockService::class)->receive($knit, $location, 20);
            app(StockService::class)->receive($cookie, $location, 20);
            app(AddonService::class)->define($knit, 1, [
                ['kind' => 'choice', 'name' => 'ギフト包装', 'required' => true, 'choices' => [['label' => 'なし', 'amount' => 0], ['label' => 'あり', 'amount' => 330]]],
            ]);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => null]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);
            app(CouponService::class)->create(['code' => 'BACK', 'kind' => 'fixed', 'value' => 1000, 'name' => 'テスト'], 'test');

            return [$knit->id, $cookie->id];
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

    public function test_returning_every_line_refunds_exactly_what_was_paid_with_taxes_by_rate_and_points_like_a_full_refund(): void
    {
        $this->inside(function (): void {
            [$sale, $member] = $this->mixedOrder();
            $earned = (int) PointEntry::query()->where('transaction_id', $sale->id)->where('kind', 'earn')->value('points');
            $this->assertGreaterThan(0, $earned);
            $this->assertSame(1000 - 500 + $earned, app(PointService::class)->balance($member->id));

            $refund = app(OrderReturns::class)->record($sale, $this->everything($sale), null, self::ACTOR);

            // 受け取った額をちょうど全部返した（値引き・ポイントの後の現金）
            $this->assertSame(['succeeded', (int) $sale->total_amount], [$refund->status, $refund->amount]);
            $this->assertSame([(int) $sale->total_amount], array_column($this->stripe->refunds, 'amount'));
            $this->assertSame("refund-return-{$refund->return_id}", $this->stripe->lastHeader('Idempotency-Key'));
            // 税率ごとの消費税を合わせると、注文の消費税にちょうど戻る。率は 8% と 10% の 2 つ
            $taxes = OrderReturn::query()->sole()->taxes;
            $this->assertSame([800, 1000], array_column($taxes, 'rate_bp'));
            $this->assertSame((int) $sale->tax_amount, array_sum(array_column($taxes, 'tax')));
            $this->assertSame((int) $sale->total_amount, array_sum(array_column($taxes, 'gross')));
            // 台帳: 返金 1 本、率ごとの調整 2 本。全部の口座が 0 に戻る（請求も消費税も受け取ったお金も）
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Refund)->count());
            $this->assertSame(['return-rate-1000', 'return-rate-800'], LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Adjustment)->orderBy('reference')->pluck('reference')->all());
            $this->assertSame([], array_filter(app(LedgerService::class)->balances($sale)));
            // 取引の状態は戻さない（発送済みのまま）
            $this->assertSame([TransactionStatus::Open, Phase::Fulfillment], [Transaction::query()->findOrFail($sale->id)->status, Transaction::query()->findOrFail($sale->id)->phase]);

            // ポイントは今までの全額の返金と同じ: 使った 500 を 1 行で戻し、付けた分を 1 行で取り消す（cause なし・refund）
            $release = PointEntry::query()->where('transaction_id', $sale->id)->where('kind', 'release')->sole();
            $revoke = PointEntry::query()->where('transaction_id', $sale->id)->where('kind', 'revoke')->sole();
            $this->assertSame([500, null], [$release->points, $release->cause]);
            $this->assertSame([-$earned, 0, 'refund'], [$revoke->points, $revoke->shortfall, $revoke->cause]);
            $this->assertSame(1000, app(PointService::class)->balance($member->id));
            $this->assertSame('revoked', app(PointService::class)->forOrder($sale->id)['state'] ?? null);

            // もう返すものは無い（品も金額も）
            $this->assertRefused(fn () => app(OrderReturns::class)->record($sale, new ReturnRequest([], false, 1, ReturnReason::Goodwill, 'provider'), null, self::ACTOR), ReturnPlanner::OVER);
        });
    }

    public function test_a_partial_return_refunds_the_real_amount_of_the_line_and_moves_points_by_the_share(): void
    {
        $this->inside(function (): void {
            [$sale, $member] = $this->mixedOrder();
            $lines = TransactionLine::query()->where('transaction_id', $sale->id)->get()->keyBy('position');
            $knit = $lines->first(fn (TransactionLine $l): bool => $l->listing_id === $this->knit);
            $addon = $lines->first(static fn (TransactionLine $l): bool => $l->kind === 'addon');
            $discounts = CouponRedemption::query()->sole()->allocations;
            $points = OrderPoints::query()->findOrFail($sale->id)->allocations;
            // 実の額 = 明細の額 − 値引きの割り振り − ポイントの割り振り。2 点のうち 1 点は切り捨ての半分
            $knitNet = $knit->amount - ($discounts[$knit->position] ?? 0) - ($points[$knit->position] ?? 0);
            $addonNet = $addon->amount - ($discounts[$addon->position] ?? 0) - ($points[$addon->position] ?? 0);
            $expected = intdiv($knitNet, 2) + intdiv($addonNet, 2);
            $earned = (int) PointEntry::query()->where('transaction_id', $sale->id)->where('kind', 'earn')->value('points');
            $balance = app(PointService::class)->balance($member->id);

            $request = new ReturnRequest([$knit->position => ['quantity' => 1, 'restock' => false, 'addons' => true]], false, 0, ReturnReason::Returned, 'provider');
            $plan = app(OrderReturns::class)->preview($sale, $request);
            $this->assertSame($expected, $plan->total);
            // 標準の率だけ。消費税は「返す前の残りの税 − 返した後の残りの税」
            $standard = (int) TransactionLine::query()->where('transaction_id', $sale->id)->where('tax_rate_bp', 1000)->sum('amount');
            $this->assertSame([['rate_bp' => 1000, 'gross' => $expected, 'tax' => $this->tax(1000, $standard) - $this->tax(1000, $standard - $expected)]], $plan->taxes);

            $refund = app(OrderReturns::class)->record($sale, $request, $plan->total, self::ACTOR);
            $this->assertSame([$expected, 'succeeded'], [$refund->amount, $refund->status]);
            // 台帳: 標準の率の調整 1 本（請求を返した額、納める消費税を返した税だけ減らす）
            $adjustment = LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Adjustment)->sole();
            $this->assertSame('return-rate-1000', $adjustment->reference);
            $this->assertSame(-$plan->taxes[0]['tax'], (int) LedgerPosting::query()->where('journal_id', $adjustment->id)->where('account', 'tax')->sum('amount'));
            $this->assertSame(0, app(LedgerService::class)->balances($sale)['customer'] ?? 0);

            // ポイント: 返した額 ÷ 受け取った額の割合（切り捨て）で、使った分を戻し、付けた分を取り消す
            $paid = (int) $sale->total_amount;
            $back = intdiv(500 * $expected, $paid);
            $take = intdiv($earned * $expected, $paid);
            $this->assertSame([$back, 'partial'], [(int) PointEntry::query()->where('kind', 'release')->value('points'), PointEntry::query()->where('kind', 'release')->value('cause')]);
            $this->assertSame([-$take, 'partial'], [(int) PointEntry::query()->where('kind', 'revoke')->value('points'), PointEntry::query()->where('kind', 'revoke')->value('cause')]);
            $this->assertSame($balance + $back - $take, app(PointService::class)->balance($member->id));
            // 付いたまま（一部の取り消し）。もう一度割合を合わせても何も書かない
            $this->assertSame('earned', app(PointService::class)->forOrder($sale->id)['state'] ?? null);
            app(PointService::class)->refundedPart($sale->id, $expected, $paid, 'test');
            $this->assertSame(2, PointEntry::query()->where('transaction_id', $sale->id)->whereIn('kind', ['release', 'revoke'])->count());

            // 2 点目は同じ品をもう 1 点（累計の差なので、2 点の合計は実の額にちょうど届く）
            app(OrderReturns::class)->record($sale, $request, null, self::ACTOR);
            $this->assertSame($knitNet + $addonNet, (int) RefundLine::query()->whereIn('kind', ['item', 'addon'])->sum('amount'));
            // 3 点目は無い（発送した数まで）
            $this->assertRefused(fn () => app(OrderReturns::class)->record($sale, $request, null, self::ACTOR), '0 点まで');

            // 残り（クッキーと送料）を返すと全額: 戻しと取り消しの合計は使った数・付けた数に届き、残高は注文の前に戻る
            $cookie = $lines->first(fn (TransactionLine $l): bool => $l->listing_id === $this->cookie);
            app(OrderReturns::class)->record($sale, new ReturnRequest([$cookie->position => ['quantity' => 3, 'restock' => false, 'addons' => false]], true, 0, ReturnReason::Returned, 'provider'), null, self::ACTOR);
            $this->assertSame(0, app(LedgerService::class)->netPaid($sale));
            $this->assertSame(500, (int) PointEntry::query()->where('transaction_id', $sale->id)->where('kind', 'release')->sum('points'));
            $this->assertSame($earned, (int) PointEntry::query()->where('transaction_id', $sale->id)->where('kind', 'revoke')->sum(DB::raw('shortfall - points')));
            $this->assertSame(1000, app(PointService::class)->balance($member->id));
            $this->assertSame([], array_filter(app(LedgerService::class)->balances($sale)));
            $this->assertSame((int) $sale->tax_amount, (int) collect(OrderReturn::query()->get())->sum(static fn (OrderReturn $r): int => array_sum(array_column($r->taxes, 'tax'))));
        });
    }

    public function test_the_refund_total_stops_at_exactly_what_was_received_in_the_service_and_in_the_database(): void
    {
        $this->inside(function (): void {
            $sale = $this->simpleOrder();
            $paid = (int) $sale->total_amount;
            $returns = app(OrderReturns::class);

            // 1 円超える金額だけの返金は断り、何も残さない
            $this->assertRefused(fn () => $returns->record($sale, $this->amount($paid + 1), null, self::ACTOR), ReturnPlanner::OVER);
            $this->assertSame(0, OrderReturn::query()->count());
            $this->assertSame([], $this->stripe->refunds);

            // 受け取った額ちょうどは返せる。その後は 1 円も返せない
            $this->assertSame($paid, $returns->record($sale, $this->amount($paid), null, self::ACTOR)?->amount);
            $this->assertRefused(fn () => $returns->record($sale, $this->amount(1), null, self::ACTOR), ReturnPlanner::OVER);

            // サービスをすり抜けた返金の行も DB が断る（受け取った額を越える）
            $attempt = PaymentAttempt::query()->where('transaction_id', $sale->id)->where('status', 'paid')->sole();
            $this->expectRefusal(fn () => Refund::create(['transaction_id' => $sale->id, 'attempt_id' => $attempt->id, 'purpose' => 'shipping', 'amount' => 1, 'currency' => 'jpy',
                'payment_ref' => 'pi_x', 'idempotency_key' => 'sneak', 'status' => 'requested']), 'refunds_over_received');
            // 返金の行も明細の数・額を越えられない
            $return = OrderReturn::query()->sole();
            $position = (int) TransactionLine::query()->where('transaction_id', $sale->id)->where('line_type', 'item')->value('position');
            $this->expectRefusal(fn () => RefundLine::create(['return_id' => $return->id, 'transaction_id' => $sale->id, 'kind' => 'item', 'line_position' => $position, 'quantity' => 3, 'amount' => 0, 'tax_rate_bp' => 1000]), 'refund_lines_over_quantity');
            // 返金の 1 回と返金の行は追記だけ
            $this->expectRefusal(fn () => DB::table('order_returns')->where('id', $return->id)->update(['amount' => 1]), '追記専用');
        });
    }

    public function test_two_returns_at_once_never_refund_more_than_was_received(): void
    {
        $this->inside(function (): void {
            $sale = $this->simpleOrder();
            $paid = (int) $sale->total_amount;
            $second = null;

            // 1 人目の Stripe への返金の最中（台帳のロックの外）に、2 人目が残り全部より多く返そうとする
            $this->stripe->before = function (string $method, string $url) use ($sale, &$second): void {
                $second = 'tried';

                try {
                    app(OrderReturns::class)->record(Transaction::query()->findOrFail($sale->id), $this->amount(2), null, 'operator:second');
                    $second = 'refunded';
                } catch (TransactionException $e) {
                    $second = $e->getMessage();
                }
            };

            app(OrderReturns::class)->record($sale, $this->amount($paid - 1), null, self::ACTOR);

            // 1 人目の途中の返金（答え待ち）も数えるので、2 人目は残りの 1 円を越えて返せない
            $this->assertStringStartsWith(ReturnPlanner::OVER, (string) $second);
            $this->assertSame([$paid - 1], Refund::query()->where('transaction_id', $sale->id)->pluck('amount')->all());
            $this->assertSame($paid - 1, -(int) LedgerPosting::query()->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
                ->where('ledger_journals.type', 'refund')->where('ledger_postings.transaction_id', $sale->id)->where('account', 'customer')->sum('ledger_postings.amount'));
        });
    }

    public function test_returned_items_go_back_to_stock_only_when_chosen_and_not_past_what_was_shipped(): void
    {
        $this->inside(function (): void {
            $sale = $this->simpleOrder(quantity: 2);
            $position = (int) TransactionLine::query()->where('transaction_id', $sale->id)->where('line_type', 'item')->value('position');
            $stock = fn (): array => [(int) InventoryStock::query()->where('listing_id', $this->knit)->sum('quantity'), (int) InventoryStock::query()->where('listing_id', $this->knit)->sum('reserved')];
            // 発送の後、完了の前: 押さえたまま（数は 20、押さえ 2）
            $this->assertSame([20, 2], $stock());

            // 在庫に戻さない（既定）
            app(OrderReturns::class)->record($sale, $this->item($position, 1, restock: false), null, self::ACTOR);
            $unchanged = $stock();
            $this->assertSame([20, 2], $unchanged);
            $this->assertSame(0, DomainEvent::query()->where('type', 'inventory.returned')->count());

            // 在庫に戻す: 種類の在庫に 1 足し、記録を残す。完了で 2 点を出庫しても、返した 1 点は在庫に残る
            app(OrderReturns::class)->record($sale, $this->item($position, 1, restock: true), null, self::ACTOR);
            $this->assertSame([21, 2], $stock());
            $event = DomainEvent::query()->where('type', 'inventory.returned')->sole();
            $this->assertEquals(['transaction' => $sale->id, 'quantity' => 1, 'quantity_after' => 21], array_intersect_key($event->payload, ['transaction' => 1, 'quantity' => 1, 'quantity_after' => 1]));
            app(SaleOrderService::class)->complete(Transaction::query()->findOrFail($sale->id), 'test');
            $this->assertSame([19, 0], $stock());

            // 発送した 2 点を返し終えたので、もう返せない
            $this->assertRefused(fn () => app(OrderReturns::class)->record(Transaction::query()->findOrFail($sale->id), $this->item($position, 1), null, self::ACTOR), '0 点まで');
        });
    }

    public function test_the_tax_of_several_partial_refunds_adds_up_to_the_tax_of_the_order(): void
    {
        $this->inside(function (): void {
            // ニット 4,400 ＋ 送料 550 ＝ 4,950（標準 10%、消費税 450）。1 円と残りに分けても、税の合計は 450 にちょうど戻る
            $sale = $this->simpleOrder();
            $this->assertSame([4950, 450], [(int) $sale->total_amount, (int) $sale->tax_amount]);
            $first = app(OrderReturns::class)->preview($sale, $this->amount(1));
            // 返す前の残りの税（450）− 返した後の残りの税（4,949 の税は 449）
            $this->assertSame([['rate_bp' => 1000, 'gross' => 1, 'tax' => 1]], $first->taxes);
            app(OrderReturns::class)->record($sale, $this->amount(1), null, self::ACTOR);
            app(OrderReturns::class)->record($sale, $this->amount(4949), null, self::ACTOR);

            $this->assertSame(450, (int) collect(OrderReturn::query()->get())->sum(static fn (OrderReturn $r): int => array_sum(array_column($r->taxes, 'tax'))));
            $this->assertSame([], array_filter(app(LedgerService::class)->balances($sale)));
        });
    }

    public function test_a_completed_order_can_be_refunded_and_keeps_its_state_and_an_unshipped_order_cannot(): void
    {
        $this->inside(function (): void {
            $sale = $this->simpleOrder();
            app(SaleOrderService::class)->complete($sale, 'test');
            $done = Transaction::query()->findOrFail($sale->id);
            $this->assertSame([TransactionStatus::Completed, Phase::Settlement], [$done->status, $done->phase]);

            app(OrderReturns::class)->record($done, $this->amount(1000), null, self::ACTOR);
            $after = Transaction::query()->findOrFail($sale->id);
            // 精算まで済んだ取引のまま、返金だけを足す
            $this->assertSame([TransactionStatus::Completed, Phase::Settlement], [$after->status, $after->phase]);
            $this->assertSame((int) $sale->total_amount - 1000, app(LedgerService::class)->netPaid($after));

            // 発送の前（入金済み）の注文は、ここからは返せない（取消で全額）
            $unshipped = $this->simpleOrder(ship: false);
            $this->assertRefused(fn () => app(OrderReturns::class)->record($unshipped, $this->amount(100), null, self::ACTOR), OrderReturns::NOT_RETURNABLE);
        });
    }

    public function test_a_declined_refund_keeps_the_return_and_can_be_asked_again_with_a_new_key_or_recorded_as_paid_outside(): void
    {
        $this->inside(function (): void {
            $sale = $this->simpleOrder();
            $this->stripe->respond = static fn (): array => ['{"error":{"type":"invalid_request_error","code":"charge_disputed","message":"x"}}', 400, []];

            try {
                app(OrderReturns::class)->record($sale, $this->amount(1000), null, self::ACTOR);
                $this->fail('断られたのに返金した');
            } catch (RefundDeclined) {
                // 断られた
            }

            // 返金の 1 回は残り、返金は失敗。台帳には何も書かない。断られた返品の分は空けておく（ほかの返金に使わせない）
            $failed = Refund::query()->sole();
            $this->assertSame(['failed', 1, 0], [$failed->status, OrderReturn::query()->count(), LedgerJournal::query()->where('transaction_id', $sale->id)->whereIn('type', ['refund', 'adjustment'])->count()]);
            $this->assertTrue(app(RefundRetries::class)->retryable($failed));
            $this->assertSame((int) $sale->total_amount - 1000, app(ReturnSettlement::class)->available($sale));

            // 頼み直す: 新しい冪等キーで（前の断りを Stripe が返し続けない）
            $this->stripe->respond = null;
            $retried = app(OrderReturns::class)->retry($sale, $failed->id, 'provider', self::ACTOR);
            $this->assertSame('succeeded', $retried->status);
            $this->assertSame('refund-return-'.$failed->return_id.'-2', $this->stripe->lastHeader('Idempotency-Key'));
            $this->assertSame('retried', Refund::query()->findOrFail($failed->id)->resolution);
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Refund)->count());
            // 二度目のやり直しは断る（もう済んだ）
            $this->assertRefused(fn () => app(OrderReturns::class)->retry($sale, $failed->id, 'provider', self::ACTOR), RefundRetries::NOT_RETRYABLE);

            // 外で返した記録にする: その場で済み、台帳は店から直に返した向き（payee が受け取る）
            $this->stripe->respond = static fn (): array => ['{"error":{"type":"invalid_request_error","code":"charge_disputed","message":"x"}}', 400, []];

            try {
                app(OrderReturns::class)->record($sale, $this->amount(500), null, self::ACTOR);
            } catch (RefundDeclined) {
                // 断られた
            }

            $second = Refund::query()->where('status', 'failed')->whereNull('resolution')->sole();
            $outside = app(OrderReturns::class)->retry($sale, $second->id, 'outside', self::ACTOR);
            $this->assertSame(['succeeded', 'outside', null], [$outside->status, $outside->channel, $outside->provider_ref]);
            $journal = LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Refund)->orderByDesc('occurred_at')->orderByDesc('id')->first();
            $this->assertSame(500, (int) LedgerPosting::query()->where('journal_id', $journal?->id)->where('account', 'seller')->sum('amount'));
        });
    }

    public function test_an_amount_mismatch_holds_the_refund_and_blocks_new_returns_until_resolved(): void
    {
        $this->inside(function (): void {
            $sale = $this->simpleOrder();
            $this->stripe->respond = static fn (): array => [(string) json_encode(['id' => 're_short', 'object' => 'refund', 'amount' => 900, 'status' => 'succeeded']), 200, []];

            $this->assertRefused(fn () => app(OrderReturns::class)->record($sale, $this->amount(1000), null, self::ACTOR), PaymentRefunds::HELD);
            $this->assertSame(['held', 'amount_mismatch'], [Refund::query()->sole()->status, Refund::query()->sole()->failure_reason]);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Refund)->count());
            $this->stripe->respond = null;
            $this->assertRefused(fn () => app(OrderReturns::class)->record($sale, $this->amount(1), null, self::ACTOR), OrderReturns::BUSY);
        });
    }

    public function test_a_pending_refund_is_booked_once_even_when_the_notice_comes_twice_and_an_unknown_answer_resumes_with_the_same_key(): void
    {
        $this->inside(function (): void {
            $sale = $this->simpleOrder();
            $this->stripe->refundStatus = 'pending';
            $refund = app(OrderReturns::class)->record($sale, $this->amount(1000), null, self::ACTOR);
            $this->assertSame('pending', $refund->status);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Refund)->count());
            $this->assertSame([], $this->settledNotices($sale->id));

            // 成功の知らせが 2 回: 台帳の返金・調整は 1 回だけ
            foreach (['evt_a', 'evt_b'] as $id) {
                app(PaymentNotices::class)->refundUpdated('stripe', $id, 'refund.updated', new RefundResult((string) $refund->provider_ref, 'succeeded', null, 1000, 'pi_'.$sale->id));
            }
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Refund)->count());
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Adjustment)->count());
            // 返金が確かになった知らせは、返金と同じトランザクションで 1 回だけ記録に残る（コミットの後に受け手へ届く）
            $notices = $this->settledNotices($sale->id);
            $this->assertCount(1, $notices);
            $this->assertTrue($notices[0]->purpose === 'return' && $notices[0]->amount === 1000 && ! $notices[0]->full && $notices[0]->lines === [['kind' => 'amount', 'position' => null, 'quantity' => 0, 'amount' => 1000]]);

            // 答えが分からない（Stripe に届かない）: 記録は答え待ちのまま。見回りが同じ冪等キーで続きから進め、返金は 1 回
            $this->stripe->refundStatus = 'succeeded';
            $this->stripe->respond = static fn (): array => ['{"error":{"type":"api_error","message":"down"}}', 500, []];
            $this->assertRefused(fn () => app(OrderReturns::class)->record($sale, $this->amount(300), null, self::ACTOR), '');
            $waiting = Refund::query()->where('status', 'requested')->sole();
            $this->stripe->respond = null;
            $this->assertSame('resumed', app(RefundReconciler::class)->reconcile($waiting, CarbonImmutable::now()->addMinutes(11)));
            $this->assertSame('succeeded', Refund::query()->findOrFail($waiting->id)->status);
            $this->assertSame("refund-return-{$waiting->return_id}", $this->stripe->lastHeader('Idempotency-Key'));
            $this->assertSame(2, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Refund)->count());
        });
    }

    public function test_a_dashboard_refund_recorded_by_the_shop_adds_an_amount_line_and_lowers_the_charge_by_rate(): void
    {
        $this->inside(function (): void {
            $sale = $this->simpleOrder();
            app(PaymentNotices::class)->refundUpdated('stripe', 'evt_dash', 'refund.created', new RefundResult('re_dash', 'succeeded', null, 700, 'pi_'.$sale->id));
            $held = Refund::query()->sole();
            $this->assertSame(['external', 'held'], [$held->purpose, $held->status]);

            app(PaymentRefunds::class)->resolve($held, 'recorded', 700, self::ACTOR);
            $line = RefundLine::query()->sole();
            $this->assertSame(['amount', 700, 1000], [$line->kind, $line->amount, $line->tax_rate_bp]);
            $this->assertSame(ReturnReason::External, OrderReturn::query()->sole()->reason);
            $this->assertSame(OrderReturn::query()->sole()->id, Refund::query()->sole()->return_id);
            $this->assertSame(1, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Adjustment)->count());
            $this->assertSame(0, app(LedgerService::class)->balances($sale)['customer'] ?? 0);
        });
    }

    public function test_a_cancel_refund_that_failed_after_the_cancel_can_be_asked_again(): void
    {
        $this->inside(function (): void {
            $sale = $this->simpleOrder(ship: false);
            $this->stripe->refundStatus = 'pending';
            app(SaleOrderService::class)->cancel($sale, self::ACTOR);
            $pending = Refund::query()->sole();
            $this->assertSame(TransactionStatus::Cancelled, Transaction::query()->findOrFail($sale->id)->status);

            // 取り消した後で、Stripe が返金を失敗にした（お金が戻っていない）
            app(PaymentNotices::class)->refundUpdated('stripe', 'evt_fail', 'refund.updated', new RefundResult((string) $pending->provider_ref, 'failed', 'expired_or_canceled_card', (int) $sale->total_amount, 'pi_'.$sale->id));
            $this->assertTrue(app(RefundRetries::class)->retryable(Refund::query()->sole()));
            // 取消の頼み直しは Stripe へだけ
            $this->assertRefused(fn () => app(OrderReturns::class)->retry($sale, $pending->id, 'outside', self::ACTOR), RefundRetries::NOT_RETRYABLE);

            $this->stripe->refundStatus = 'succeeded';
            $again = app(OrderReturns::class)->retry($sale, $pending->id, 'provider', self::ACTOR);
            $this->assertSame(['cancel', 'succeeded'], [$again->purpose, $again->status]);
            $this->assertSame("refund-cancel-{$sale->id}-2", $this->stripe->lastHeader('Idempotency-Key'));
            $this->assertSame(0, app(LedgerService::class)->netPaid($sale));
        });
    }

    public function test_a_partial_revoke_never_takes_the_balance_below_zero(): void
    {
        $this->inside(function (): void {
            [$sale, $member] = $this->mixedOrder();
            // 付いた分も含めて使い切った
            app(PointService::class)->adjust($member, -app(PointService::class)->balance($member->id), '使い切った例', 'test');
            $this->assertSame(0, app(PointService::class)->balance($member->id));
            $earned = (int) PointEntry::query()->where('transaction_id', $sale->id)->where('kind', 'earn')->value('points');
            $half = intdiv((int) $sale->total_amount, 2);

            app(OrderReturns::class)->record($sale, $this->amount($half), null, self::ACTOR);
            // 戻した分（使ったポイントの割合）は残高に入り、取り消しはその残高まで。足りない分は shortfall に残す
            $back = intdiv(500 * $half, (int) $sale->total_amount);
            $take = intdiv($earned * $half, (int) $sale->total_amount);
            $revoke = PointEntry::query()->where('kind', 'revoke')->sole();
            $this->assertSame([-min($take, $back), $take - min($take, $back)], [$revoke->points, $revoke->shortfall]);
            $this->assertGreaterThanOrEqual(0, app(PointService::class)->balance($member->id));
            // DB も付けた数より多くは取り消させない
            $this->expectRefusal(fn () => PointEntry::create(['customer_account_id' => $member->id, 'kind' => 'revoke', 'points' => 0, 'shortfall' => $earned, 'cause' => 'partial', 'transaction_id' => $sale->id, 'occurred_at' => now()]), 'point_entries_over_earned');
        });
    }

    public function test_the_customer_is_told_the_amount_and_the_card_delay_without_the_address(): void
    {
        Mail::fake();
        $this->inside(function (): void {
            $sale = $this->simpleOrder();
            app(OrderReturns::class)->record($sale, $this->amount(1200), null, self::ACTOR);
            // 外で返した記録は、カードの日数の代わりにお店の案内を
            app(OrderReturns::class)->record($sale, new ReturnRequest([], false, 300, ReturnReason::Goodwill, 'outside'), null, self::ACTOR);
            $this->assertSame(2, SentMail::query()->where('kind', 'order_refunded')->count());
        });

        $texts = [];
        Mail::assertSent(OrderMail::class, function (OrderMail $mail) use (&$texts): bool {
            if ($mail->kind === 'order_refunded') {
                $texts[] = view('mail.order-text', ['kind' => $mail->kind, 'shopName' => $mail->shopName, 'order' => $mail->order, 'link' => $mail->link, 'refund' => $mail->refund])->render()
                    ."\n".view('mail.order', ['kind' => $mail->kind, 'shopName' => $mail->shopName, 'order' => $mail->order, 'link' => $mail->link, 'refund' => $mail->refund])->render();
            }

            return true;
        });
        $this->assertCount(2, $texts);
        $this->assertStringContainsString('¥1,200 を返金しました', $texts[0]);
        $this->assertStringContainsString('数日', $texts[0]);
        $this->assertStringContainsString('¥300', $texts[1]);
        $this->assertStringContainsString('ショップからのご案内', $texts[1]);

        foreach ($texts as $text) {
            foreach (['渋谷', '1500001', '09012345678'] as $personal) {
                $this->assertStringNotContainsString($personal, $text);
            }
        }
    }

    public function test_a_partial_dashboard_refund_before_shipping_then_a_cancel_brings_every_account_back_to_zero(): void
    {
        $this->inside(function (): void {
            // 発送の前に Stripe の画面で一部を返し、店の人が確かめて記録した後に取り消す（見直しの中 1）
            $sale = $this->simpleOrder(ship: false);
            app(PaymentNotices::class)->refundUpdated('stripe', 'evt_early', 'refund.created', new RefundResult('re_early', 'succeeded', null, 700, 'pi_'.$sale->id));
            app(PaymentRefunds::class)->resolve(Refund::query()->sole(), 'recorded', 700, self::ACTOR);
            // 発送の前は返金の仕訳だけ（調整は書かない。返品の 1 回も作らない）
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Adjustment)->count());
            $this->assertSame(0, OrderReturn::query()->count());

            $result = app(SaleOrderService::class)->cancel(Transaction::query()->findOrFail($sale->id), self::ACTOR);
            $this->assertSame((int) $sale->total_amount - 700, $result['refunded']);
            $this->assertSame([], array_filter(app(LedgerService::class)->balances($sale)));
        });
    }

    public function test_a_held_return_refund_is_recorded_at_the_confirmed_amount_or_asked_again_after_it_was_dismissed(): void
    {
        $this->inside(function (): void {
            $sale = $this->simpleOrder();
            $short = static fn (): array => [(string) json_encode(['id' => 're_short_'.bin2hex(random_bytes(4)), 'object' => 'refund', 'amount' => 900, 'status' => 'succeeded']), 200, []];

            // 1 回目: Stripe は 900 円と答えた（頼んだのは 1,000 円）→ 止める。店の人が Stripe で 900 円を確かめて記録する
            $this->stripe->respond = $short;
            $this->assertRefused(fn () => app(OrderReturns::class)->record($sale, $this->amount(1000), null, self::ACTOR), PaymentRefunds::HELD);
            $this->stripe->respond = null;
            app(PaymentRefunds::class)->resolve(Refund::query()->sole(), 'recorded', 900, self::ACTOR);

            // 返金の行は確かめた額にそろう（差は金額だけの行で −100）。台帳は 900 円の返金と、900 円分の調整
            $return = OrderReturn::query()->sole();
            $this->assertSame([900, -100], [(int) $return->lines()->sum('amount'), (int) $return->lines()->where('amount', '<', 0)->sum('amount')]);
            $this->assertSame((int) $sale->total_amount - 900, app(LedgerService::class)->netPaid($sale));
            $this->assertSame(900, (int) LedgerPosting::query()->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
                ->where('ledger_journals.type', 'adjustment')->where('ledger_postings.transaction_id', $sale->id)->where('account', 'customer')->sum('ledger_postings.amount'));
            $this->assertSame(0, app(LedgerService::class)->balances($sale)['customer'] ?? 0);
            $this->assertSame(1, DomainEvent::query()->where('type', 'order.return_corrected')->count());
            $this->assertNotEmpty(array_filter($this->settledNotices($sale->id), static fn (RefundSettled $e): bool => $e->purpose === 'return' && $e->returnId === $return->id && $e->amount === 900 && count($e->lines) === 2));

            // 2 回目: また額が違って止めたが、Stripe に返金は無かった →「無かった」で閉じる。返品の分は空けておき、頼み直せる（新しい冪等キー）
            $this->stripe->respond = $short;
            $this->assertRefused(fn () => app(OrderReturns::class)->record($sale, $this->amount(1000), null, self::ACTOR), PaymentRefunds::HELD);
            $this->stripe->respond = null;
            $held = Refund::query()->where('status', 'held')->sole();
            app(PaymentRefunds::class)->resolve($held, 'dismissed', null, self::ACTOR);
            $dismissed = Refund::query()->findOrFail($held->id);
            $this->assertTrue(app(RefundRetries::class)->retryable($dismissed));
            $this->assertSame((int) $sale->total_amount - 900 - 1000, app(ReturnSettlement::class)->available($sale));

            $again = app(OrderReturns::class)->retry($sale, $held->id, 'provider', self::ACTOR);
            $this->assertSame(['succeeded', 1000], [$again->status, $again->amount]);
            $this->assertSame("refund-return-{$held->return_id}-2", $this->stripe->lastHeader('Idempotency-Key'));
            $this->assertSame('retried', Refund::query()->findOrFail($held->id)->resolution);
            $this->assertFalse(app(RefundRetries::class)->retryable(Refund::query()->findOrFail($held->id)));
        });
    }

    public function test_only_one_return_refund_is_in_flight_and_the_notice_is_matched_by_our_refund_id(): void
    {
        $this->inside(function (): void {
            $sale = $this->simpleOrder();

            // Stripe に届かない: 1 人目の返品の返金は答え待ち。2 人目の同じ額の「金額だけ」は断る（途中の返品の返金は 1 注文に 1 つ）
            $this->stripe->respond = static fn (): array => ['{"error":{"type":"api_error","message":"down"}}', 500, []];
            $this->assertRefused(fn () => app(OrderReturns::class)->record($sale, $this->amount(500), null, self::ACTOR), '');
            $this->assertRefused(fn () => app(OrderReturns::class)->record($sale, $this->amount(500), null, 'operator:second'), OrderReturns::BUSY);
            $this->assertSame(1, Refund::query()->count());
            $waiting = Refund::query()->sole();
            // DB も 2 つ目の途中の返品の返金を断る
            $this->expectRefusal(fn () => Refund::create(['transaction_id' => $sale->id, 'attempt_id' => $waiting->attempt_id, 'return_id' => $waiting->return_id, 'purpose' => 'return', 'amount' => 1,
                'currency' => 'jpy', 'payment_ref' => $waiting->payment_ref, 'idempotency_key' => 'sneak-return', 'status' => 'requested']), 'refunds_one_');

            // 送料の差額の返金も、途中の返品の返金があれば待つ
            // （贈り物の受け取りで差額 100 円が決まった形に置き換える）
            DB::table('order_shipping')->where('transaction_id', $sale->id)->update(['basis' => 'highest', 'rates' => json_encode(['東京都' => 450]), 'settled_fee' => 450]);
            $this->assertSame('waiting', app(ShippingDifferenceRefunds::class)->refund($sale->id, self::ACTOR));
            $this->assertSame(0, Refund::query()->where('purpose', 'shipping')->count());

            // 返金にはこちらの記録の ID を載せる。知らせはその ID で当てる（Stripe の返金の ID がまだ無くても、同じ額のほかの返金と取り違えない）
            $this->stripe->respond = null;
            $this->assertSame(['ec_sugar_refund' => $waiting->id], $this->stripe->lastParams()['metadata'] ?? null);
            // ほかの支払いの ID を名乗る知らせは当てない（外の返金として残すだけ）
            app(PaymentNotices::class)->refundUpdated('stripe', 'evt_wrong', 'refund.updated', new RefundResult('re_wrong', 'succeeded', null, 500, 'pi_other', $waiting->id));
            $this->assertSame('requested', Refund::query()->findOrFail($waiting->id)->status);
            // 支払いの ID の無い知らせでも、こちらの記録の ID があれば当てる
            app(PaymentNotices::class)->refundUpdated('stripe', 'evt_ours', 'refund.updated', new RefundResult('re_ours', 'succeeded', null, 500, null, $waiting->id));
            $this->assertSame(['succeeded', 're_ours'], [Refund::query()->findOrFail($waiting->id)->status, Refund::query()->findOrFail($waiting->id)->provider_ref]);
            $this->assertSame(0, Refund::query()->where('purpose', 'external')->count());
        });
    }

    public function test_a_free_item_can_be_returned_to_stock_without_a_refund(): void
    {
        $this->inside(function (): void {
            // 0 円の品（おまけ）をニットと一緒に送った注文
            app(ShopSettingsService::class)->setSellFreeItems(true, 'test');
            $free = Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => 'おまけ', 'unit_amount' => 0, 'status' => 'active']);
            app(StockService::class)->receive($free, Location::query()->orderBy('id')->firstOrFail(), 5);
            $carts = app(CartService::class);
            [$cart] = $carts->create();
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->knit)->value('id'), 1, AddonSelection::resolve(Listing::query()->findOrFail($this->knit), [(string) ListingAddon::query()->where('listing_id', $this->knit)->value('id') => '0']));
            $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $free->id)->value('id'), 1);
            $order = $this->place($cart, 0, 'jiro@example.com');
            $this->pay($order);
            $this->shipAll($order);
            $position = (int) TransactionLine::query()->where('transaction_id', $order->id)->where('listing_id', $free->id)->value('position');
            $stock = static fn (): int => (int) InventoryStock::query()->where('listing_id', $free->id)->sum('quantity');
            $before = $stock();

            $this->assertNull(app(OrderReturns::class)->record(Transaction::query()->findOrFail($order->id), $this->item($position, 1, restock: true), 0, self::ACTOR));
            $this->assertSame($before + 1, $stock());
            $this->assertSame([0, 0], [OrderReturn::query()->where('transaction_id', $order->id)->sole()->amount, Refund::query()->where('transaction_id', $order->id)->count()]);
            $this->assertSame(0, LedgerJournal::query()->where('transaction_id', $order->id)->whereIn('type', ['refund', 'adjustment'])->count());
        });
    }

    public function test_after_an_amount_only_refund_an_item_goes_back_to_stock_for_what_is_left_by_default(): void
    {
        $this->inside(function (): void {
            // ニット 4,400 ＋ 送料 550 ＝ 4,950。お詫びに金額だけ 1,000 を返した後、ニットが返ってきて在庫に戻す
            $sale = $this->simpleOrder();
            $position = (int) TransactionLine::query()->where('transaction_id', $sale->id)->where('line_type', 'item')->value('position');
            $stock = fn (): int => (int) InventoryStock::query()->where('listing_id', $this->knit)->sum('quantity');
            app(OrderReturns::class)->record($sale, $this->amount(1000), null, self::ACTOR);
            $before = $stock();

            // 品の額は 4,400 のままでも、返す額の既定は返せる残りの 3,950（前は「まだ返していない額を越えて」で断られた）
            $plan = app(OrderReturns::class)->preview($sale, $this->item($position, 1, restock: true));
            $this->assertSame([3950, 4400, 3950], [$plan->total, $plan->goodsFull, $plan->goodsLimit]);
            $refund = app(OrderReturns::class)->record($sale, $this->item($position, 1, restock: true), $plan->total, self::ACTOR);

            $this->assertSame([3950, 'succeeded'], [$refund?->amount, $refund?->status]);
            $this->assertSame([1000, 3950], array_column($this->stripe->refunds, 'amount'));
            $this->assertSame($before + 1, $stock());
            $this->assertSame([3950], RefundLine::query()->where('kind', 'item')->pluck('amount')->all());
            // 受け取った額をちょうど返し終え、台帳の口座はすべて 0 に戻る（税率ごとの調整も残りの額の税の差で）
            $this->assertSame([], array_filter(app(LedgerService::class)->balances($sale)));
            $this->assertSame(450, (int) collect(OrderReturn::query()->get())->sum(static fn (OrderReturn $r): int => array_sum(array_column($r->taxes, 'tax'))));
        });
    }

    public function test_the_shop_can_lower_the_goods_refund_down_to_nothing_but_not_above_what_is_left_or_below_zero(): void
    {
        $this->inside(function (): void {
            // ニット 3 点 13,200 ＋ 送料 550 ＝ 13,750。金額だけ 1,000 を返した後（返せる残り 12,750）
            $sale = $this->simpleOrder(quantity: 3);
            $position = (int) TransactionLine::query()->where('transaction_id', $sale->id)->where('line_type', 'item')->value('position');
            $stock = fn (): int => (int) InventoryStock::query()->where('listing_id', $this->knit)->sum('quantity');
            $returns = app(OrderReturns::class);
            $returns->record($sale, $this->amount(1000), null, self::ACTOR);
            $before = $stock();

            // 上限（品の額 4,400 と残りの小さい方）を 1 円越える額と、負の額は断り、何も残さない
            $this->assertRefused(fn () => $returns->record($sale, $this->item($position, 1, restock: true, goods: 4401), null, self::ACTOR), sprintf(ReturnPlanner::GOODS_OVER, '¥4,400'));
            $this->assertRefused(fn () => $returns->preview($sale, $this->item($position, 1, restock: true, goods: -1)), ReturnPlanner::GOODS_NEGATIVE);
            $this->assertSame([1, $before], [OrderReturn::query()->count(), $stock()]);

            // 下げた額で返す（傷があったので一部だけ）。品の行は下げた額、在庫には戻る
            $lowered = $returns->record($sale, $this->item($position, 1, restock: true, goods: 3000), 3000, self::ACTOR);
            $this->assertSame([3000, 'succeeded'], [$lowered?->amount, $lowered?->status]);
            $this->assertSame([3000], RefundLine::query()->where('kind', 'item')->pluck('amount')->all());
            $this->assertSame($before + 1, $stock());

            // 0 円: 品だけ返して在庫に戻す。Stripe に頼まず、返金の記録も台帳の仕訳も作らない。返品の記録・在庫の戻し・イベントは残す
            $journals = LedgerJournal::query()->where('transaction_id', $sale->id)->count();
            $plan = $returns->preview($sale, $this->item($position, 1, restock: true, goods: 0));
            $this->assertSame([0, 4400, 4400], [$plan->total, $plan->goodsFull, $plan->goodsLimit]);
            $this->assertNull($returns->record($sale, $this->item($position, 1, restock: true, goods: 0), 0, self::ACTOR));
            $this->assertSame($before + 2, $stock());
            $this->assertSame([3, 1], [OrderReturn::query()->count(), OrderReturn::query()->where('amount', 0)->count()]);
            $this->assertSame([2, 2], [Refund::query()->where('transaction_id', $sale->id)->count(), count($this->stripe->refunds)]);
            $this->assertSame($journals, LedgerJournal::query()->where('transaction_id', $sale->id)->count());
            $this->assertSame([3, 2], [DomainEvent::query()->where('type', 'order.return_recorded')->count(), DomainEvent::query()->where('type', 'inventory.returned')->count()]);
            $recorded = DomainEvent::query()->where('type', 'order.return_recorded')->orderByDesc('sequence')->firstOrFail()->payload;
            $this->assertSame([0, 'item', 1, 0, true], [$recorded['amount'], $recorded['lines'][0]['kind'], $recorded['lines'][0]['quantity'], $recorded['lines'][0]['amount'], $recorded['lines'][0]['restock']]);
            // 返せる残りは 0 円の返品で減らない（12,750 − 3,000）
            $this->assertSame(9750, app(ReturnSettlement::class)->available(Transaction::query()->findOrFail($sale->id)));
        });
    }

    public function test_the_default_goods_refund_stops_at_what_is_left_of_each_tax_rate(): void
    {
        $this->inside(function (): void {
            // 2 つの税率の注文で、金額だけの返金で残りを 100 円にした（金額だけは率ごとの残りの割合で配る）
            [$sale] = $this->mixedOrder();
            $books = app(ReturnSettlement::class);
            app(OrderReturns::class)->record($sale, $this->amount((int) $sale->total_amount - 100), null, self::ACTOR);
            $left = $books->remainingByRate($sale);
            $this->assertSame(100, array_sum($left));
            $this->assertGreaterThan(0, $left[800]);
            $lines = TransactionLine::query()->where('transaction_id', $sale->id)->get();
            $cookie = $lines->first(fn (TransactionLine $l): bool => $l->listing_id === $this->cookie);
            $knit = $lines->first(fn (TransactionLine $l): bool => $l->listing_id === $this->knit);

            // クッキー缶（軽減 8%）を全部返す: 既定は 8% の残りまで（全体の残り 100 円ではない。率を越えると税の調整が書けない）
            $plan = app(OrderReturns::class)->preview($sale, $this->item($cookie->position, 3, restock: true));
            $this->assertSame($left[800], $plan->total);
            $this->assertGreaterThan($plan->total, $plan->goodsFull);
            $this->assertSame([800], array_column($plan->taxes, 'rate_bp'));
            app(OrderReturns::class)->record($sale, $this->item($cookie->position, 3, restock: true), $plan->total, self::ACTOR);

            // ニット（標準 10%）とオプション・送料: 残りの 10% の分。返し終えると台帳の口座はすべて 0 に戻る
            $rest = new ReturnRequest([$knit->position => ['quantity' => 2, 'restock' => true, 'addons' => true]], true, 0, ReturnReason::Returned, 'provider');
            $this->assertSame($left[1000], app(OrderReturns::class)->preview($sale, $rest)->total);
            app(OrderReturns::class)->record($sale, $rest, $left[1000], self::ACTOR);
            $this->assertSame([], array_filter(app(LedgerService::class)->balances($sale)));
            $this->assertSame((int) $sale->tax_amount, (int) collect(OrderReturn::query()->get())->sum(static fn (OrderReturn $r): int => array_sum(array_column($r->taxes, 'tax'))));
        });
    }

    public function test_a_lowered_goods_refund_is_spread_over_the_rates_and_the_lines_like_an_amount_only_refund(): void
    {
        $this->inside(function (): void {
            [$sale] = $this->mixedOrder();
            $lines = TransactionLine::query()->where('transaction_id', $sale->id)->get();
            $cookie = $lines->first(fn (TransactionLine $l): bool => $l->listing_id === $this->cookie);
            $knit = $lines->first(fn (TransactionLine $l): bool => $l->listing_id === $this->knit);
            $request = static fn (?int $goods): ReturnRequest => new ReturnRequest([
                $knit->position => ['quantity' => 1, 'restock' => false, 'addons' => true],
                $cookie->position => ['quantity' => 1, 'restock' => false, 'addons' => false],
            ], false, 0, ReturnReason::Defective, 'provider', $goods);

            // 品の額のまま（既定）の行の額と率ごとの額
            $full = app(OrderReturns::class)->preview($sale, $request(null));
            $byRate = array_column($full->taxes, 'gross', 'rate_bp');
            $this->assertSame([800, 1000], array_keys($byRate));
            $this->assertSame([$full->goodsFull, $full->goodsFull], [$full->total, $full->goodsLimit]);

            // 1,000 円に下げる: 率へは率ごとの額の割合で、率の中は行の額の割合で（金額だけの返金と同じ split）
            $plan = app(OrderReturns::class)->preview($sale, $request(1000));
            $parts = Allocation::byWeight(1000, $byRate);
            $this->assertSame(1000, $plan->total);
            $this->assertSame($parts, array_column($plan->taxes, 'gross', 'rate_bp'));
            $standard = array_values(array_filter($full->lines, static fn (array $l): bool => $l['rate_bp'] === 1000));
            $this->assertSame(array_values(Allocation::byWeight($parts[1000], array_column($standard, 'amount'))), array_column(array_values(array_filter($plan->lines, static fn (array $l): bool => $l['rate_bp'] === 1000)), 'amount'));

            // 記録すると、台帳の率ごとの調整も下げた額で
            $refund = app(OrderReturns::class)->record($sale, $request(1000), 1000, self::ACTOR);
            $this->assertSame([1000, 'succeeded'], [$refund?->amount, $refund?->status]);
            $adjusted = LedgerJournal::query()->where('transaction_id', $sale->id)->where('type', JournalType::Adjustment)->orderBy('reference')->get()
                ->mapWithKeys(static fn (LedgerJournal $j): array => [(string) $j->reference => (int) $j->postings()->where('account', 'customer')->value('amount')])->all();
            $this->assertSame(['return-rate-1000' => $parts[1000], 'return-rate-800' => $parts[800]], $adjusted);
        });
    }

    public function test_two_item_returns_at_once_after_an_amount_only_refund_never_go_past_what_is_left(): void
    {
        $this->inside(function (): void {
            // ニット 2 点 8,800 ＋ 送料 550 ＝ 9,350。金額だけ 1,000 を返した後、2 人が同時に 1 点ずつ「在庫に戻す」で返す
            $sale = $this->simpleOrder(quantity: 2);
            $position = (int) TransactionLine::query()->where('transaction_id', $sale->id)->where('line_type', 'item')->value('position');
            $stock = fn (): int => (int) InventoryStock::query()->where('listing_id', $this->knit)->sum('quantity');
            app(OrderReturns::class)->record($sale, $this->amount(1000), null, self::ACTOR);
            $before = $stock();
            $request = $this->item($position, 1, restock: true);
            // 2 人とも確かめの画面で 4,400 を見た
            $this->assertSame([4400, 4400], [app(OrderReturns::class)->preview($sale, $request)->total, app(OrderReturns::class)->preview($sale, $request)->total]);
            $second = null;

            // 1 人目の Stripe への返金の最中（台帳のロックの外）に、2 人目が同じ額で押す
            $this->stripe->before = function () use ($sale, $request, &$second): void {
                if ($second !== null) {
                    return;
                }

                try {
                    app(OrderReturns::class)->record(Transaction::query()->findOrFail($sale->id), $request, 4400, 'operator:second');
                    $second = 'refunded';
                } catch (TransactionException|PaymentUnavailableException $e) {
                    $second = $e->getMessage();
                }
            };
            app(OrderReturns::class)->record($sale, $request, 4400, self::ACTOR);
            $this->stripe->before = null;
            $this->assertSame(OrderReturns::BUSY, $second);

            // 1 人目が済んだ後に同じ額で押し直すと、既定が残りの 3,950 に変わったので断る（確かめ直してもらう）
            $this->assertRefused(fn () => app(OrderReturns::class)->record($sale, $request, 4400, 'operator:second'), OrderReturns::CHANGED);
            $plan = app(OrderReturns::class)->preview($sale, $request);
            $this->assertSame(3950, $plan->total);
            app(OrderReturns::class)->record($sale, $request, $plan->total, 'operator:second');

            // 返金の合計は受け取った額ちょうど、在庫は 2 点だけ戻る
            $this->assertSame(9350, (int) Refund::query()->where('transaction_id', $sale->id)->where('status', 'succeeded')->sum('amount'));
            $this->assertSame($before + 2, $stock());
            $this->assertSame([], array_filter(app(LedgerService::class)->balances($sale)));
        });
    }

    public function test_the_event_and_the_records_carry_no_personal_information(): void
    {
        $this->inside(function (): void {
            [$sale] = $this->mixedOrder();
            $position = (int) TransactionLine::query()->where('transaction_id', $sale->id)->where('listing_id', $this->knit)->value('position');
            app(OrderReturns::class)->record($sale, $this->item($position, 1, restock: true), null, self::ACTOR);

            $payloads = DomainEvent::query()->whereIn('type', ['order.return_recorded', 'payment.refund_requested', 'payment.refund_recorded', 'inventory.returned'])->get()
                ->map(static fn (DomainEvent $e): string => (string) json_encode($e->payload, JSON_UNESCAPED_UNICODE))->implode("\n");
            $this->assertNotSame('', $payloads);

            foreach (['山田', '花子', 'hanako', '渋谷', '09012345678', '1500001'] as $personal) {
                $this->assertStringNotContainsString($personal, $payloads);
            }

            $this->assertEqualsCanonicalizing(['return', 'reason', 'amount', 'channel', 'taxes', 'lines'], array_keys(DomainEvent::query()->where('type', 'order.return_recorded')->sole()->payload));
        });
    }

    /**
     * 会員（1,000 ポイント）が、ギフト包装つきのニット 2 点（標準 10%）とクッキー缶 3 点（軽減 8%）を、クーポン 1,000 円引き・ポイント 500 で買い、払って全部発送した
     *
     * @return array{0: Transaction, 1: CustomerAccount}
     */
    private function mixedOrder(): array
    {
        $account = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'test');
        CustomerAccount::query()->whereKey($account->id)->update(['member_since' => now()]);
        $member = CustomerAccount::query()->findOrFail($account->id);
        Auth::guard('customer')->setUser(new Member($member, 'ort'));
        app(PointService::class)->adjust($member, 1000, 'テスト', 'test');

        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $knit = Listing::query()->findOrFail($this->knit);
        $wrap = (string) ListingAddon::query()->where('listing_id', $this->knit)->value('id');
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->knit)->value('id'), 2, AddonSelection::resolve($knit, [$wrap => '1']));
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->cookie)->value('id'), 3);
        $carts->applyCoupon($cart, 'BACK');
        $sale = $this->place($cart, 500);
        $this->assertSame(1, TransactionLine::query()->where('transaction_id', $sale->id)->where('kind', 'addon')->count());
        $this->assertSame(1, TransactionLine::query()->where('transaction_id', $sale->id)->where('kind', 'shipping')->count());
        $this->pay($sale);
        $this->shipAll($sale);

        return [Transaction::query()->findOrFail($sale->id), $member];
    }

    /**
     * ゲストがニットを買い、払って（発送して）おく
     */
    private function simpleOrder(int $quantity = 1, bool $ship = true): Transaction
    {
        $carts = app(CartService::class);
        [$cart] = $carts->create();
        $knit = Listing::query()->findOrFail($this->knit);
        $wrap = (string) ListingAddon::query()->where('listing_id', $this->knit)->value('id');
        $carts->add($cart, (string) ListingVariant::query()->where('listing_id', $this->knit)->value('id'), $quantity, AddonSelection::resolve($knit, [$wrap => '0']));
        $sale = $this->place($cart, 0, 'jiro@example.com');
        $this->pay($sale);

        if ($ship) {
            $this->shipAll($sale);
        }

        return Transaction::query()->findOrFail($sale->id);
    }

    private function place(Cart $cart, int $points, string $email = 'hanako@example.com'): Transaction
    {
        $input = CheckoutInput::fromArray(['buyer' => ['email' => $email, 'name' => '山田 花子', 'phone' => '09012345678', 'postal' => '1500001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '1', 'line2' => null], 'recipient' => null,
            'points' => $points > 0 ? ['mode' => 'some', 'amount' => $points] : null]);
        $this->assertNotNull($input);
        $fingerprint = CheckoutSummary::fingerprintOf(app(CartService::class)->view($cart, $input->destination(), $email, $input->pointsRequested()));

        return app(PlaceOrder::class)->place($cart, $input, rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $fingerprint);
    }

    private function pay(Transaction $sale): void
    {
        app(PaymentService::class)->startCheckout($sale, 'x', 'y');
        $ref = PaymentAttempt::query()->where('transaction_id', $sale->id)->sole()->provider_ref;
        $this->assertSame('paid', app(PaymentNotices::class)->confirmPaid('stripe', 'evt_'.$sale->id, 'checkout.session.completed', $ref, $sale->id, (int) $sale->total_amount, 'jpy', 'pi_'.$sale->id));
    }

    private function shipAll(Transaction $sale): void
    {
        foreach (Fulfillment::query()->where('transaction_id', $sale->id)->get() as $fulfillment) {
            app(SaleOrderService::class)->ship(Transaction::query()->findOrFail($sale->id), $fulfillment->id, 'yamato', '1234', 'operator:test');
        }
    }

    private function everything(Transaction $sale): ReturnRequest
    {
        $items = [];

        foreach (TransactionLine::query()->where('transaction_id', $sale->id)->where('line_type', 'item')->get() as $line) {
            $items[$line->position] = ['quantity' => $line->quantity, 'restock' => false, 'addons' => true];
        }

        return new ReturnRequest($items, true, 0, ReturnReason::Returned, 'provider');
    }

    private function item(int $position, int $quantity, bool $restock = false, ?int $goods = null): ReturnRequest
    {
        return new ReturnRequest([$position => ['quantity' => $quantity, 'restock' => $restock, 'addons' => false]], false, 0, ReturnReason::Returned, 'provider', $goods);
    }

    private function amount(int $amount): ReturnRequest
    {
        return new ReturnRequest([], false, $amount, ReturnReason::Goodwill, 'provider');
    }

    private function tax(int $rate, int $gross): int
    {
        return Totals::ofAmounts([$rate => $gross], true, TaxRounding::Floor)->tax;
    }

    private function assertRefused(Closure $work, string $message): void
    {
        try {
            $work();
            $this->fail('断られなかった');
        } catch (TransactionException|PaymentUnavailableException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function expectRefusal(Closure $write, string $message): void
    {
        try {
            DB::transaction(static function () use ($write): void {
                $write();
            });
            $this->fail('DB が書き込みを断らなかった');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
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

    /**
     * 記録に残った「返金が確かになった」の知らせ（記録の順）
     *
     * @return list<RefundSettled>
     */
    private function settledNotices(string $transactionId): array
    {
        return array_values(DomainEvent::query()->where('type', RefundSettled::type())->where('aggregate_id', $transactionId)->orderBy('sequence')->get()
            ->map(static fn (DomainEvent $event): RefundSettled => RefundSettled::fromEvent($event))->all());
    }
}
