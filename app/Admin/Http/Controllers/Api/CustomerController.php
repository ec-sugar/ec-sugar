<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\ShopHome;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerAddress;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerMerges;
use App\Core\Plugin\MemberBenefits;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 管理画面の「お客さん」（ADR 0044 の 4）。
 *
 * - 一覧と 1 人の画面は、件数と金額だけ（注文の数・合計・最後の注文の日・会員か）。閲覧の段階から
 * - 名前・メール・届け先（個人情報）は personal だけが返し、見た事実を監査ログに残す。運用の段階から
 * - 同じ人かもしれない記録（名前と電話が同じでメールが違う）、まとめる・分けるは運用の段階から。自動ではまとめない
 * - 数えるのは払われた注文だけ（ホームの売上と同じ決まり。ShopHome::counted、テストの払いは外す）。まとめた記録は親に足して数える
 */
final class CustomerController
{
    private const int PER_PAGE = 50;

    private const int ORDERS = 100;

    private const int SIMILAR = 20;

    public function __construct(private readonly CustomerMerges $merges) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'member' => ['sometimes', 'nullable', 'in:0,1'],
            'sort' => ['sometimes', 'nullable', 'in:-last_order_at,-orders,-sales,-created_at'],
        ]);
        $sort = match ($data['sort'] ?? '-last_order_at') {
            '-orders' => 'orders',
            '-sales' => 'sales',
            '-created_at' => 'customer_accounts.created_at',
            default => 'last_order_at',
        };

        $query = CustomerAccount::query()->whereNull('merged_into_id')
            ->leftJoinSub(self::stats(), 'stats', 'stats.account_id', '=', 'customer_accounts.id')
            ->select(['customer_accounts.id', 'customer_accounts.member_since', 'customer_accounts.disabled_at', 'customer_accounts.created_at'])
            ->selectRaw('coalesce(stats.orders, 0) AS orders, coalesce(stats.sales, 0) AS sales, stats.last_order_at, coalesce(stats.merged, 0) AS merged');

        if (($data['member'] ?? null) !== null) {
            $data['member'] === '1' ? $query->whereNotNull('member_since') : $query->whereNull('member_since');
        }

        $page = $query->orderByRaw("{$sort} DESC NULLS LAST")->orderByDesc('customer_accounts.id')->paginate(self::PER_PAGE);

        return response()->json([
            'customers' => collect($page->items())->map(static fn (CustomerAccount $a): array => self::summary($a))->values(),
            'counts' => [
                'all' => CustomerAccount::query()->whereNull('merged_into_id')->count(),
                'members' => CustomerAccount::query()->whereNull('merged_into_id')->whereNotNull('member_since')->count(),
            ],
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(string $customerId): JsonResponse
    {
        $account = $this->find($customerId);
        $family = CustomerMerges::family($account);
        $stats = ShopHome::counted()->whereNotExists(ShopHome::paidInTestMode(...))->whereIn('customer_account_id', $family)->toBase()
            ->selectRaw('count(*) AS orders, coalesce(sum(total_amount), 0) AS sales, max(created_at) AS last_order_at')->first();
        $merged = CustomerAccount::query()->where('merged_into_id', $account->id)->orderBy('merged_at')->get();

        return response()->json(['customer' => [
            ...self::summary($account),
            'orders' => (int) ($stats->orders ?? 0),
            'sales' => (int) ($stats->sales ?? 0),
            'last_order_at' => self::time($stats->last_order_at ?? null),
            'merged_count' => $merged->count(),
            'merged_into_id' => $account->merged_into_id,
            // 会員の特典の App の一言（App「会員ランク」のランクの名前。ADR 0079。使っていない店・ランクの無い人は null）
            'badge' => app(MemberBenefits::class)->badgeFor($account->id),
            // まとめた記録（ID と、まとめた時と、注文の数だけ。個人情報は personal）
            'merged' => $merged
                ->map(static fn (CustomerAccount $child): array => [
                    'id' => $child->id,
                    'merged_at' => $child->merged_at?->toIso8601String(),
                    // 上の数と同じく払われた注文だけ
                    'orders' => ShopHome::counted()->whereNotExists(ShopHome::paidInTestMode(...))->where('customer_account_id', $child->id)->count(),
                ])->values(),
            // 注文（まとめた記録の分も。払われていない・取り消した注文も並べる。金額と状態だけ）
            'transactions' => Transaction::query()->whereIn('customer_account_id', $family)->where('kind', 'sale')
                ->select(['id', 'customer_account_id', 'currency', 'total_amount', 'created_at'])
                ->selectRaw(TransactionController::ORDER_STATUS_SQL.' AS order_status')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(self::ORDERS)->get()
                ->map(static fn (Transaction $t): array => [
                    'id' => $t->id,
                    'from_merged' => $t->customer_account_id !== $account->id,
                    'order_status' => (string) $t->getAttribute('order_status'),
                    'currency' => $t->currency,
                    'total_amount' => $t->total_amount,
                    'created_at' => $t->created_at?->toIso8601String(),
                ])->values(),
        ]]);
    }

    /**
     * 名前・メール・届け先（個人情報）。運用の段階から、見た事実を監査ログに残す
     */
    public function personal(Request $request, string $customerId, AuditLog $audit): JsonResponse
    {
        $account = $this->find($customerId);
        $family = CustomerMerges::family($account);
        $latest = Transaction::query()->whereIn('customer_account_id', $family)->where('kind', 'sale')->whereNotNull('customer_id')
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $party = $latest?->customer_id === null ? null : Party::query()->find($latest->customer_id);
        $this->audit($request, $audit, 'operator.personal_data_viewed', ['subject' => 'customer', 'customer_account_id' => $account->id]);

        return response()->json(['personal' => [
            'email' => $account->email,
            'merged_emails' => CustomerAccount::query()->where('merged_into_id', $account->id)->orderBy('merged_at')->get()
                ->map(static fn (CustomerAccount $child): array => ['id' => $child->id, 'email' => $child->email])->values(),
            // 最後の注文の時の名前・電話・住所（台帳には写していない。ADR 0044 の 1）
            'latest_order' => $party === null ? null : ['transaction_id' => $latest->id, 'name' => $party->name, 'phone' => $party->phone, 'address' => $party->address],
            // 会員として入れた届け先
            'addresses' => CustomerAddress::query()->where('customer_account_id', $account->id)->orderByDesc('is_default')->orderBy('created_at')->get()
                ->map(static fn (CustomerAddress $a): array => ['id' => $a->id, 'default' => $a->is_default, 'address' => $a->address])->values(),
        ]]);
    }

    /**
     * 同じ人かもしれない記録: 名前と電話（どちらもブラインドインデックス）が同じで、メールが違う記録。返すのは件数と金額だけ。運用の段階から
     */
    public function similar(string $customerId): JsonResponse
    {
        $account = $this->find($customerId);
        $family = CustomerMerges::family($account);
        $pairs = DB::table('transactions')->join('parties', 'parties.id', '=', 'transactions.customer_id')
            ->whereIn('transactions.customer_account_id', $family)->whereNotNull('parties.name_index')->whereNotNull('parties.phone_index')
            ->distinct()->get(['parties.name_index', 'parties.phone_index']);

        if ($pairs->isEmpty()) {
            return response()->json(['similar' => []]);
        }

        $ids = DB::table('transactions')->join('parties', 'parties.id', '=', 'transactions.customer_id')
            ->join('customer_accounts', 'customer_accounts.id', '=', 'transactions.customer_account_id')
            ->whereNotIn('transactions.customer_account_id', $family)
            ->where(static function (QueryBuilder $q) use ($pairs): void {
                foreach ($pairs as $pair) {
                    $q->orWhere(static fn (QueryBuilder $w) => $w->where('parties.name_index', $pair->name_index)->where('parties.phone_index', $pair->phone_index));
                }
            })
            ->selectRaw('DISTINCT coalesce(customer_accounts.merged_into_id, customer_accounts.id) AS id')->limit(self::SIMILAR)->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)->reject(static fn (string $id): bool => in_array($id, $family, true))->values()->all();

        $rows = CustomerAccount::query()->whereKey($ids)->whereNull('merged_into_id')
            ->leftJoinSub(self::stats(), 'stats', 'stats.account_id', '=', 'customer_accounts.id')
            ->select(['customer_accounts.id', 'customer_accounts.member_since', 'customer_accounts.disabled_at', 'customer_accounts.created_at'])
            ->selectRaw('coalesce(stats.orders, 0) AS orders, coalesce(stats.sales, 0) AS sales, stats.last_order_at, coalesce(stats.merged, 0) AS merged')
            ->orderByRaw('last_order_at DESC NULLS LAST')->get();

        return response()->json(['similar' => $rows->map(static fn (CustomerAccount $a): array => self::summary($a))->values()]);
    }

    public function merge(Request $request, string $customerId, AuditLog $audit): JsonResponse
    {
        $mergedId = $request->validate(['merged_id' => ['required', 'string', 'size:26']])['merged_id'];
        $account = $this->find($customerId);
        $this->merges->merge($account, strtolower($mergedId), TenantActor::of($request));
        $this->audit($request, $audit, 'operator.customers_merged', ['customer_account_id' => $account->id, 'merged_id' => strtolower($mergedId)]);

        return response()->json(null, 204);
    }

    public function unmerge(Request $request, string $customerId, AuditLog $audit): JsonResponse
    {
        $mergedId = $request->validate(['merged_id' => ['required', 'string', 'size:26']])['merged_id'];
        $account = $this->find($customerId);
        $this->merges->unmerge($account, strtolower($mergedId), TenantActor::of($request));
        $this->audit($request, $audit, 'operator.customers_unmerged', ['customer_account_id' => $account->id, 'merged_id' => strtolower($mergedId)]);

        return response()->json(null, 204);
    }

    /**
     * 台帳ごとの数（まとめた記録は親に足す）: 払われた注文の数・合計・最後の注文の時、まとめた記録の数
     */
    private static function stats(): QueryBuilder
    {
        $paid = ShopHome::counted()->whereNotExists(ShopHome::paidInTestMode(...))->whereNotNull('customer_account_id')->toBase()
            ->join('customer_accounts AS owner', 'owner.id', '=', 'transactions.customer_account_id')
            ->selectRaw('coalesce(owner.merged_into_id, owner.id) AS account_id, count(*) AS orders, sum(transactions.total_amount) AS sales, max(transactions.created_at) AS last_order_at')
            ->groupByRaw('1');
        $merged = DB::table('customer_accounts')->whereNotNull('merged_into_id')->selectRaw('merged_into_id AS account_id, count(*) AS merged')->groupBy('merged_into_id');

        return DB::query()->fromSub($paid, 'paid')->joinSub($merged, 'merged', 'merged.account_id', '=', 'paid.account_id', 'full')
            ->selectRaw('coalesce(paid.account_id, merged.account_id) AS account_id, paid.orders, paid.sales, paid.last_order_at, merged.merged');
    }

    /**
     * @return array{id: string, member: bool, member_since: string|null, disabled: bool, created_at: string|null, orders: int, sales: int, last_order_at: string|null, merged_count: int}
     */
    private static function summary(CustomerAccount $account): array
    {
        return [
            'id' => $account->id,
            'member' => $account->member_since !== null,
            'member_since' => $account->member_since?->toIso8601String(),
            'disabled' => $account->disabled_at !== null,
            'created_at' => $account->created_at->toIso8601String(),
            'orders' => (int) $account->getAttribute('orders'),
            'sales' => (int) $account->getAttribute('sales'),
            'last_order_at' => self::time($account->getAttribute('last_order_at')),
            'merged_count' => (int) $account->getAttribute('merged'),
        ];
    }

    private static function time(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(Request $request, AuditLog $audit, string $type, array $context): void
    {
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);
        $audit->record($type, $operator, $context, (string) Tenant::current()->getTenantKey(), $request->ip());
    }

    private function find(string $customerId): CustomerAccount
    {
        return CustomerAccount::query()->findOrFail(strtolower($customerId));
    }
}
