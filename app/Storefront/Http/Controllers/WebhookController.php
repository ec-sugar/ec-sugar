<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Payment\Contracts\PaymentWebhookReceiver;
use App\Core\Payment\WebhookRejected;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 決済代行からの通知の受け口。送るのはお客さんではなく決済代行。セッションも CSRF も無く、署名で確かめる。
 * 答えには処理の結果を出さない（受け取った / 断った だけ）。
 * どの代行かは口（PaymentWebhookReceiver）の向こう側で、ここは署名の見出しの名前も代行に聞く（ADR 0107 の段 3）
 */
final class WebhookController
{
    public function stripe(Request $request, PaymentWebhookReceiver $webhook): JsonResponse
    {
        return self::answer(static fn (): string => $webhook->receive($request->getContent(), $request->header($webhook->signatureHeader())));
    }

    /**
     * つないだ店（Stripe Connect）の通知。運営の 1 か所（中央のホスト）で受ける（ADR 0022）
     */
    public function stripeConnect(Request $request, PaymentWebhookReceiver $webhook): JsonResponse
    {
        return self::answer(static fn (): string => $webhook->receiveForPlatform($request->getContent(), $request->header($webhook->signatureHeader())));
    }

    /**
     * @param  callable(): string  $receive
     */
    private static function answer(callable $receive): JsonResponse
    {
        try {
            $receive();
        } catch (WebhookRejected) {
            return response()->json(['received' => false], 400);
        }

        return response()->json(['received' => true]);
    }
}
