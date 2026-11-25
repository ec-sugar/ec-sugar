<?php

declare(strict_types=1);

namespace App\Storefront\Orders;

use App\Core\Money\Money;

/**
 * お客さんに見せる注文のギフトカード（ADR 0076。テーマ API の一部。OrderView::$giftCard）。
 * last4 はカードの末尾 4 文字、amount は使った額、remaining は使った後のカードの残り（その時の帳簿の行）、released は取消・返金・送料の差額でカードへ戻した額
 */
final readonly class OrderGiftCardView
{
    public function __construct(
        public string $last4,
        public int $amount,
        public int $remaining,
        public int $released,
    ) {}

    /**
     * @param  array{last4: string, amount: int, remaining: int, released: int}|null  $giftCard  GiftCardService::forOrder の答え
     */
    public static function of(?array $giftCard): ?self
    {
        return $giftCard === null ? null : new self($giftCard['last4'], $giftCard['amount'], $giftCard['remaining'], $giftCard['released']);
    }

    /**
     * 一言（ご注文の状況・注文のメール）
     */
    public function note(): string
    {
        $card = 'ギフトカード（末尾 '.$this->last4.'）';

        return match (true) {
            $this->released >= $this->amount => $card.'でお支払いの '.Money::format($this->amount).' は、ギフトカードの残高に戻しました',
            $this->released > 0 => $card.'でお支払いの '.Money::format($this->amount).' のうち、'.Money::format($this->released).' をギフトカードの残高に戻しました',
            default => $card.'で '.Money::format($this->amount).' をお支払い（ギフトカードの残高 '.Money::format($this->remaining).'）',
        };
    }
}
