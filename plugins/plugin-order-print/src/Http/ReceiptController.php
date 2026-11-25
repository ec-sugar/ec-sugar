<?php

declare(strict_types=1);

namespace EcSugar\PluginOrderPrint\Http;

use App\Http\PrintPage;
use App\Storefront\Access\SecretLinks;
use App\Storefront\Orders\OrderReader;
use EcSugar\PluginOrderPrint\ReceiptPage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * お客さんの領収書（/p/order-print/receipt/{注文}）。ご注文の確認とマイページの履歴のリンク（期限つきの署名つき。店の名前も署名に入る）からだけ開ける。
 * 署名が違う・期限切れ・ほかの店の署名・ショップの注文でない、はどれも同じ答え（あるかどうかを教えない）。払い済みの注文だけ。
 * GET は宛名の欄、POST（「領収書を出す」）で回数を数えて描く（2 回目から「再発行」）。署名つきの URL を次へ渡さず、ブラウザにも残さない
 */
final class ReceiptController
{
    public function __construct(
        private readonly OrderReader $orders,
        private readonly ReceiptPage $page,
    ) {}

    public function show(Request $request, string $orderId): Response
    {
        // 参照元を渡さない・残さない・検索に載せないは、経路の宣言（secret-link）で核が掛ける
        $transaction = $this->orders->find($orderId);

        if ($transaction === null || ! SecretLinks::valid($request)) {
            return ReceiptPage::refused(PrintPage::nonce($request), 'リンクの期限が切れたか、正しくありません。ご注文の確認の画面かマイページから、もう一度「領収書」を開いてください。', 403);
        }

        // ロゴはショップの写真の経路で読む（印刷の CSP は同じオリジンの画像だけ）
        $logo = static fn (string $id): string => route('storefront.media.shop', ['mediaId' => $id, 'variant' => 'medium'], false);

        return $this->page->render($request, $transaction, false, 'storefront', $request->getRequestUri(), $logo);
    }
}
