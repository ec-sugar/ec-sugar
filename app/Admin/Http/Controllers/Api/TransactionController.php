<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Presenters\TransactionPresenter;
use App\Admin\Http\Requests\ListTransactionsRequest;
use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\ShopHome;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Listing\Models\Seller;
use App\Core\Order\OrderCharges;
use App\Core\Order\Services\DirectPayments;
use App\Core\Order\Services\OrderFields;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Order\Services\ShippingDifferenceRefunds;
use App\Core\Party\Models\Party;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Payment\Services\PaymentRefunds;
use App\Core\Transaction\Enums\OrderStatus;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * テナントの中の取引の閲覧（EnterTenant の後。ADR 0014）。
 *
 * 一覧と詳細には個人情報（顧客の名前・連絡先・住所、配送先、追跡番号）を入れない。
 * 個人情報は personal() でだけ返し、見た事実を中央の監査ログに残す（「必要な所だけ」を、見る操作そのものを分けて守る）。
 * 経路の {transactionId} はモデルに結びつけない（SubstituteBindings は EnterTenant より前に動き、中央 DB を引く）。
 */
final class TransactionController
{
    /**
     * 物販の対応の状況（OrderStatus）を SQL で求める式。SaleOrderService::status と同じ決まりで、
     * 絞り込みと件数に使う（一覧に出す状況とそろうことを TransactionAdminTest が確かめる）。利用者の入力は入らない
     */
    public const string ORDER_STATUS_SQL = "CASE WHEN status = 'refunded' THEN 'refunded' WHEN status = 'cancelled' THEN 'cancelled'"
        ." WHEN phase = 'agreement' THEN 'awaiting_payment' WHEN phase = 'payment' AND total_amount = 0 THEN 'confirmed'"
        // 代引きで、まだ入金を確かめていない（ADR 0074。SaleOrderService::codUnpaid と同じ決まり）
        ." WHEN phase = 'payment' AND EXISTS (SELECT 1 FROM payment_attempts pa WHERE pa.transaction_id = transactions.id AND pa.kind = 'direct' AND pa.method = 'cod' AND pa.status = 'awaiting') THEN 'cod_pending'"
        // 予約の当日払いで、まだ入金を確かめていない（SaleOrderService::onSiteUnpaid と同じ決まり）
        ." WHEN phase = 'payment' AND EXISTS (SELECT 1 FROM payment_attempts pa WHERE pa.transaction_id = transactions.id AND pa.kind = 'direct' AND pa.method = 'on_site' AND pa.status = 'awaiting') THEN 'on_site_pending'"
        ." WHEN phase = 'payment' THEN 'paid' WHEN phase = 'fulfillment' THEN 'shipped' ELSE 'completed' END";

    public function __construct(
        private readonly SaleOrderService $orders,
        private readonly TransactionPresenter $presenter,
    ) {}

    public function index(ListTransactionsRequest $request): JsonResponse
    {
        [$column, $direction] = $request->sort();
        $sellerId = $request->validated('seller_id');
        $phase = $request->validated('phase');
        $q = trim((string) $request->validated('q', ''));
        $status = $request->validated('status');
        $orderStatus = $request->validated('order_status');
        $payment = $request->validated('payment');

        // 状態以外の絞り込み（状態ごとの件数にも効かせる）
        $filter = static function (Builder $query) use ($sellerId, $phase, $q): void {
            if (is_string($sellerId) && $sellerId !== '') {
                $query->where('seller_id', $sellerId);
            }

            if (is_string($phase) && $phase !== '') {
                $query->where('phase', $phase);
            }

            if ($q !== '') {
                // 明細の商品名の一部（LIKE の記号は文字として扱う）か、英数字だけなら注文番号（ID）の先頭
                $pattern = '%'.addcslashes($q, '\\%_').'%';
                $query->where(static function (Builder $w) use ($q, $pattern): void {
                    $w->whereExists(static fn ($lines) => $lines->from('transaction_lines')->whereColumn('transaction_lines.transaction_id', 'transactions.id')
                        ->whereRaw('transaction_lines.description ILIKE ?', [$pattern]));

                    if (preg_match('/\A[0-9A-Za-z]{1,26}\z/', $q) === 1) {
                        $w->orWhere('id', 'like', strtolower($q).'%');
                    }
                });
            }
        };

        $counts = Transaction::query()->tap($filter)->toBase()->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')
            ->map(static fn (mixed $n): int => (int) $n);
        // 物販の対応の状況ごとの件数（注文の一覧の「状態で絞る」に出す）。状況の絞り込みは効かせない
        $orderCounts = Transaction::query()->tap($filter)->where('kind', 'sale')->toBase()
            ->selectRaw(self::ORDER_STATUS_SQL.' AS order_status, count(*) AS n')->groupByRaw('1')->pluck('n', 'order_status')
            ->map(static fn (mixed $n): int => (int) $n);

        $page = Transaction::query()
            ->tap($filter)
            ->when(is_string($status) && $status !== '', static fn (Builder $w) => $w->where('status', $status))
            ->when(is_string($orderStatus) && $orderStatus !== '', static fn (Builder $w) => $w->where('kind', 'sale')->whereRaw(self::ORDER_STATUS_SQL.' = ?', [$orderStatus]))
            ->when(is_string($payment) && $payment !== '', static fn (Builder $w) => self::paymentFilter($w, (string) $payment))
            ->withCount('lines')
            // 返した額と、返品・記録した外の返金があるか（注文の一覧の「一部返金」「全額返金」の札。ADR 0048）
            ->selectRaw(ShopHome::REFUNDED_SQL.' AS refunded_amount')
            ->selectRaw("EXISTS (SELECT 1 FROM refunds r WHERE r.transaction_id = transactions.id AND r.status = 'succeeded' AND r.purpose IN ('return', 'external')) AS has_return")
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate($request->perPage(), ['id', 'kind', 'phase', 'status', 'seller_id', 'customer_id', 'currency', 'total_amount', 'agreed_at', 'completed_at', 'created_at']);

        $sellers = Seller::query()->whereIn('id', collect($page->items())->pluck('seller_id')->filter()->unique()->values())->pluck('name', 'id');

        $statusCounts = [];

        foreach (TransactionStatus::cases() as $case) {
            $statusCounts[$case->value] = $counts->get($case->value, 0);
        }

        $orderStatusCounts = [];

        foreach (OrderStatus::cases() as $case) {
            $orderStatusCounts[$case->value] = $orderCounts->get($case->value, 0);
        }

        return response()->json([
            'transactions' => collect($page->items())->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'kind' => $transaction->kind,
                'phase' => $transaction->phase->value,
                'status' => $transaction->status->value,
                // 物販は対応の状況（入金待ち・入金済み・発送済み…）。日本の EC の受注の一覧でよく使う言葉
                'order_status' => $transaction->kind === 'sale' ? $this->orders->status($transaction)->value : null,
                'seller' => $transaction->seller_id === null ? null : ['id' => $transaction->seller_id, 'name' => $sellers->get($transaction->seller_id)],
                // 顧客は「いるかどうか」だけ。名前は personal() で見る
                'has_customer' => $transaction->customer_id !== null,
                'currency' => $transaction->currency,
                'total_amount' => $transaction->total_amount,
                'lines_count' => (int) $transaction->getAttribute('lines_count'),
                // 返品・一部返金の札（取引の状態は変えずに、返した額で見せる。ADR 0048）
                'refund_state' => self::refundState($transaction),
                'agreed_at' => $transaction->agreed_at?->toIso8601String(),
                'completed_at' => $transaction->completed_at?->toIso8601String(),
                'created_at' => $transaction->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'counts' => ['all' => array_sum($statusCounts)] + $statusCounts,
            'order_counts' => ['all' => array_sum($orderStatusCounts)] + $orderStatusCounts,
            // 銀行振込の入金待ち・代引きで入金を確かめていない注文の数（ADR 0074。「お支払いで絞る」）
            'payment_counts' => array_combine(self::PAYMENT_FILTERS, array_map(
                static fn (string $name): int => self::paymentFilter(Transaction::query()->tap($filter), $name)->count(),
                self::PAYMENT_FILTERS,
            )),
        ]);
    }

    /** 「お支払いで絞る」の候補（ADR 0074）: 銀行振込の入金待ち・代引きで入金を確かめていない */
    public const array PAYMENT_FILTERS = ['bank_awaiting', 'cod_unpaid'];

    /**
     * 店が受け取る支払いの注文で絞る（ADR 0074）。開いている物販で、支払いの記録が入金待ちのもの
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public static function paymentFilter(Builder $query, string $filter): Builder
    {
        return $query->where('kind', 'sale')->where('status', TransactionStatus::Open)
            ->whereExists(static fn ($q) => $q->selectRaw('1')->from('payment_attempts')->whereColumn('payment_attempts.transaction_id', 'transactions.id')
                ->where('payment_attempts.kind', 'direct')->where('payment_attempts.status', 'awaiting')
                ->where('payment_attempts.method', $filter === 'bank_awaiting' ? 'bank_transfer' : 'cod'));
    }

    public function show(string $transactionId): JsonResponse
    {
        return response()->json(['transaction' => $this->presenter->present(Transaction::query()->findOrFail($transactionId))]);
    }

    /**
     * 受渡を発送済みにする（運送会社と追跡番号）。入金の前・発送済み・終わった注文は 409 で、理由は見せてよい文
     */
    public function ship(Request $request, string $transactionId): JsonResponse
    {
        $data = $request->validate([
            'fulfillment_id' => ['required', 'string', 'ulid'],
            'carrier' => ['present', 'nullable', 'string', 'in:'.implode(',', array_keys(SaleOrderService::CARRIERS))],
            'tracking_number' => ['present', 'nullable', 'string', 'regex:/\A[A-Za-z0-9-]{1,64}\z/'],
        ], ['tracking_number.regex' => 'お問い合わせ番号は英数字とハイフンで 64 文字までです。'], ['carrier' => '運送会社', 'tracking_number' => 'お問い合わせ番号']);

        return $this->operate($transactionId, fn (Transaction $t) => $this->orders->ship($t, strtolower($data['fulfillment_id']), $data['carrier'], $data['tracking_number'], self::actor($request)));
    }

    /**
     * サービス（役務）を提供した: サービスだけの注文の受渡を済みにして、注文を完了まで進める
     */
    public function deliverService(Request $request, string $transactionId): JsonResponse
    {
        return $this->operate($transactionId, fn (Transaction $t) => $this->orders->deliverService($t, self::actor($request)));
    }

    /**
     * 完了にする（お客さんに届いた）
     */
    public function complete(Request $request, string $transactionId): JsonResponse
    {
        return $this->operate($transactionId, fn (Transaction $t) => $this->orders->complete($t, self::actor($request)));
    }

    /**
     * 取り消す（発送の前だけ。払われていれば Stripe で全額を返金）。お金が動くので直前の再確認（経路の RequireRecentConfirmation）
     */
    public function cancel(Request $request, string $transactionId): JsonResponse
    {
        return $this->operate($transactionId, fn (Transaction $t) => $this->orders->cancel($t, self::actor($request)));
    }

    /**
     * 返金が要る支払いを Stripe で返金する。お金が動くので直前の再確認（経路の RequireRecentConfirmation）
     */
    public function refundStray(Request $request, string $transactionId, string $strayId, PaymentRefunds $payments): JsonResponse
    {
        $payments->refundStray(StrayPayment::query()->where('transaction_id', $transactionId)->whereKey(strtolower($strayId))->firstOrFail(), self::actor($request));

        return $this->show($transactionId);
    }

    /**
     * 止めた返金を片付ける。取消の返金は注文の取消と一緒に、ほかは返金だけ
     */
    public function resolveRefund(Request $request, string $transactionId, string $refundId, PaymentRefunds $payments, SaleOrderService $orders): JsonResponse
    {
        $input = $request->validate([
            'outcome' => ['required', 'string', 'in:recorded,dismissed'],
            'amount' => ['nullable', 'required_if:outcome,recorded', 'integer', 'min:1', 'max:100000000'],
        ], [], ['outcome' => '確定の方法', 'amount' => '返金の額']);
        $transaction = Transaction::query()->findOrFail($transactionId);
        $refund = Refund::query()->where('transaction_id', $transaction->id)->whereKey(strtolower($refundId))->firstOrFail();
        /** @var 'recorded'|'dismissed' $outcome */
        $outcome = $input['outcome'];
        $amount = isset($input['amount']) ? (int) $input['amount'] : null;

        if ($refund->purpose === 'cancel') {
            $orders->resolveCancelRefund($transaction, $refund, $outcome, $amount, self::actor($request));
        } else {
            $payments->resolve($refund, $outcome, $amount, self::actor($request));
        }

        return $this->show($transaction->id);
    }

    /**
     * 銀行振込・代引きの入金を確かめた（ADR 0074）。受け取った額を入れ、注文の額と違えば「額の違いを確かめた」が要る（違いは店が外で調整）。
     * お金の段階と直前の再確認（経路）
     */
    public function confirmDirectPayment(Request $request, string $transactionId, DirectPayments $direct): JsonResponse
    {
        $input = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'accept_difference' => ['sometimes', 'boolean'],
        ], [], ['amount' => '受け取った額', 'accept_difference' => '差額を確認した']);

        return $this->operate($transactionId, fn (Transaction $t) => $direct->confirm($t, (int) $input['amount'], (bool) ($input['accept_difference'] ?? false), self::actor($request)));
    }

    /**
     * 代引きの受取拒否で取り消す（ADR 0074）。在庫に戻すかを選ぶ。お金の段階と直前の再確認（経路）
     */
    public function refuseCod(Request $request, string $transactionId, DirectPayments $direct): JsonResponse
    {
        $input = $request->validate(['restock' => ['required', 'boolean']], [], ['restock' => '在庫に戻す']);

        return $this->operate($transactionId, fn (Transaction $t) => $direct->refuseCod($t, (bool) $input['restock'], self::actor($request)));
    }

    /**
     * 銀行振込・代引きで受け取った注文の返金を記録する（店の人が自分で振り込んだ額。ADR 0074）。お金の段階と直前の再確認（経路）
     */
    public function recordDirectRefund(Request $request, string $transactionId, DirectPayments $direct): JsonResponse
    {
        $input = $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:100000000']], [], ['amount' => '返金した額']);

        return $this->operate($transactionId, fn (Transaction $t) => $direct->recordRefund($t, (int) $input['amount'], self::actor($request)));
    }

    /**
     * 断られた送料の差額の返金を頼み直す（ADR 0064 の作って決めたこと）。お金が動くので直前の再確認（経路の RequireRecentConfirmation）。
     * 断られた記録は片付け済みにし、新しい返金を Stripe へ頼む。また断られたら、新しい記録が失敗として残る（店のやることに出る）
     */
    public function retryShippingRefund(Request $request, string $transactionId, ShippingDifferenceRefunds $refunds): JsonResponse
    {
        $transaction = Transaction::query()->findOrFail($transactionId);
        $refunds->retry($transaction->id, self::actor($request));

        return $this->show($transaction->id);
    }

    /**
     * @param  callable(Transaction): mixed  $operation
     */
    private function operate(string $transactionId, callable $operation): JsonResponse
    {
        $transaction = Transaction::query()->findOrFail($transactionId);
        // 決まりに反するとき（TransactionException など）は、見せてよい文の JSON にまとめて返る（bootstrap/app.php）
        $operation($transaction);

        return $this->show($transaction->id);
    }

    /**
     * @return 'partial'|'full'|null
     */
    private static function refundState(Transaction $transaction): ?string
    {
        if (! (bool) $transaction->getAttribute('has_return') || $transaction->status->isClosed()) {
            return null;
        }

        return (int) $transaction->getAttribute('refunded_amount') >= (int) $transaction->total_amount ? 'full' : 'partial';
    }

    private static function actor(Request $request): string
    {
        return TenantActor::of($request);
    }

    /**
     * 取引の個人情報（顧客と配送先）。見た事実を中央の監査ログに残す。
     */
    public function personal(Request $request, string $transactionId, AuditLog $audit, OrderFields $orderFields, OrderCharges $charges): JsonResponse
    {
        $transaction = Transaction::query()->findOrFail($transactionId);
        $customer = $transaction->customer_id === null ? null : Party::query()->find($transaction->customer_id);
        $tenant = Tenant::current();
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);

        $audit->record('operator.personal_data_viewed', $operator, [
            'subject' => 'transaction',
            'transaction_id' => $transaction->id,
            'customer_id' => $transaction->customer_id,
        ], (string) $tenant->getTenantKey(), $request->ip());

        return response()->json(['personal' => [
            'customer' => $customer === null ? null : [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'address' => $customer->address,
            ],
            'fulfillments' => $transaction->fulfillments()->orderBy('created_at')->get()->map(static fn (Fulfillment $fulfillment): array => [
                'id' => $fulfillment->id,
                'address' => $fulfillment->address,
                'tracking_number' => $fulfillment->tracking_number,
            ])->values(),
            // オプションの入れた文字（名入れなど。ADR 0036）。人の名前になりうるので、この入口（記録が残る）でだけ見せる
            'addon_texts' => $transaction->lines()->where('kind', 'addon')->whereNotNull('addon_text')->orderBy('position')->get()
                ->map(static fn (TransactionLine $line): array => [
                    'position' => $line->position,
                    'parent_position' => $line->parent_position,
                    'description' => $line->description,
                    'text' => $line->addon_text,
                ])->values(),
            // 注文の項目の自由な文（ご要望など。ADR 0066）。個人情報を含みうるので、この入口（記録が残る）でだけ見せる。選ぶ・チェックは注文の詳細に出る
            'order_fields' => array_values(array_map(static fn (array $f): array => ['name' => $f['name'], 'value' => $f['value']],
                array_filter($orderFields->forTransaction($transaction->id), static fn (array $f): bool => $f['type']->freeText()))),
            // App の料金の備考（ラッピング・のしと名入れ。ADR 0078）。名入れは人の名前になりうるので、この入口（記録が残る）でだけ見せる
            'charge_notes' => $charges->notes($transaction->id, private: true),
        ]]);
    }
}
