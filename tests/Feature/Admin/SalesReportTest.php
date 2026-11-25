<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\SalesPeriod;
use App\Admin\Services\SalesReport;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Ledger\Enums\PaymentRoute;
use App\Core\Ledger\Services\LedgerService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 売上の集計（ADR 0060）: お金は台帳から動いた日で（入金は払った日・返金は返した日）、注文は払いが確かになった日で数える。
 * 日の区切りは日本の時刻。取消・返金・クーポン・送料・0 円の注文・テストの注文・ほかの店・権限（お金の段階だけ）・期間の上限・CSV の式よけ・索引
 */
final class SalesReportTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_money_is_counted_on_the_day_it_moved_and_orders_on_the_day_they_were_paid_in_japanese_days(): void
    {
        $this->shops();
        $this->travelTo(self::at('2026-11-03 23:00'));
        $this->enrolledOperator();

        $report = $this->send('GET', '/api/tenants/sra/sales?preset=custom&from=2026-11-01&to=2026-11-03')->assertOk()->json();
        $this->assertSame(['preset' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-03', 'days' => 3], $report['period']);
        // 入金 = B 4,100 + C 1,500。返金 = C 1,500（11/2）+ H 2,500（10 月に払った注文を 11/3 に返した）。売上 = 入金 − 返金
        // 注文 = B（10/31 23:50 に受けて 11/1 0:10 に払った）・C（後で取消）・D（0 円）。平均 = 5,600 ÷ 3 = 1,866（切り捨て）
        $this->assertSame([
            'sales' => 1600, 'gross' => 5600, 'refunds' => 4000, 'orders' => 3, 'cancelled_orders' => 1, 'average' => 1866,
            'discounts' => 500, 'shipping' => 600, 'points_used' => 0, 'test_orders' => 1,
        ], $report['totals']);
        $this->assertSame([
            ['day' => '2026-11-01', 'sales' => 5600, 'gross' => 5600, 'refunds' => 0, 'orders' => 2],
            ['day' => '2026-11-02', 'sales' => -1500, 'gross' => 0, 'refunds' => 1500, 'orders' => 1],
            ['day' => '2026-11-03', 'sales' => -2500, 'gross' => 0, 'refunds' => 2500, 'orders' => 0],
        ], $report['daily']);
        // 商品: 取り消した C の品は入らない。0 円の品は数だけ
        $this->assertSame([['=SUM(1) マグ', 2, 4000], ['試供品', 1, 0]], array_map(static fn (array $p): array => [$p['title'], $p['quantity'], $p['amount']], $report['products']['by_amount']));
        // はなこは 10 月にも買った（また来た）、たろう・じろうは初めて
        $this->assertSame(['new' => 2, 'returning' => 1, 'guests' => 0], $report['customers']);
        // 名前・メールは返さない
        $this->assertStringNotContainsString('hanako', (string) json_encode($report));
        $this->assertStringNotContainsString('花子', (string) json_encode($report, JSON_UNESCAPED_UNICODE));

        // 先月（10 月）: A と H の入金。B は 11/1 に払ったので入らない。H の返金は 11 月なので 10 月の金額は減らない（注文は「うち取消・返金」に今の状態で数える）
        $october = $this->send('GET', '/api/tenants/sra/sales?preset=last_month')->assertOk()->json('totals');
        $this->assertSame([5500, 0, 2, 1], [$october['gross'], $october['refunds'], $october['orders'], $october['cancelled_orders']]);
        // 10 月の 2 人はどちらも初めて
        $this->assertSame(['new' => 2, 'returning' => 0, 'guests' => 0], $this->send('GET', '/api/tenants/sra/sales?preset=last_month')->json('customers'));

        // 今日（11/3）だけ・今月（11/1〜今日）
        $this->assertSame(['sales' => -2500, 'orders' => 0], array_intersect_key($this->send('GET', '/api/tenants/sra/sales?preset=today')->json('totals'), ['sales' => 0, 'orders' => 0]));
        $this->assertSame(1600, $this->send('GET', '/api/tenants/sra/sales?preset=month')->json('totals.sales'));

        // ほかの店の数は混ざらない
        $this->assertSame(['sales' => 99999, 'orders' => 1], array_intersect_key($this->send('GET', '/api/tenants/srb/sales?preset=month')->json('totals'), ['sales' => 0, 'orders' => 0]));
    }

    public function test_only_the_money_level_can_read_it_and_csv_neutralizes_formulas_and_is_audited(): void
    {
        $this->shops();
        $this->travelTo(self::at('2026-11-03 23:00'));
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);

        // 運用の段階では読めない（金額は店のお金）。与えられていない店は 404
        $access->grant($staff, 'sra', 'operate', 'test');
        $this->send('GET', '/api/tenants/sra/sales')->assertForbidden();
        $this->send('GET', '/api/tenants/sra/sales/csv?kind=daily')->assertForbidden();
        $this->send('GET', '/api/tenants/srb/sales')->assertNotFound();

        $access->grant($staff, 'sra', 'money', 'test');
        $this->send('GET', '/api/tenants/sra/sales')->assertOk();

        // 期間の上限・先の日・形の違う日付・知らない選び方は 422
        $this->send('GET', '/api/tenants/sra/sales?preset=custom&from=2025-01-01&to=2026-01-02')->assertUnprocessable();
        $this->send('GET', '/api/tenants/sra/sales?preset=custom&from=2026-11-01&to=2026-11-04')->assertUnprocessable();
        $this->send('GET', '/api/tenants/sra/sales?preset=custom&from=2026-02-30&to=2026-03-01')->assertUnprocessable();
        $this->send('GET', '/api/tenants/sra/sales?preset=custom&from=2026-11-03&to=2026-11-01')->assertUnprocessable();
        $this->send('GET', '/api/tenants/sra/sales?preset=year')->assertUnprocessable();
        // 366 日ちょうどは受ける
        $this->send('GET', '/api/tenants/sra/sales?preset=custom&from=2025-11-03&to=2026-11-03')->assertOk()->assertJsonPath('period.days', 366);

        $daily = $this->send('GET', '/api/tenants/sra/sales/csv?kind=daily&preset=custom&from=2026-11-01&to=2026-11-03')->assertOk();
        $this->assertSame('attachment; filename="sales-daily-sra-2026-11-01-2026-11-03.csv"', $daily->headers->get('Content-Disposition'));
        $lines = explode("\r\n", trim((string) $daily->getContent()));
        $this->assertSame("\xEF\xBB\xBF日付,注文の数,入金（円）,返金（円）,売上（円）", $lines[0]);
        $this->assertSame(['2026-11-01,2,5600,0,5600', '2026-11-02,1,0,1500,-1500', '2026-11-03,0,0,2500,-2500'], array_slice($lines, 1));

        // 商品名が式で始まっても、表計算ソフトで式にならない
        $products = (string) $this->send('GET', '/api/tenants/sra/sales/csv?kind=products&preset=custom&from=2026-11-01&to=2026-11-03')->assertOk()->getContent();
        $this->assertStringContainsString(",\"'=SUM(1) マグ\",2,4000\r\n", $products);

        $audits = AuditEvent::query()->where('type', 'operator.sales_exported')->where('operator_id', $staff->id)->orderBy('id')->get();
        $this->assertCount(2, $audits);
        // 残すのは何を・どの期間・何行かだけ（中身は残さない）
        $this->assertEqualsCanonicalizing(['kind' => 'products', 'from' => '2026-11-01', 'to' => '2026-11-03', 'rows' => 2], $audits[1]->data);
    }

    public function test_the_period_is_cut_by_japanese_days_and_the_journal_range_uses_the_index(): void
    {
        $now = self::at('2026-11-05 00:30');
        // 今週は月曜（11/2）から。先月は 10 月の全部
        $this->assertSame(['week', '2026-11-02', '2026-11-05'], [SalesPeriod::of('week', now: $now)->preset, SalesPeriod::of('week', now: $now)->toArray()['from'], SalesPeriod::of('week', now: $now)->lastDay()]);
        $this->assertSame(['2026-10-01', '2026-10-31', 31], array_values(array_slice(SalesPeriod::of('last_month', now: $now)->toArray(), 1)));
        // 日本の 0:30 は UTC では前の日。日本の日付で区切る
        $this->assertSame('2026-11-04T15:00:00+00:00', SalesPeriod::of('today', now: $now)->from->utc()->toIso8601String());
        $this->expectException(InvalidArgumentException::class);
        SalesPeriod::of('custom', '2026-11-01', null, $now);
    }

    public function test_the_journal_range_query_can_use_the_type_and_time_index(): void
    {
        $this->tenant('src');
        $plan = $this->inside('src', static function (): string {
            return DB::transaction(static function (): string {
                // 行が少ないと表をなめる方が速いので、索引が使えるかだけを見る
                DB::statement('SET LOCAL enable_seqscan = off');
                $rows = DB::select("EXPLAIN SELECT 1 FROM ledger_journals j WHERE j.type IN ('payment', 'refund') AND j.occurred_at >= now() - interval '1 day' AND j.occurred_at < now()");

                return implode("\n", array_map(static fn (object $row): string => (string) ($row->{'QUERY PLAN'} ?? ''), $rows));
            });
        });
        $this->assertStringContainsString('ledger_journals_type_occurred_at_index', $plan);

        // 店の文脈の外では読まない
        $this->expectException(\LogicException::class);
        app(SalesReport::class)->daily(SalesPeriod::of('today'));
    }

    /**
     * 2 つの店に、いろいろな注文を日本の時刻で置く（sra が数える店、srb はほかの店）
     */
    private function shops(): void
    {
        $this->tenant('sra');
        $this->tenant('srb');

        // A: 10/31 23:30 に払った ¥3,000（はなこ）
        $this->travelTo(self::at('2026-10-31 23:30'));
        $this->sale('sra', [['A の品', 3000, 1]], email: 'hanako@example.com', pay: true);

        // H: 10/20 に払った ¥2,500（さぶろう）を、11/3 に返金して取り消す
        $this->travelTo(self::at('2026-10-20 12:00'));
        $h = $this->sale('sra', [['H の品', 2500, 1]], email: 'saburo@example.com', pay: true);

        // B: 10/31 23:50 に受けて、11/1 0:10 に払った（日本の日付で 11 月。UTC では 10/31）。2 点・送料 ¥600・クーポン −¥500
        $this->travelTo(self::at('2026-10-31 23:50'));
        $b = $this->sale('sra', [['=SUM(1) マグ', 2000, 2]], email: 'hanako@example.com', shipping: 600, discount: 500);
        $this->travelTo(self::at('2026-11-01 00:10'));
        $this->pay('sra', $b);

        // C: 11/1 10:00 に払った ¥1,500（たろう）を、11/2 9:00 に返金して取り消す
        $this->travelTo(self::at('2026-11-01 10:00'));
        $c = $this->sale('sra', [['C の品', 1500, 1]], email: 'taro@example.com', pay: true);
        $this->travelTo(self::at('2026-11-02 09:00'));
        $this->refundAndCancel('sra', $c);

        // D: 0 円の注文（じろう）。E: テストのモードで払った。F: 入金待ち
        $this->travelTo(self::at('2026-11-02 12:00'));
        $this->sale('sra', [['試供品', 0, 1]], email: 'jiro@example.com', pay: true);
        $this->sale('sra', [['E の品', 9999, 1]], email: 'shiro@example.com', pay: true, ref: 'cs_test_sales1', test: true);
        $this->sale('sra', [['F の品', 7000, 1]], email: 'goro@example.com');
        // ほかの店
        $this->sale('srb', [['G の品', 99999, 1]], email: 'hanako@example.com', pay: true);

        $this->travelTo(self::at('2026-11-03 08:00'));
        $this->refundAndCancel('sra', $h, phases: [Phase::Dispute, Phase::Refund]);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int}>  $items  品の名前・値段・数
     */
    private function sale(string $tenant, array $items, string $email, bool $pay = false, ?string $ref = null, int $shipping = 0, int $discount = 0, bool $test = false): string
    {
        return $this->inside($tenant, function () use ($items, $email, $pay, $ref, $shipping, $discount, $test): string {
            $seller = Seller::query()->firstOrFail();
            $store = Location::query()->firstOrFail();
            $lines = [];

            foreach ($items as [$title, $amount, $quantity]) {
                $listing = Listing::create(['seller_id' => $seller->id, 'kind' => 'product', 'title' => $title, 'unit_amount' => $amount, 'status' => ListingStatus::Active]);
                app(StockService::class)->receive($listing, $store, 10);
                $lines[] = LineDraft::fromListing($listing, $quantity, 1000, $store);
            }

            if ($shipping > 0) {
                $lines[] = LineDraft::adjustment('shipping', '送料', $shipping, 1000);
            }

            if ($discount > 0) {
                $lines[] = LineDraft::adjustment('discount', 'クーポン（SALE）', -$discount, 1000);
            }

            $buyer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => $email]);
            $account = app(CustomerAccounts::class)->forEmail($email, 'storefront');
            $sale = app(TransactionService::class)->open('sale', $seller, $buyer, $lines, actor: 'system', customerAccount: $account);

            if ($discount > 0) {
                $coupon = strtolower((string) Str::ulid());
                DB::table('coupons')->insert(['id' => $coupon, 'code' => 'SALE', 'name' => 'テスト', 'kind' => 'fixed', 'value' => $discount, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('coupon_redemptions')->insert(['id' => strtolower((string) Str::ulid()), 'coupon_id' => $coupon, 'transaction_id' => $sale->id, 'amount' => $discount, 'status' => 'used', 'created_at' => now(), 'updated_at' => now()]);
            }

            if ($ref !== null) {
                PaymentAttempt::create(['transaction_id' => $sale->id, 'provider' => 'stripe', 'provider_ref' => $ref, 'checkout_url' => "https://checkout.stripe.com/c/pay/{$ref}", 'amount' => $sale->total_amount, 'currency' => 'JPY', 'status' => 'paid', 'expires_at' => now()->addHour(), 'paid_at' => now(), 'test_mode' => $test]);
            }

            if ($pay) {
                $this->payInside($sale);
            }

            return $sale->id;
        });
    }

    private function pay(string $tenant, string $id): void
    {
        $this->inside($tenant, fn () => $this->payInside(Transaction::query()->findOrFail($id)));
    }

    private function payInside(Transaction $sale): void
    {
        if ($sale->total_amount > 0) {
            app(LedgerService::class)->recordPayment($sale, $sale->total_amount, PaymentRoute::Platform, 'cs_live_'.$sale->id, 'webhook:stripe');
        }

        app(TransactionService::class)->transition($sale, Phase::Payment);
    }

    /**
     * @param  list<Phase>  $phases
     */
    private function refundAndCancel(string $tenant, string $id, array $phases = [Phase::Cancel]): void
    {
        $this->inside($tenant, function () use ($id, $phases): void {
            $sale = Transaction::query()->findOrFail($id);
            app(LedgerService::class)->recordRefund($sale, $sale->total_amount, PaymentRoute::Platform, 're_'.$sale->id, 'operator:test');

            foreach ($phases as $phase) {
                $sale = app(TransactionService::class)->transition($sale, $phase);
            }
        });
    }

    private static function at(string $jst): CarbonImmutable
    {
        return CarbonImmutable::parse($jst, 'Asia/Tokyo');
    }
}
