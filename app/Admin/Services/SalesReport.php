<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Core\Points\Services\PointService;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 売上の集計（ADR 0060）。店の文脈の中だけで、読むだけ。個人情報は返さない（お客さんは人数だけ）。
 *
 * 数え方（ADR 0060 の 1）:
 *   - お金は台帳（ledger）から、お金が動いた時刻で数える。入金は支払いの仕訳（payment）の時刻、返金は返金の仕訳（refund）の時刻。
 *     売上 = 入金 − 返金（税込みの円の整数）。締めた期間の数は、後の取消・返金で変わらない（返金は返した日の期間で引く）
 *   - 注文の数は、払いが確かになった時刻（その注文の最初の支払いの仕訳）の期間で数える。後で取り消した注文も数え、「うち取消・返金」を添える。
 *     0 円の注文（クーポンで 0 円になったもの・0 円の品）は支払いが無いので、受け付けた時刻（created_at）で数え、取り消したものは数えない
 *   - 数える取引は物販（sale）・円・お客さんが払う向き。テストの注文（Stripe のテストのモードで払われた）は、入金も返金も件数も数えない（件数だけを別に）
 *   - クーポンの値引き（送料無料の分も含む。使った記録の額）と送料（送料の行）は、数えた注文の分を足す
 *   - ギフトカード（ADR 0076）: お金はギフトカードを売った時に入金として数え、ギフトカードで払った分（参照 gift_card: の受け取りと、その返金）は数えない
 *     （同じお金を 2 回数えない。お金が動いた時で数える決まりのまま）。注文の数には数える（払いが確かになった時刻）
 *   - 使ったポイント（ADR 0068。ポイントの行の額）も、数えた注文の分を足す。戻した注文（取消・期限切れ・全額の返金）の分は除き、贈り物の送料の差額と一部の返金（ADR 0048）で戻した分は引く。
 *     まだ使われていないポイントの合計（店の負債）は期間によらず今の数
 *   - 平均の注文額 = 入金 ÷ 注文の数（1 円未満は切り捨て）。注文が無ければ null
 *   - 商品別は、数えた注文のうち今も取り消されていないものの品の行（数と、オプションと値引きの前の行の額）。返品（返金が済んだ返金の行。ADR 0048）の数と、その割合の額を引く
 *   - 新しいお客さん・また来たお客さんは、お客さんの台帳（まとめた記録は、まとめた先の 1 人）ごとに、期間より前に数える注文があったかで分ける
 *
 * SQL へ渡す時刻は UTC にしてから（ADR 0042 の作って決めたこと）
 */
final class SalesReport
{
    /** 画面に出す商品の上位の数 */
    public const int TOP = 10;

    /** CSV に書く商品の行の上限 */
    public const int CSV_PRODUCTS_MAX = 10000;

    // 数える取引（t）: 物販・円・お客さんが払う向き・テストのモードで払われていない
    private const string SALE = "t.kind = 'sale' AND t.currency = 'JPY' AND t.payer = 'customer'";

    // テストのモードは支払いの試みの test_mode 列で見分ける（決済の形ごとの番号の書き方を見ない。ADR 0096）
    private const string NOT_TEST = "NOT EXISTS (SELECT 1 FROM payment_attempts a WHERE a.transaction_id = t.id AND a.status = 'paid' AND a.test_mode)";

    // 受け付けた 0 円の注文（取り消していない。入金待ちより先）
    private const string FREE = "t.total_amount = 0 AND t.status IN ('open', 'completed') AND t.phase NOT IN ('quote', 'agreement', 'cancel', 'refund')";

    /**
     * 期間の集計（画面の数・日ごとの棒・商品の上位・お客さん）
     *
     * @return array{
     *     period: array{preset: string, from: string, to: string, days: int},
     *     currency: string,
     *     totals: array{sales: int, gross: int, refunds: int, orders: int, cancelled_orders: int, average: int|null, discounts: int, shipping: int, points_used: int, test_orders: int},
     *     points: array{outstanding: int, holders: int},
     *     daily: list<array{day: string, sales: int, gross: int, refunds: int, orders: int}>,
     *     products: array{by_amount: list<array{listing_id: string|null, title: string, quantity: int, amount: int}>, by_quantity: list<array{listing_id: string|null, title: string, quantity: int, amount: int}>},
     *     customers: array{new: int, returning: int, guests: int}
     * }
     */
    public function summary(SalesPeriod $period): array
    {
        self::assertTenant();

        $daily = $this->daily($period);
        $gross = array_sum(array_column($daily, 'gross'));
        $refunds = array_sum(array_column($daily, 'refunds'));
        $orders = $this->orderTotals($period);

        return [
            'period' => $period->toArray(),
            'currency' => 'JPY',
            'totals' => [
                'sales' => $gross - $refunds,
                'gross' => $gross,
                'refunds' => $refunds,
                'orders' => $orders['orders'],
                'cancelled_orders' => $orders['cancelled'],
                'average' => $orders['orders'] > 0 ? intdiv($gross, $orders['orders']) : null,
                'discounts' => $orders['discounts'],
                'shipping' => $orders['shipping'],
                'points_used' => $orders['points'],
                'test_orders' => $this->testOrders($period),
            ],
            // まだ使われていないポイント（1 ポイント = 1 円の店の負債。今の数）
            'points' => ['outstanding' => app(PointService::class)->outstanding(), 'holders' => app(PointService::class)->holders()],
            'daily' => $daily,
            'products' => [
                'by_amount' => $this->products($period, 'amount', self::TOP),
                'by_quantity' => $this->products($period, 'quantity', self::TOP),
            ],
            'customers' => $this->customers($period),
        ];
    }

    /**
     * 日ごと（期間の全部の日。何も無い日も 0 で）。sales は入金 − 返金
     *
     * @return list<array{day: string, sales: int, gross: int, refunds: int, orders: int}>
     */
    public function daily(SalesPeriod $period): array
    {
        self::assertTenant();
        $zone = SalesPeriod::zone();
        [$from, $until] = self::bounds($period);

        // 支払いの仕訳はお客さんの口座が +、返金は − （LedgerService の符号）。打ち消しの仕訳も同じ種別なので足せば打ち消し合う
        $money = DB::select(
            "SELECT to_char(j.occurred_at AT TIME ZONE ?, 'YYYY-MM-DD') AS day, j.type, SUM(p.amount)::bigint AS amount
               FROM ledger_journals j
               JOIN ledger_postings p ON p.transaction_id = j.transaction_id AND p.journal_id = j.id AND p.account = 'customer'
               JOIN transactions t ON t.id = j.transaction_id
              WHERE j.type IN ('payment', 'refund') AND j.occurred_at >= ? AND j.occurred_at < ?
                AND (j.reference IS NULL OR j.reference NOT LIKE 'gift\\_card:%')
                AND ".self::SALE.' AND '.self::NOT_TEST.'
              GROUP BY 1, 2',
            [$zone, $from, $until],
        );

        [$counted, $bindings] = $this->counted($period);
        $orders = DB::select("WITH counted AS ({$counted}) SELECT to_char(at AT TIME ZONE ?, 'YYYY-MM-DD') AS day, count(*) AS n FROM counted GROUP BY 1", [...$bindings, $zone]);

        $rows = [];

        foreach ($period->days() as $day) {
            $rows[$day] = ['day' => $day, 'sales' => 0, 'gross' => 0, 'refunds' => 0, 'orders' => 0];
        }

        foreach ($money as $row) {
            if (! isset($rows[$row->day])) {
                continue;
            }

            if ($row->type === 'payment') {
                $rows[$row->day]['gross'] += (int) $row->amount;
            } else {
                $rows[$row->day]['refunds'] += -(int) $row->amount;
            }
        }

        foreach ($orders as $row) {
            if (isset($rows[$row->day])) {
                $rows[$row->day]['orders'] = (int) $row->n;
            }
        }

        foreach ($rows as $day => $row) {
            $rows[$day]['sales'] = $row['gross'] - $row['refunds'];
        }

        return array_values($rows);
    }

    /**
     * 商品別（数えた注文のうち、今も取り消されていないものの品の行）。$limit が null なら全部（CSV。上限つき）
     *
     * @param  'amount'|'quantity'  $by
     * @return list<array{listing_id: string|null, title: string, quantity: int, amount: int}>
     */
    public function products(SalesPeriod $period, string $by = 'amount', ?int $limit = null): array
    {
        self::assertTenant();
        [$counted, $bindings] = $this->counted($period);
        $order = $by === 'quantity' ? 'quantity DESC, amount DESC' : 'amount DESC, quantity DESC';

        $rows = DB::select(
            "WITH counted AS ({$counted})
             SELECT l.listing_id, COALESCE(MAX(li.title), MIN(l.description)) AS title,
                    SUM(l.quantity - COALESCE(rq.returned, 0))::bigint AS quantity,
                    SUM(l.amount - (l.amount * COALESCE(rq.returned, 0)) / l.quantity)::bigint AS amount
               FROM counted c
               JOIN transaction_lines l ON l.transaction_id = c.id AND l.line_type = 'item'
               LEFT JOIN (SELECT rl.transaction_id, rl.line_position, SUM(rl.quantity) AS returned FROM refund_lines rl
                           WHERE rl.kind = 'item' AND EXISTS (SELECT 1 FROM refunds r WHERE r.return_id = rl.return_id AND r.status = 'succeeded')
                           GROUP BY rl.transaction_id, rl.line_position) rq ON rq.transaction_id = l.transaction_id AND rq.line_position = l.position
               LEFT JOIN listings li ON li.id = l.listing_id
              WHERE c.status NOT IN ('cancelled', 'refunded')
              GROUP BY l.listing_id, CASE WHEN l.listing_id IS NULL THEN l.description END
              ORDER BY {$order}, title ASC
              LIMIT ?",
            [...$bindings, $limit ?? self::CSV_PRODUCTS_MAX],
        );

        return array_map(static fn (object $row): array => [
            'listing_id' => $row->listing_id === null ? null : (string) $row->listing_id,
            'title' => (string) $row->title,
            'quantity' => (int) $row->quantity,
            'amount' => (int) $row->amount,
        ], $rows);
    }

    /**
     * 注文の数・うち取消・クーポンの値引き・送料・使ったポイント
     *
     * @return array{orders: int, cancelled: int, discounts: int, shipping: int, points: int}
     */
    private function orderTotals(SalesPeriod $period): array
    {
        [$counted, $bindings] = $this->counted($period);

        $row = DB::selectOne(
            "WITH counted AS ({$counted})
             SELECT count(*) AS orders,
                    count(*) FILTER (WHERE status IN ('cancelled', 'refunded')) AS cancelled,
                    COALESCE((SELECT SUM(r.amount) FROM coupon_redemptions r JOIN counted c ON c.id = r.transaction_id WHERE r.status <> 'released'), 0)::bigint AS discounts,
                    COALESCE((SELECT SUM(l.amount) FROM transaction_lines l JOIN counted c ON c.id = l.transaction_id WHERE l.line_type = 'adjustment' AND l.kind = 'shipping'), 0)::bigint AS shipping,
                    COALESCE((SELECT -SUM(l.amount) FROM transaction_lines l JOIN counted c ON c.id = l.transaction_id WHERE l.line_type = 'adjustment' AND l.kind = 'points'
                        AND NOT EXISTS (SELECT 1 FROM point_entries e WHERE e.transaction_id = l.transaction_id AND e.kind = 'release' AND e.cause IS NULL)), 0)::bigint
                    - COALESCE((SELECT SUM(e.points) FROM point_entries e JOIN counted c ON c.id = e.transaction_id WHERE e.kind = 'release' AND e.cause IN ('shipping', 'partial')
                        AND NOT EXISTS (SELECT 1 FROM point_entries r WHERE r.transaction_id = e.transaction_id AND r.kind = 'release' AND r.cause IS NULL)), 0)::bigint AS points
               FROM counted",
            $bindings,
        );

        return [
            'orders' => (int) ($row->orders ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
            'discounts' => (int) ($row->discounts ?? 0),
            'shipping' => (int) ($row->shipping ?? 0),
            'points' => (int) ($row->points ?? 0),
        ];
    }

    /**
     * 新しいお客さん（期間より前に数える注文が無い）・また来たお客さん（ある）の人数と、台帳につながっていない注文の数
     *
     * @return array{new: int, returning: int, guests: int}
     */
    private function customers(SalesPeriod $period): array
    {
        [$counted, $bindings] = $this->counted($period);
        [$from] = self::bounds($period);

        // まとめた記録は、まとめた先の 1 人（まとめは 1 段だけ。CustomerMerges）。前の注文は、その人の台帳の全部の記録から探す
        $earlier = 'EXISTS (SELECT 1 FROM transactions t
                     WHERE t.customer_account_id IN (SELECT a2.id FROM customer_accounts a2 WHERE a2.id = r.root OR a2.merged_into_id = r.root)
                       AND '.self::SALE.' AND '.self::NOT_TEST."
                       AND (EXISTS (SELECT 1 FROM ledger_journals e WHERE e.transaction_id = t.id AND e.type = 'payment' AND e.occurred_at < ?)
                            OR (".self::FREE.' AND t.created_at < ?)))';

        $row = DB::selectOne(
            "WITH counted AS ({$counted}),
                  roots AS (SELECT DISTINCT COALESCE(a.merged_into_id, a.id) AS root FROM counted c JOIN customer_accounts a ON a.id = c.customer_account_id)
             SELECT (SELECT count(*) FROM roots r WHERE NOT {$earlier}) AS new,
                    (SELECT count(*) FROM roots r WHERE {$earlier}) AS returning,
                    (SELECT count(*) FROM counted WHERE customer_account_id IS NULL) AS guests",
            [...$bindings, $from, $from, $from, $from],
        );

        return ['new' => (int) ($row->new ?? 0), 'returning' => (int) ($row->returning ?? 0), 'guests' => (int) ($row->guests ?? 0)];
    }

    /**
     * テストのモードで払われた注文の数（期間に払われたもの。数には入れず、件数だけを知らせる）
     */
    private function testOrders(SalesPeriod $period): int
    {
        [$from, $until] = self::bounds($period);

        return (int) (DB::selectOne(
            "SELECT count(DISTINCT t.id) AS n FROM transactions t
               JOIN payment_attempts a ON a.transaction_id = t.id AND a.status = 'paid' AND a.test_mode
              WHERE ".self::SALE.' AND a.paid_at >= ? AND a.paid_at < ?',
            [$from, $until],
        )->n ?? 0);
    }

    /**
     * 期間に数える注文（払いが確かになった注文と、受け付けた 0 円の注文）の SQL と、その値。列は id・status・customer_account_id・at（数えた時刻）
     *
     * @return array{0: string, 1: list<string>}
     */
    private function counted(SalesPeriod $period): array
    {
        [$from, $until] = self::bounds($period);

        $sql = "SELECT t.id, t.status, t.customer_account_id, f.at
                  FROM (SELECT j.transaction_id, MIN(j.occurred_at) AS at FROM ledger_journals j
                         WHERE j.type = 'payment' AND j.reverses_journal_id IS NULL AND j.occurred_at >= ? AND j.occurred_at < ?
                         GROUP BY j.transaction_id) f
                  JOIN transactions t ON t.id = f.transaction_id
                 WHERE ".self::SALE.' AND '.self::NOT_TEST."
                   AND NOT EXISTS (SELECT 1 FROM ledger_journals e WHERE e.transaction_id = t.id AND e.type = 'payment' AND e.occurred_at < ?)
                UNION ALL
                SELECT t.id, t.status, t.customer_account_id, t.created_at AS at
                  FROM transactions t
                 WHERE ".self::SALE.' AND '.self::FREE.' AND t.created_at >= ? AND t.created_at < ?';

        return [$sql, [$from, $until, $from, $from, $until]];
    }

    /**
     * 期間の始まりと終わり（UTC の文字。終わりは含まない）
     *
     * @return array{0: string, 1: string}
     */
    private static function bounds(SalesPeriod $period): array
    {
        return [$period->from->utc()->format('Y-m-d H:i:sP'), $period->until->utc()->format('Y-m-d H:i:sP')];
    }

    private static function assertTenant(): void
    {
        if (! tenant() instanceof Tenant) {
            throw new LogicException('売上の集計は店の文脈の中でだけ読む');
        }
    }
}
