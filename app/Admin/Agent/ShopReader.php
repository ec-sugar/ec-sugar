<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use App\Admin\Agent\Models\AgentProposal;
use App\Admin\Http\Controllers\Api\TransactionController;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\SalesPeriod;
use App\Admin\Services\SalesReport;
use App\Admin\Services\ShopHome;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerAddress;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerMerges;
use App\Core\Shop\Services\AgentSettings;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Core\Transaction\TaxBreakdown;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * AI エージェントの道具の層（ADR 0100）。MCP の入口と、1.0 の後の管理画面のアシスタントが同じものを使う。
 *
 * - 読むだけ。書き込みは無い（テストが道具を呼ぶ間の店の DB の書き込みを見張る）。書く道具は ShopWriter
 * - 店の文脈の中で呼ぶ（AuthenticateAgent が入れる）。店は引数で選ばせない
 * - お客さんの個人情報（名前・メール・電話・住所・会員・備考・決済の ID）は、既定では返さない。注文はお客さんを番号でだけ。
 *   店の設定「AI エージェントにお客さんの情報を渡す」がオンで、鍵の持ち主がこの店で運用以上の段階のときだけ（管理画面の個人情報の表示と同じ段階。ADR 0024・0101）、
 *   お客さんの道具と注文の注文者の名前・メールを返し、見た事実を中央の監査ログに残す（備考と決済の ID はオンでも返さない）
 * - 店の人やお客さんが書いた文は untrusted() で包む（エージェントへの命令として読ませない）
 * - 段階の確かめは呼ぶ側（道具）が AgentContext で行う。売上（sales）はお金の段階
 */
final class ShopReader
{
    public const int MAX_PRODUCTS = 50;

    public const int MAX_ORDERS = 100;

    public const int MAX_CUSTOMERS = 20;

    public function __construct(
        private readonly SaleOrderService $orders,
        private readonly SalesReport $sales,
        private readonly AgentSettings $settings,
        private readonly AuditLog $audit,
    ) {
        // 店の文脈の外では作らない（どの店のデータか分からないまま読まない。見直しの低 9）
        if (! tenancy()->initialized) {
            throw new LogicException('AI エージェントの道具は店の文脈の中だけで使う');
        }
    }

    /**
     * 商品を名前で探す（下書き・取り下げも含む。状態で絞れる）
     *
     * @return list<array<string, mixed>>
     */
    public function searchProducts(?string $query, ?string $status, int $limit): array
    {
        $listings = Listing::query()
            ->when($query !== null && $query !== '', static fn ($q) => $q->where('title', 'ilike', '%'.addcslashes((string) $query, '%_\\').'%'))
            ->when($status !== null, static fn ($q) => $q->where('status', ListingStatus::from((string) $status)))
            ->orderByDesc('updated_at')->limit(max(1, min($limit, self::MAX_PRODUCTS)))->get();

        $stock = self::stockByListing($listings->pluck('id')->all());

        return $listings->map(fn (Listing $listing): array => [
            'id' => $listing->id,
            'title' => self::untrusted($listing->title),
            'status' => $listing->status->value,
            'kind' => $listing->kind,
            'price' => $listing->unit_amount,
            'tax_class' => $listing->tax_class->value,
            'available' => $stock[$listing->id] ?? 0,
            'variants' => ListingVariant::query()->where('listing_id', $listing->id)->count(),
        ])->values()->all();
    }

    /**
     * 1 つの商品（説明の文・種類ごとの値段と在庫）
     *
     * @return array<string, mixed>|null
     */
    public function product(string $id): ?array
    {
        $listing = Listing::query()->find($id);

        if (! $listing instanceof Listing) {
            return null;
        }

        $available = InventoryStock::query()->where('listing_id', $listing->id)
            ->selectRaw('variant_id, SUM(quantity - reserved) AS available')->groupBy('variant_id')->pluck('available', 'variant_id');

        return [
            'id' => $listing->id,
            'title' => self::untrusted($listing->title),
            'status' => $listing->status->value,
            'kind' => $listing->kind,
            'price' => $listing->unit_amount,
            'tax_class' => $listing->tax_class->value,
            'summary' => $listing->summary === null ? null : self::untrusted($listing->summary),
            'description' => $listing->description === null ? null : self::untrusted(mb_substr($listing->description, 0, 4000)),
            'variants' => ListingVariant::query()->where('listing_id', $listing->id)->orderBy('position')->get()->map(static fn (ListingVariant $v): array => [
                'id' => $v->id,
                'sku' => $v->sku === null ? null : self::untrusted($v->sku),
                'options' => array_map(static fn (string $x): array => self::untrusted($x), array_values(array_filter([$v->value_1, $v->value_2, $v->value_3], static fn (?string $x): bool => $x !== null && $x !== ''))),
                'price' => $v->unit_amount ?? $listing->unit_amount,
                'status' => $v->status->value,
                'available' => (int) ($available[$v->id] ?? 0),
            ])->all(),
        ];
    }

    /**
     * 在庫が少ない種類（売っている商品の、売れる数がしきい値以下のもの。少ない順）
     *
     * @return list<array<string, mixed>>
     */
    public function lowStock(int $threshold, int $limit): array
    {
        $rows = InventoryStock::query()
            ->join('listings', 'listings.id', '=', 'inventory_stocks.listing_id')
            ->where('listings.status', ListingStatus::Active->value)
            ->selectRaw('inventory_stocks.listing_id, inventory_stocks.variant_id, MAX(listings.title) AS title, SUM(inventory_stocks.quantity - inventory_stocks.reserved) AS available')
            ->groupBy('inventory_stocks.listing_id', 'inventory_stocks.variant_id')
            ->havingRaw('SUM(inventory_stocks.quantity - inventory_stocks.reserved) <= ?', [max(0, $threshold)])
            ->orderBy('available')->limit(max(1, min($limit, self::MAX_PRODUCTS)))->toBase()->get();

        // 集計の行（モデルではない）。数は文字で返ることがあるので整える
        return $rows->map(static fn (object $row): array => [
            'product_id' => (string) ($row->listing_id ?? ''),
            'variant_id' => (string) ($row->variant_id ?? ''),
            'title' => self::untrusted((string) ($row->title ?? '')),
            'available' => (int) ($row->available ?? 0),
        ])->values()->all();
    }

    /**
     * 注文の一覧（ショップの注文〔取引の種類 sale〕。新しい順）
     *
     * @return list<array<string, mixed>>
     */
    public function orders(?string $from, ?string $to, ?string $status, int $limit): array
    {
        $fromAt = self::day($from);
        $toAt = self::day($to)?->addDay();

        $limit = max(1, min($limit, self::MAX_ORDERS));
        // 状態は管理画面の注文の一覧と同じ SQL の式で求めて絞る（新しい分だけを読んで欠けない・1 件ずつ引かない。見直しの中 2）
        $transactions = Transaction::query()->where('kind', 'sale')
            ->when($fromAt !== null, static fn ($q) => $q->where('created_at', '>=', $fromAt))
            ->when($toAt !== null, static fn ($q) => $q->where('created_at', '<', $toAt))
            ->when($status !== null, static fn ($q) => $q->whereRaw('('.TransactionController::ORDER_STATUS_SQL.') = ?', [$status]))
            ->select('transactions.*')->selectRaw(TransactionController::ORDER_STATUS_SQL.' AS order_status')
            ->orderByDesc('created_at')->limit($limit)->get();

        $items = TransactionLine::query()->whereIn('transaction_id', $transactions->pluck('id')->all())
            ->where('line_type', LineType::Item)->whereNull('parent_position')
            ->selectRaw('transaction_id, SUM(quantity) AS items')->groupBy('transaction_id')->toBase()->get()
            ->mapWithKeys(static fn (object $row): array => [(string) ($row->transaction_id ?? '') => (int) ($row->items ?? 0)]);

        $buyers = $this->sharesPersonal() ? $this->buyers(array_values(array_unique(array_filter($transactions->pluck('customer_id')->all(), 'is_string'))), 'orders', $transactions->pluck('id')->all()) : [];

        return $transactions->map(static fn (Transaction $transaction): array => [
            'id' => $transaction->id,
            'placed_at' => $transaction->created_at?->setTimezone(SalesPeriod::zone())->toIso8601String(),
            'status' => (string) $transaction->getAttribute('order_status'),
            'total' => $transaction->total_amount,
            'currency' => $transaction->currency,
            'items' => $items[$transaction->id] ?? 0,
            'customer' => $transaction->customer_id,
        ] + (isset($buyers[(string) $transaction->customer_id]) ? ['buyer' => $buyers[(string) $transaction->customer_id]] : []))->values()->all();
    }

    /**
     * 1 つの注文（明細・合計・税の内訳・状態）。お客さんは番号だけ
     *
     * @return array<string, mixed>|null
     */
    public function order(string $id): ?array
    {
        $transaction = Transaction::query()->where('kind', 'sale')->find($id);

        if (! $transaction instanceof Transaction) {
            return null;
        }

        $buyers = $this->sharesPersonal() && $transaction->customer_id !== null ? $this->buyers([$transaction->customer_id], 'order', [$transaction->id]) : [];

        return [
            'id' => $transaction->id,
            'placed_at' => $transaction->created_at?->setTimezone(SalesPeriod::zone())->toIso8601String(),
            'status' => $this->orders->status($transaction)->value,
            'currency' => $transaction->currency,
            'subtotal' => $transaction->subtotal_amount,
            'tax' => $transaction->tax_amount,
            'total' => $transaction->total_amount,
            'customer' => $transaction->customer_id,
            'lines' => TransactionLine::query()->where('transaction_id', $transaction->id)->orderBy('position')->get()->map(static fn (TransactionLine $line): array => [
                'kind' => $line->kind ?? $line->line_type->value,
                'description' => self::untrusted($line->description),
                'quantity' => $line->quantity,
                'unit_amount' => $line->unit_amount,
                'amount' => $line->amount,
                'tax_rate_bp' => $line->tax_rate_bp,
                'product_id' => $line->listing_id,
            ])->all(),
            'taxes' => array_map(static fn (array $row): array => ['rate_bp' => $row['rateBp'], 'amount' => $row['amount'], 'tax' => $row['tax']], TaxBreakdown::ofTransaction($transaction)->rows),
        ] + (isset($buyers[(string) $transaction->customer_id]) ? ['buyer' => $buyers[(string) $transaction->customer_id], 'customer_account' => $transaction->customer_account_id] : []);
    }

    /**
     * お客さんの情報を渡してよいか（店の設定がオンで、鍵の持ち主がこの店で運用以上）。お客さんの道具は、これが偽なら一覧に出ない
     */
    public function sharesPersonal(): bool
    {
        return $this->settings->sharesCustomerData() && app()->bound(AgentContext::class) && app(AgentContext::class)->allows('operate');
    }

    /**
     * お客さんを名前かメールで探す（どちらも完全一致。暗号化した列なので部分一致はできない）。返すのは台帳の番号・メール・最後の注文の名前・注文の数
     *
     * @return list<array<string, mixed>>
     */
    public function searchCustomers(string $query, int $limit): array
    {
        $this->assertPersonal();
        $query = trim($query);
        $limit = max(1, min($limit, self::MAX_CUSTOMERS));

        if ($query === '') {
            throw new ToolInputException('Pass a name or an email address.');
        }

        if (str_contains($query, '@')) {
            $ids = CustomerAccount::query()->whereBlind('email', $query)->limit($limit)->get(['id', 'merged_into_id'])
                ->map(static fn (CustomerAccount $a): string => $a->merged_into_id ?? $a->id)->all();
        } else {
            $parties = Party::query()->whereBlind('name', $query)->limit(500)->pluck('id')->all();
            $ids = $parties === [] ? [] : Transaction::query()->where('kind', 'sale')->whereIn('customer_id', $parties)->whereNotNull('customer_account_id')
                ->join('customer_accounts', 'customer_accounts.id', '=', 'transactions.customer_account_id')
                ->selectRaw('DISTINCT coalesce(customer_accounts.merged_into_id, customer_accounts.id) AS id')->limit($limit)->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)->all();
        }

        $accounts = CustomerAccount::query()->whereIn('id', array_values(array_unique($ids)))->whereNull('merged_into_id')->orderByDesc('created_at')->limit($limit)->get();
        $this->viewed('agent_customers', ['customer_account_ids' => $accounts->pluck('id')->all()]);

        return $accounts->map(fn (CustomerAccount $account): array => $this->customerSummary($account))->values()->all();
    }

    /**
     * 1 人のお客さん（連絡先・届け先・注文の履歴。まとめた記録の分も）
     *
     * @return array<string, mixed>|null
     */
    public function customer(string $id): ?array
    {
        $this->assertPersonal();
        $account = CustomerAccount::query()->find($id);

        if (! $account instanceof CustomerAccount) {
            return null;
        }

        $account = $account->merged_into_id === null ? $account : (CustomerAccount::query()->find($account->merged_into_id) ?? $account);
        $family = CustomerMerges::family($account);
        $latest = Transaction::query()->whereIn('customer_account_id', $family)->where('kind', 'sale')->whereNotNull('customer_id')->orderByDesc('created_at')->orderByDesc('id')->first();
        $party = $latest?->customer_id === null ? null : Party::query()->find($latest->customer_id);
        $this->viewed('agent_customer', ['customer_account_id' => $account->id]);

        return $this->customerSummary($account) + [
            'phone' => $party?->phone === null ? null : self::untrusted($party->phone),
            'address' => $party?->address === null ? null : self::untrustedAddress($party->address),
            'saved_addresses' => CustomerAddress::query()->where('customer_account_id', $account->id)->orderByDesc('is_default')->orderBy('created_at')->limit(10)->get()
                ->map(static fn (CustomerAddress $a): array => ['default' => $a->is_default, 'address' => self::untrustedAddress($a->address)])->values()->all(),
            'orders' => Transaction::query()->whereIn('customer_account_id', $family)->where('kind', 'sale')
                ->select(['id', 'total_amount', 'currency', 'created_at'])->selectRaw(TransactionController::ORDER_STATUS_SQL.' AS order_status')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX_ORDERS)->get()
                ->map(static fn (Transaction $t): array => ['id' => $t->id, 'placed_at' => $t->created_at?->setTimezone(SalesPeriod::zone())->toIso8601String(), 'status' => (string) $t->getAttribute('order_status'), 'total' => $t->total_amount, 'currency' => $t->currency])->values()->all(),
        ];
    }

    /**
     * 自分（鍵の持ち主）が作った案の 1 件（ADR 0101）。ほかの人の案は見えない
     *
     * @return array<string, mixed>|null
     */
    public function proposal(string $id, string $operatorId): ?array
    {
        $proposal = AgentProposal::query()->whereKey($id)->where('operator_id', $operatorId)->first();

        if (! $proposal instanceof AgentProposal) {
            return null;
        }

        $expired = $proposal->status === AgentProposal::PENDING && $proposal->expires_at->isPast();

        return [
            'id' => $proposal->id,
            'tool' => $proposal->tool,
            'status' => $expired ? AgentProposal::EXPIRED : $proposal->status,
            'summary' => self::untrusted($proposal->summary),
            'created_at' => $proposal->created_at->setTimezone(SalesPeriod::zone())->toIso8601String(),
            'expires_at' => $proposal->expires_at->setTimezone(SalesPeriod::zone())->toIso8601String(),
            'decided_at' => $proposal->decided_at?->setTimezone(SalesPeriod::zone())->toIso8601String(),
            'result' => $proposal->result === null ? null : array_map(static fn (mixed $v): mixed => is_string($v) ? self::untrusted($v) : $v, $proposal->result),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerSummary(CustomerAccount $account): array
    {
        $family = CustomerMerges::family($account);
        $latest = Transaction::query()->whereIn('customer_account_id', $family)->where('kind', 'sale')->whereNotNull('customer_id')->orderByDesc('created_at')->orderByDesc('id')->first();
        $party = $latest?->customer_id === null ? null : Party::query()->find($latest->customer_id);
        $stats = ShopHome::counted()->whereNotExists(ShopHome::paidInTestMode(...))->whereIn('customer_account_id', $family)->toBase()
            ->selectRaw('count(*) AS orders, coalesce(sum(total_amount), 0) AS sales')->first();

        return [
            'id' => $account->id,
            'email' => self::untrusted($account->email),
            'name' => $party === null ? null : self::untrusted($party->name),
            'member' => $account->member_since !== null,
            'paid_orders' => (int) ($stats->orders ?? 0),
            'paid_total' => (int) ($stats->sales ?? 0),
        ];
    }

    /**
     * 注文者（Party）の名前とメール。見た事実を 1 回の呼び出しで 1 行残す（管理画面の personal と同じく、どの注文のどのお客さんを見たか）
     *
     * @param  list<string>  $partyIds
     * @param  list<string>  $transactionIds
     * @return array<string, array{name: array{untrusted_text: string}, email: array{untrusted_text: string}|null}>
     */
    private function buyers(array $partyIds, string $subject, array $transactionIds): array
    {
        if ($partyIds === []) {
            return [];
        }

        $this->viewed('agent_'.$subject, ['transaction_ids' => $transactionIds, 'customer_ids' => $partyIds]);

        return Party::query()->whereIn('id', $partyIds)->get()->mapWithKeys(static fn (Party $p): array => [$p->id => [
            'name' => self::untrusted($p->name),
            'email' => $p->email === null ? null : self::untrusted($p->email),
        ]])->all();
    }

    private function assertPersonal(): void
    {
        if (! $this->sharesPersonal()) {
            throw new ToolInputException('This shop does not share customer information with AI agents.');
        }
    }

    /**
     * 個人情報を見た事実を中央の監査ログに（管理画面の「お客さんの名前と連絡先を見た」と同じ種類。どの鍵か・何を見たか）
     *
     * @param  array<string, mixed>  $data
     */
    private function viewed(string $subject, array $data): void
    {
        $context = app(AgentContext::class);
        $this->audit->record('operator.personal_data_viewed', Operator::query()->find($context->operatorId), ['subject' => $subject, 'token' => $context->tokenId] + $data, $context->tenantId, request()->ip());
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, array{untrusted_text: string}|null>
     */
    private static function untrustedAddress(array $address): array
    {
        $out = [];

        foreach (['name', 'phone', 'postal', 'prefecture', 'city', 'line1', 'line2'] as $key) {
            $out[$key] = is_string($address[$key] ?? null) && $address[$key] !== '' ? self::untrusted($address[$key]) : null;
        }

        return $out;
    }

    /**
     * 売上（管理画面の売上の画面と同じ数え方。テストの支払いは除く）。日ごとの内訳つき
     *
     * @return array<string, mixed>
     */
    public function salesSummary(string $preset, ?string $from, ?string $to): array
    {
        try {
            $period = SalesPeriod::of($preset, $from, $to);
        } catch (InvalidArgumentException) {
            throw new ToolInputException('The period is not valid (custom periods are up to 366 days and cannot end in the future).');
        }

        $report = $this->sales->summary($period);

        return [
            'period' => $report['period'],
            'currency' => $report['currency'],
            'totals' => array_intersect_key($report['totals'], array_flip(['sales', 'gross', 'refunds', 'orders', 'cancelled_orders', 'average', 'discounts', 'shipping'])),
            'daily' => $report['daily'],
            'top_products' => array_map(static fn (array $row): array => ['product_id' => $row['listing_id'], 'title' => self::untrusted($row['title']), 'quantity' => $row['quantity'], 'amount' => $row['amount']], array_slice($report['products']['by_amount'], 0, 10)),
        ];
    }

    /**
     * 店の人やお客さんが書いた文を、データとして包む（エージェントへの命令として読ませない）
     *
     * @return array{untrusted_text: string}
     */
    public static function untrusted(string $text): array
    {
        return ['untrusted_text' => $text];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private static function stockByListing(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return InventoryStock::query()->whereIn('listing_id', $ids)->selectRaw('listing_id, SUM(quantity - reserved) AS available')
            ->groupBy('listing_id')->pluck('available', 'listing_id')->map(static fn ($v): int => (int) $v)->all();
    }

    private static function day(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw new ToolInputException('Dates must look like 2026-11-12.');
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value, SalesPeriod::zone())?->startOfDay();
    }
}
