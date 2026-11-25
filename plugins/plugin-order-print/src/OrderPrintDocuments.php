<?php

declare(strict_types=1);

namespace EcSugar\PluginOrderPrint;

use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\Contracts\OrderDocumentHandler;
use App\Core\Plugin\OrderDocument\OrderDocument;
use App\Core\Transaction\Models\Transaction;
use App\Http\PrintPage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 注文の書類の係（核の OrderDocumentHandler）。管理画面は納品書（何件かまとめて）と領収書（お客さんの領収書のリンクは ReceiptOrderLink）。
 * 経路・ログイン・運用の段階・見た記録・注文を探すことは核が受け持つ（ここは渡された注文でページを描くだけ）
 */
final class OrderPrintDocuments implements OrderDocumentHandler
{
    public function documents(): array
    {
        return [
            new OrderDocument('delivery_note', '納品書', bulk: true),
            new OrderDocument('receipt', '領収書'),
        ];
    }

    public function staffPage(string $document, array $transactions, Request $request, string $actor): Response
    {
        // ロゴは管理画面のホストの写真の経路で読む（印刷の CSP は同じオリジンの画像だけ）
        $tenant = (string) tenant()?->getTenantKey();
        $logoUrl = static fn (string $id): string => '/api/tenants/'.rawurlencode($tenant).'/shop-media/'.$id.'/medium';

        return match ($document) {
            'delivery_note' => $this->deliveryNote($request, $transactions, $logoUrl),
            'receipt' => app(ReceiptPage::class)->render($request, $transactions[0], true, $actor, $request->getRequestUri(), $logoUrl),
            default => throw new AdminRecordNotFound,
        };
    }

    /**
     * @param  list<Transaction>  $transactions
     * @param  callable(string): string  $logoUrl
     */
    private function deliveryNote(Request $request, array $transactions, callable $logoUrl): Response
    {
        $issuer = Issuer::current();

        return response()->view('order-print::delivery-note', [
            'nonce' => PrintPage::nonce($request),
            'title' => count($transactions) === 1 ? '納品書 '.strtoupper($transactions[0]->id) : '納品書（'.count($transactions).' 件）',
            'sheets' => array_map(static fn (Transaction $t): OrderSheet => OrderSheet::of($t, true), $transactions),
            'issuer' => $issuer,
            'logoUrl' => $issuer->logoMediaId === null ? null : $logoUrl($issuer->logoMediaId),
            'issuedOn' => OrderSheet::today(),
        ]);
    }
}
