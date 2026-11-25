<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\Services\OrderDocuments;
use App\Core\Transaction\Models\Transaction;
use App\Http\PrintPage;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * App の注文の書類（納品書・領収書など）の管理画面の入口（EnterTenant の後）。
 * 一覧（どの書類のボタンを出すか）は閲覧から。印刷用のページは届け先や名前が載るので運用から（個人情報の表示と同じ。ADR 0024）。
 * 注文は ?ids=ID,ID… で受け取り、店の中の物販の注文だけを渡した順に探す（無い ID が 1 つでもあれば 404）。
 * 見た事実は注文の個人情報の表示と同じく監査ログに残し、応答は印刷の CSP（スクリプトなし）と保存させない印で返す
 */
final class OrderDocumentController
{
    /** まとめて出せる注文の数（注文の一覧の 1 ページと同じ） */
    public const int MAX_ORDERS = 50;

    public function __construct(private readonly OrderDocuments $documents) {}

    public function index(): JsonResponse
    {
        return response()->json(['documents' => $this->documents->menu()]);
    }

    public function show(Request $request, string $plugin, string $document, AuditLog $audit): Response
    {
        [$handler, $found] = $this->documents->find($plugin, $document);
        $ids = self::ids($request, $found->bulk);
        $rows = Transaction::query()->where('kind', 'sale')->whereIn('id', $ids)->get()->keyBy('id');

        if ($rows->count() !== count($ids)) {
            throw new AdminRecordNotFound;
        }

        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);
        $audit->record('operator.personal_data_viewed', $operator, [
            'subject' => 'order_document',
            'plugin' => $plugin,
            'document' => $found->key,
            'method' => $request->method(),
            'transaction_ids' => $ids,
        ], (string) Tenant::current()->getTenantKey(), $request->ip());

        PrintPage::nonce($request);
        $response = $handler->staffPage($found->key, array_map(static fn (string $id): Transaction => $rows[$id], $ids), $request, TenantActor::of($request));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * ?ids= の注文の ID（小文字にそろえ、重なりを除いた並び）。書類が bulk でなければ 1 件だけ
     *
     * @return list<string>
     */
    private static function ids(Request $request, bool $bulk): array
    {
        $raw = $request->query('ids');
        $max = $bulk ? self::MAX_ORDERS : 1;
        $ids = is_string($raw) && strlen($raw) <= self::MAX_ORDERS * 27 ? array_values(array_unique(explode(',', strtolower($raw)))) : [];

        if ($ids === [] || count($ids) > $max) {
            throw ValidationException::withMessages(['ids' => $bulk ? "注文を 1〜{$max} 件選んでください。" : '注文を 1 件選んでください。']);
        }

        foreach ($ids as $id) {
            if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) !== 1) {
                throw ValidationException::withMessages(['ids' => '注文の ID の形式が正しくありません。']);
            }
        }

        return $ids;
    }
}
