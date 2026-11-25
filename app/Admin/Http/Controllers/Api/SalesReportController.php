<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Requests\SalesReportRequest;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\SalesReport;
use App\Core\Csv\CsvCells;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 売上の集計（ADR 0060。EnterTenant の後、お金の段階から）。読むだけで、店の中の数と商品の名前だけを返す（お客さんは人数だけ）。
 * 1 回の画面の数の時点をそろえるため、読むだけの REPEATABLE READ のトランザクションで包む（運営の様子と同じ）。
 * CSV は日ごと・商品ごと。文字の欄は式よけ（CsvCells）、UTF-8 の BOM つき・改行は CRLF。書き出したことは中央の監査ログに残す（中身は残さない）
 */
final class SalesReportController
{
    public function __construct(private readonly SalesReport $report) {}

    public function show(SalesReportRequest $request): JsonResponse
    {
        $period = $request->period();

        return response()->json(self::snapshot(fn (): array => $this->report->summary($period)));
    }

    public function csv(SalesReportRequest $request, AuditLog $audit): Response
    {
        $period = $request->period();
        $kind = $request->kind();

        $rows = self::snapshot(fn (): array => $kind === 'products'
            ? array_map(static fn (array $p): array => [$p['listing_id'] ?? '', CsvCells::neutralize($p['title']), (string) $p['quantity'], (string) $p['amount']], $this->report->products($period))
            : array_map(static fn (array $d): array => [$d['day'], (string) $d['orders'], (string) $d['gross'], (string) $d['refunds'], (string) $d['sales']], $this->report->daily($period)));
        $header = $kind === 'products' ? ['商品 ID', '商品名', '数量', '金額（円）'] : ['日付', '注文の数', '入金（円）', '返金（円）', '売上（円）'];

        $tenant = (string) Tenant::current()->getTenantKey();
        $range = $period->toArray();
        $filename = "sales-{$kind}-{$tenant}-{$range['from']}-{$range['to']}.csv";

        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);
        $audit->record('operator.sales_exported', $operator, ['kind' => $kind, 'from' => $range['from'], 'to' => $range['to'], 'rows' => count($rows)], $tenant, $request->ip());

        return response(self::encode($header, $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private static function snapshot(callable $read): mixed
    {
        return DB::transaction(static function () use ($read): mixed {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $read();
        });
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    private static function encode(array $header, array $rows): string
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('一時保存先を開けません');
        }

        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $header, escape: '', eol: "\r\n");

            foreach ($rows as $row) {
                fputcsv($stream, $row, escape: '', eol: "\r\n");
            }

            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}
