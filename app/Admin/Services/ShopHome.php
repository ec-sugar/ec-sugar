<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Agent\AgentProposals;
use App\Core\Event\Models\DomainEvent;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Enums\FulfillmentStatus;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\ListingKinds;
use App\Core\Listing\Models\Listing;
use App\Core\Order\Preorders;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Order\Services\ShippingDifferenceRefunds;
use App\Core\Payment\Models\Refund;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * 管理画面のホームの数・やること・最近の注文。店の文脈の中だけで、読むだけ。個人情報は返さない。
 *
 * 数の決まり（金額は円の整数のまま足す。浮動小数を使わない）:
 *   - 数えるのは物販（sale）の注文で、支払いが済んだもの（入金済み・受付済み〔0 円〕・発送済み・完了）。入金待ちは数えない
 *   - 取消（cancelled）と返金（refunded）になった注文は数えない（取消は全額を返すので、注文ごと外せば売上から引いたのと同じ）
 *   - 返品・一部返金（ADR 0048）・記録した外の返金・送料の差額の返金は、済んだ分をその注文の額から引く（REFUNDED_SQL。注文は数に残す）
 *   - テストの注文（Stripe のテストのモードで払われた注文）は数えず、件数だけを別に返す
 *   - 日と月の区切りは日本の時刻（config('app.display_timezone')）。注文の日は注文を受けた時刻（created_at）
 *   - 平均注文単価は今月の売上 ÷ 今月の注文の数（1 円未満は切り捨て）。注文が無ければ null
 */
final class ShopHome
{
    /** 最近の注文の数 */
    public const int RECENT = 5;

    /**
     * 注文ごとの返した額（済んだ返品・一部返金・記録した外の返金・送料の差額の返金。ADR 0048）。売上はこれを引いた額（売上の集計〔ADR 0060〕が返金の仕訳を引くのと合わせる）。
     * 取消の返金は取消になった注文ごと数えないので入れない。返金が要る支払いは注文の代金の外なので入れない。transactions の行の中で使う式（利用者の入力は入らない）
     */
    public const string REFUNDED_SQL = "COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.transaction_id = transactions.id AND r.status = 'succeeded' AND r.purpose IN ('return', 'external', 'shipping')), 0)";

    public function __construct(
        private readonly SaleOrderService $orders,
        private readonly Preorders $preorders,
    ) {}

    /**
     * @return array{currency: string, today: array{orders: int, sales: int}, month: array{orders: int, sales: int, average: int|null}, test_orders: int}
     */
    public function stats(?CarbonImmutable $now = null): array
    {
        self::assertTenant();
        $now = ($now ?? CarbonImmutable::now())->setTimezone((string) config('app.display_timezone', 'Asia/Tokyo'));
        $today = $now->startOfDay();
        $month = $now->startOfMonth();
        $next = $month->addMonth();

        $sum = static function (CarbonImmutable $from, CarbonImmutable $to): array {
            $row = self::counted()->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())
                ->whereNotExists(self::paidInTestMode(...))
                ->toBase()->selectRaw('count(*) AS n, coalesce(sum(total_amount - '.self::REFUNDED_SQL.'), 0) AS amount')->first();

            return ['orders' => (int) ($row->n ?? 0), 'sales' => (int) ($row->amount ?? 0)];
        };

        $day = $sum($today, $today->addDay());
        $monthly = $sum($month, $next);

        return [
            'currency' => 'JPY',
            'today' => $day,
            'month' => $monthly + ['average' => $monthly['orders'] > 0 ? intdiv($monthly['sales'], $monthly['orders']) : null],
            'test_orders' => self::counted()->where('created_at', '>=', $month->utc())->where('created_at', '<', $next->utc())
                ->whereExists(self::paidInTestMode(...))->count(),
        ];
    }

    /** 「期限の近い入金待ち」に数える、銀行振込の払う期限までの日数（ADR 0074） */
    public const int BANK_DUE_SOON_DAYS = 2;

    /**
     * やること（発送を待つ注文・在庫切れの商品の数・期限の近い銀行振込の入金待ち・入金の確認を待つ代引き・売った注文の返金で止めたギフトカード・
     * 送料の差額で返しきれなかった注文〔30 日〕）。返金の確かめは概要の refund_issues と stray_payments
     * 予約販売（ADR 0073）: 発売日の前で発送できない予約の注文は「発送を待つ注文」に数えない（発売日から App のやることと一緒に数える）。
     * 発売日の前の予約の商品は在庫で売っていないので「在庫切れ」に数えない
     *
     * @return array{to_ship: int, to_ship_cod: int, to_ship_free: int, out_of_stock: int, bank_awaiting: int, bank_due_soon: int, cod_to_confirm: int, gift_cards_stopped: int, shipping_unreturned: int}
     */
    public function todo(?CarbonImmutable $now = null): array
    {
        self::assertTenant();
        $now = ($now ?? CarbonImmutable::now())->utc();
        // 店が受け取る支払いの入金待ち（ADR 0074）
        $direct = static fn (string $method, bool $dueSoon = false): Builder => Transaction::query()->where('kind', 'sale')->where('status', TransactionStatus::Open)
            ->whereExists(static fn ($q) => $q->selectRaw('1')->from('payment_attempts')->whereColumn('payment_attempts.transaction_id', 'transactions.id')
                ->where('payment_attempts.kind', 'direct')->where('payment_attempts.method', $method)->where('payment_attempts.status', 'awaiting')
                ->when($dueSoon, static fn ($w) => $w->where('payment_attempts.pay_by', '<=', $now->addDays(self::BANK_DUE_SOON_DAYS))));
        $waiting = static fn (): Builder => Transaction::query()->where('kind', 'sale')->where('status', TransactionStatus::Open)->where('phase', Phase::Payment)
            ->whereNotIn('id', Fulfillment::query()->where('status', FulfillmentStatus::Pending)->where('ships_on', '>', PreorderTerms::today())->select('transaction_id'));
        $inPerson = static fn () => Fulfillment::query()->select('transaction_id')->where('method', FulfillmentMethod::InPerson);
        // 在庫の数を使わない種類（予約の品など）は数えない
        $outOfStock = Listing::query()->where('status', ListingStatus::Active)->whereNotIn('kind', app(ListingKinds::class)->stocklessNames())
            ->whereRaw('COALESCE((SELECT SUM(s.quantity - s.reserved) FROM inventory_stocks s JOIN listing_variants v ON v.id = s.variant_id AND v.deleted_at IS NULL WHERE s.listing_id = listings.id), 0) <= 0')
            ->pluck('id')->all();

        return [
            // 入金済み（1 円以上）と受付済み（0 円）。注文の一覧の対応の状況と同じ分け方（OrderStatus）
            // 来店で受け渡す注文（予約。受渡が役務だけ）は発送しないので数えない（App「予約」の画面の今日の予約で受け付ける）
            'to_ship' => $waiting()->where('total_amount', '>', 0)->whereNotIn('id', $direct('cod')->select('id'))->whereNotIn('id', $inPerson())->count(),
            // 代引きで、払う前に発送を待つ注文（ADR 0074。対応の状況の「代引き・発送待ち」）
            'to_ship_cod' => $waiting()->whereIn('id', $direct('cod')->select('id'))->count(),
            'to_ship_free' => $waiting()->where('total_amount', 0)->count(),
            // 公開中なのに、今の種類の在庫（数 − 押さえている数）の合計が 0 以下の商品。消えた種類の在庫は数えない（ADR 0036）
            // 発売日の前の予約の商品（ADR 0073）は在庫で売っていないので数えない
            'out_of_stock' => count($outOfStock) - count($this->preorders->terms($outOfStock)),
            // 銀行振込の入金待ち（期限に関わらず）と、そのうち払う期限まで 2 日を切った（過ぎた）もの。振込が届いていれば「入金を確認した」を押す
            'bank_awaiting' => $direct('bank_transfer')->where('phase', Phase::Agreement)->count(),
            'bank_due_soon' => $direct('bank_transfer', dueSoon: true)->where('phase', Phase::Agreement)->count(),
            // 発送した代引きで、入金を確かめていない（運送会社から代金が届いたら「代引きの入金を確認した」を押す）
            'cod_to_confirm' => $direct('cod')->where('phase', Phase::Fulfillment)->count(),
            // ギフトカード（ADR 0076）: 売った注文が返金されて自動で止めた、残高のあるカード（店の人が残高を 0 にするか再開すると消える）
            'gift_cards_stopped' => app(GiftCardService::class)->stoppedByRefund(),
            // 贈り物の送料の差額を、現金・ポイント・ギフトカードで返しきれなかった注文（直近 30 日。店の人が直接返す）
            'shipping_unreturned' => DomainEvent::query()->where('type', ShippingDifferenceRefunds::REST_RETURNED)
                ->where('occurred_at', '>=', CarbonImmutable::now()->utc()->subDays(30))->whereRaw("(payload->>'unreturned')::bigint > 0")->count(),
            // AI エージェントの承認を待つ案（ADR 0101。期限の前のもの）
            'agent_proposals' => AgentProposals::pendingCount(),
        ];
    }

    /**
     * 最近の注文（物販の新しい順）。お客さんの名前などは入れない（注文の一覧と同じ）
     *
     * @return list<array{id: string, order_status: string, currency: string, total_amount: int, lines_count: int, test: bool, created_at: string|null}>
     */
    public function recent(): array
    {
        self::assertTenant();

        return Transaction::query()->where('kind', 'sale')
            // select は前に置く（withCount の数の列を消さない）
            ->select(['id', 'kind', 'phase', 'status', 'currency', 'total_amount', 'created_at'])
            ->selectRaw('EXISTS (SELECT 1 FROM payment_attempts a WHERE a.transaction_id = transactions.id AND a.status = ? AND a.test_mode) AS test_mode', ['paid'])
            ->withCount('lines')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::RECENT)->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'order_status' => $this->orders->status($transaction)->value,
                'currency' => $transaction->currency,
                'total_amount' => $transaction->total_amount,
                'lines_count' => (int) $transaction->getAttribute('lines_count'),
                'test' => (bool) $transaction->getAttribute('test_mode'),
                'created_at' => $transaction->getAttribute('created_at')?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * 数える注文: 物販で、支払いが済んだ（入金待ちより先の）もの。取消と返金は外す。運営全体の数（ShopDigests）も同じ決まりで数える
     *
     * @return Builder<Transaction>
     */
    public static function counted(): Builder
    {
        return Transaction::query()->where('kind', 'sale')->where('currency', 'JPY')
            ->whereIn('status', [TransactionStatus::Open, TransactionStatus::Completed])
            ->whereNotIn('phase', [Phase::Quote, Phase::Agreement, Phase::Cancel, Phase::Refund]);
    }

    /**
     * テストのモードで払われた注文。whereExists・whereNotExists に渡す。
     * 見分けるのは支払いの試みの test_mode 列（決済の口が作ったときのモード。ADR 0096）で、
     * 決済の形ごとの番号の書き方を見ない（ADR 0107 の段 3b で Stripe が外へ出ても正しいまま）
     */
    public static function paidInTestMode(QueryBuilder $attempts): void
    {
        $attempts->from('payment_attempts')->whereColumn('payment_attempts.transaction_id', 'transactions.id')
            ->where('payment_attempts.status', 'paid')->where('payment_attempts.test_mode', true);
    }

    /**
     * 要対応の返金（ADR 0027）: 取り消した注文の返金が後から失敗した（お金が戻っていない）・返品・一部返金の返金が断られた（ADR 0048）・贈り物の送料の差額の返金が断られた（ADR 0064）・成功の後に戻された・額が違って止めた・1 時間以上途中のまま。
     * 店のホームの知らせと、運営全体の困りごとの数（ShopDigests）が同じ決まりで拾う
     *
     * @return Builder<Refund>
     */
    public static function refundIssues(?CarbonImmutable $now = null): Builder
    {
        // SQL へは UTC で渡す（日本の時刻のまま渡すと時差の印の無い文字になり、DB が 9 時間先として読む。呼ぶ側に任せない）
        $since = ($now ?? CarbonImmutable::now())->utc()->subHour();

        return Refund::query()
            ->where(static fn ($query) => $query
                ->whereIn('status', ['reversed', 'held'])
                // 取り消した注文の返金が後から断られた。店の人が「返金をやり直す」で頼み直した（retried）ものは消える（ADR 0048 の 3）
                ->orWhere(static fn ($query) => $query->where('status', 'failed')->where('purpose', 'cancel')->whereNull('resolution')
                    ->whereIn('transaction_id', Transaction::query()->where('status', TransactionStatus::Cancelled)->select('id')))
                // 返品・一部返金の返金が断られた・額が違って「無かった」とした、まだ頼み直していない（ADR 0048）
                ->orWhere(static fn ($query) => $query->where('status', 'failed')->where('purpose', 'return')->where(static fn ($q) => $q->whereNull('resolution')->orWhere('resolution', 'dismissed'))
                    ->whereNotExists(static fn ($q) => $q->from('refunds AS live')->whereColumn('live.return_id', 'refunds.return_id')->where('live.status', '<>', 'failed')))
                // 贈り物の送料の差額の返金が断られ、まだ返せていない（ADR 0064 の 6。自動では頼み直さない）。
                // 店の人が頼み直した（retried）・ほかの差額の返金が途中か済んだ・注文を取り消した（取消の返金が残りを全部返す）ものは消える
                ->orWhere(static fn ($query) => $query->where('status', 'failed')->where('purpose', 'shipping')->whereNull('resolution')
                    ->whereNotIn('transaction_id', Refund::query()->where('purpose', 'shipping')->where('status', '<>', 'failed')->select('transaction_id'))
                    ->whereIn('transaction_id', Transaction::query()->whereIn('status', [TransactionStatus::Open, TransactionStatus::Completed])->select('id')))
                ->orWhere(static fn ($query) => $query->whereIn('status', Refund::ACTIVE)->where('created_at', '<', $since)));
    }

    private static function assertTenant(): void
    {
        if (! tenant() instanceof Tenant) {
            throw new LogicException('ホームの数は店の文脈の中でだけ読む');
        }
    }
}
