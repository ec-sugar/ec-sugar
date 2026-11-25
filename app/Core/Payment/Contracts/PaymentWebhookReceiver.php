<?php

declare(strict_types=1);

namespace App\Core\Payment\Contracts;

/**
 * 決済代行からの通知の受け口（ADR 0026）。核は経路（/webhooks/…）と答えだけを持ち、
 * 署名の確かめ方・載る見出しの名前・通知の種類はドライバの中に閉じる。
 * 確かめられない通知は WebhookRejected を投げる（核は 400 を返すだけで、中身を答えに出さない）
 */
interface PaymentWebhookReceiver
{
    /**
     * 店の受け口に届いた通知。答えは記録のための短い印（経路は中身を出さない）
     */
    public function receive(string $payload, ?string $signature): string;

    /**
     * 運営の 1 か所（中央のホスト）で受ける、つないだ店の通知（ADR 0022）。
     * そういう形を持たない決済代行のドライバは WebhookRejected を投げてよい
     */
    public function receiveForPlatform(string $payload, ?string $signature): string;

    /**
     * 署名が載る HTTP の見出しの名前（決済代行ごとに違う。Stripe なら Stripe-Signature）
     */
    public function signatureHeader(): string;
}
