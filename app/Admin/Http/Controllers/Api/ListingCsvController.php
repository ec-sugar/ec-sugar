<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Requests\ListingCsvRequest;
use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use EcSugar\PluginProductCsv\Models\ProductImport;
use EcSugar\PluginProductCsv\ProductCsvExporter;
use EcSugar\PluginProductCsv\ProductImports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * 商品の CSV の書き出しと取り込み（EnterTenant の後。運用の段階から）。決まりは docs/design/product-csv.md。
 *
 * 取り込みは記録（product_imports）を 1 つ作り、確かめも反映もキューの仕事で進める。画面は記録を読み直して待つ。
 * 上げる（check）→ 確かめの結果を見る → 反映を頼む（apply。誤りの無い記録だけ。ほかの反映が待ち・進行中なら 409）→ 進み具合を見る。
 * 止まったら「もう一度取り込む」（retry。同じ CSV の新しい記録で確かめから）。反映を頼んだら、誰が・何件を中央の監査ログに残す
 */
final class ListingCsvController
{
    public function __construct(private readonly ProductImports $imports) {}

    public function export(ProductCsvExporter $exporter): Response
    {
        // 写真の列は、ショップの写真の公開の URL（取り込みで同じ店の写真と見分ける）
        $csv = $exporter->export(StorefrontUrls::of(Tenant::current(), ''));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.ProductCsvExporter::filename((string) Tenant::current()->getTenantKey()).'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 上げて確かめを始める（ファイルの形が違えば 422 で、記録を作らない）。写真の ZIP も一緒なら、ここで展開して置く
     */
    public function check(ListingCsvRequest $request): JsonResponse
    {
        $import = $this->imports->upload($request->bytes(), TenantActor::of($request), $request->photosPath());

        return response()->json(['import' => ProductImports::present($import)], 202);
    }

    /**
     * 新しい取り込みから 5 つ（画面を開いたとき、進行中の反映や止まった取り込みを出す）
     */
    public function index(): JsonResponse
    {
        return response()->json(['imports' => ProductImport::query()->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get()
            ->map(static fn (ProductImport $import): array => ProductImports::present($import))->values()]);
    }

    public function show(string $importId): JsonResponse
    {
        return response()->json(['import' => ProductImports::present($this->find($importId))]);
    }

    public function apply(Request $request, string $importId, AuditLog $audit): JsonResponse
    {
        $import = $this->imports->queue($this->find($importId));
        /** @var array{counts?: array{create?: int, update?: int, skip?: int}, products?: array{create?: int, update?: int}} $report */
        $report = $import->report ?? [];
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);
        $audit->record('operator.listings_imported', $operator, [
            'import_id' => $import->id,
            'rows' => $import->rows,
            'create' => $report['counts']['create'] ?? 0,
            'update' => $report['counts']['update'] ?? 0,
            'skip' => $report['counts']['skip'] ?? 0,
            'products_created' => $report['products']['create'] ?? 0,
            'products_updated' => $report['products']['update'] ?? 0,
        ], (string) Tenant::current()->getTenantKey(), $request->ip());

        return response()->json(['import' => ProductImports::present($import->refresh())], 202);
    }

    public function retry(Request $request, string $importId): JsonResponse
    {
        $import = $this->imports->retry($this->find($importId), TenantActor::of($request));

        return response()->json(['import' => ProductImports::present($import)], 202);
    }

    private function find(string $importId): ProductImport
    {
        return ProductImport::query()->findOrFail(strtolower($importId));
    }
}
