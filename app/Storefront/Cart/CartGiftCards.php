<?php

declare(strict_types=1);

namespace App\Storefront\Cart;

use App\Core\GiftCard\Models\GiftCard;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Payment\Contracts\PaymentCredentialStore;

/**
 * 注文の確かめと確定で、入れたギフトカードを合計に充てる（ADR 0076）。確かめの画面・確定（錠の中）は同じ決め方なので、指紋がずれない。
 * 使う額 = 残高と合計（値引き → ポイントの後、送料込み）の小さい方。ただし使った後のお支払いが 1〜49 円になるなら、50 円が残る額に抑える（Stripe の円の最低額）。
 * 止めた・期限の過ぎた・残高 0 のカード、本番の鍵の店でのテストの支払いで売ったカードは使わず、理由（problem）を返す（確かめの画面は入力の画面へ戻す）
 */
final class CartGiftCards
{
    public function __construct(
        private readonly GiftCardService $giftCards,
        private readonly PaymentCredentialStore $gateways,
    ) {}

    /**
     * 店の決済代行が本番の鍵か（鍵が無ければ本番として扱う。閉じる側に倒す）
     */
    public function liveShop(): bool
    {
        return $this->gateways->credentials()?->mode() !== 'test';
    }

    public function apply(?string $cardId, int $total): CartGiftCard
    {
        if ($cardId === null) {
            return CartGiftCard::none();
        }

        $card = GiftCard::query()->find($cardId);

        if ($card === null) {
            return new CartGiftCard(id: $cardId, problem: GiftCardService::NOT_FOUND);
        }

        $balance = $this->giftCards->balance($card->id);
        $expires = $card->expires_at->timezone('Asia/Tokyo')->format('Y/m/d');
        $live = $this->liveShop();
        $problem = $this->giftCards->problem($card, null, $live);

        if ($problem !== null) {
            return new CartGiftCard(id: $card->id, last4: $card->last4, balance: $balance, expiresAt: $expires, problem: $problem, liveShop: $live);
        }

        $use = max(0, min($balance, $total));
        $lowered = $total - $use >= 1 && $total - $use < CartPoints::MINIMUM_CHARGE;

        if ($lowered) {
            $use = max(0, $total - CartPoints::MINIMUM_CHARGE);
        }

        return new CartGiftCard(id: $card->id, last4: $card->last4, applied: $use, balance: $balance, expiresAt: $expires, lowered: $lowered, liveShop: $live);
    }
}
