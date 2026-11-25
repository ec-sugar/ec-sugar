<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\Requests\ListTenantsRequest;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\ShopHome;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Payment\Models\Refund;
use App\Core\Payment\Models\StrayPayment;
use App\Core\Plugin\Services\AdminPages;
use App\Core\Sample\Services\ShopSamples;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * テナントの一覧（中央）と、テナントの中の概要（EnterTenant の後）。
 */
final class TenantController
{
    public function index(ListTenantsRequest $request, OperatorAccess $access): JsonResponse
    {
        $operator = $request->user('operator');
        assert($operator instanceof Operator);
        // スタッフには権限のある店だけ（ADR 0024）
        $allowed = $access->tenantIds($operator);

        $page = Tenant::query()
            ->when($allowed !== null, static fn ($query) => $query->whereIn('id', $allowed))
            // 新しい店から（ID の順は店の人にとって意味がない。同じ時刻なら ID の逆順で、並びを毎回同じにする）
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->perPage(), ['id', 'name', 'storefront_scope', 'seller_model', 'settlement', 'created_at']);

        // ページ内のテナントのドメインを 1 回で引く
        $domains = Domain::query()
            ->whereIn('tenant_id', collect($page->items())->map(static fn (Tenant $tenant): mixed => $tenant->getTenantKey())->all())
            ->orderBy('domain')
            ->get(['tenant_id', 'domain'])
            ->groupBy('tenant_id');

        return response()->json([
            'tenants' => collect($page->items())->map(static fn (Tenant $tenant): array => [
                'id' => $tenant->getTenantKey(),
                'name' => $tenant->getAttribute('name'),
                'storefront_scope' => $tenant->getAttribute('storefront_scope'),
                'seller_model' => $tenant->getAttribute('seller_model'),
                'settlement' => $tenant->getAttribute('settlement'),
                'domains' => ($domains->get((string) $tenant->getTenantKey()) ?? collect())->pluck('domain')->values(),
                // 管理画面から店頭を開くリンク
                // この運営のこの店での段階（画面のボタンの出し分けのため。守るのはサーバー）
                'level' => $access->levelFor($operator, (string) $tenant->getTenantKey()),
                'storefront_url' => ($first = ($domains->get((string) $tenant->getTenantKey()) ?? collect())->first()) === null ? null : StorefrontUrls::base((string) $first->domain).'/',
                'created_at' => $tenant->getAttribute('created_at')?->toIso8601String(),
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * テナントの中の概要。数は軽い集計だけ（連鎖の検証は全件を読むので verify に分ける）。
     */
    public function overview(Request $request, ShopSamples $samples, ShopHome $home, AdminPages $pages): JsonResponse
    {
        $tenant = Tenant::current();

        return response()->json([
            'tenant' => ['id' => $tenant->getTenantKey(), 'name' => $tenant->getAttribute('name'), 'storefront_url' => StorefrontUrls::of($tenant), 'level' => $request->attributes->get(EnterTenant::LEVEL)],
            // 返金が要る支払い（二重に払われた・取り消した注文に届いた）。概要で知らせる
            'stray_payments' => StrayPayment::query()->where('status', 'pending')->orderBy('created_at')->limit(20)->get(['id', 'transaction_id', 'reason', 'amount'])
                ->map(static fn (StrayPayment $stray): array => ['id' => $stray->id, 'transaction_id' => $stray->transaction_id, 'reason' => $stray->reason, 'amount' => $stray->amount])->values(),
            // 要対応の返金（ADR 0027）: 取り消した注文の返金が後から失敗した（お金が戻っていない）・成功の後に戻された・額が違って止めた・1 時間以上途中のまま
            // 決まりは ShopHome::refundIssues（運営全体の困りごとの数と同じ）
            'refund_issues' => ShopHome::refundIssues()
                ->orderBy('created_at')->limit(20)->get(['id', 'transaction_id', 'purpose', 'amount', 'status', 'failure_reason'])
                ->map(static fn (Refund $refund): array => ['id' => $refund->id, 'transaction_id' => $refund->transaction_id, 'purpose' => $refund->purpose, 'amount' => $refund->amount, 'status' => $refund->status, 'failure_reason' => $refund->failure_reason])->values(),
            // 残っている入れたときのサンプルの数（無ければ null）。ホームの「サンプルをまとめて消す」に使う
            'samples' => $samples->summary(),
            // ホームの数・やること・最近の注文。注文の一覧と同じく閲覧の段階から読め、個人情報は入れない
            'stats' => $home->stats(),
            'todo' => $home->todo(),
            // App のやること（ADR 0051。定期便の「止まった定期便」など）。使っている App の、件数が 1 以上の行だけ
            'app_todo' => $pages->attention(),
            'recent' => $home->recent(),
            'counts' => [
                'sellers' => Seller::query()->count(),
                'listings' => Listing::query()->count(),
                'transactions' => Transaction::query()->toBase()->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')
                    ->map(static fn (mixed $n): int => (int) $n),
                'events' => (int) DomainEvent::query()->max('sequence'),
            ],
        ]);
    }

    public function verifyEvents(EventStore $events): JsonResponse
    {
        $result = $events->verify();

        return response()->json(['checked' => $result->checked, 'intact' => $result->intact(), 'broken_at' => $result->brokenAt]);
    }
}
