<?php

declare(strict_types=1);

namespace App\Storefront\Cart;

use App\Core\Money\Money;

/**
 * 注文の確かめのギフトカード（ADR 0076。テーマ API の一部。CartView::$giftCard）。1 円 = 1 円。
 * ギフトカードは値引きではなく支払いの一部なので、注文の合計（total）も消費税の計算も変えない。払ってもらう額（due）が減るだけ。
 *
 * - id: 注文の入力で入れたコードのカード（コードそのものはセッションにも持たない）。last4 は見分けのための末尾 4 文字
 * - applied: このご注文に使う額（値引き → ポイントの後の合計まで）。balance は使う前の残高、remaining は使った後の残高
 * - expiresAt: 期限（「2027/05/04」）
 * - lowered: 使った後のお支払いが 1〜49 円になるので、50 円が残る額に抑えた（Stripe の円の最低額）
 * - problem: 使えない理由（止めた・テストのカードで本番の鍵の店・期限切れ・残高 0。使えれば null）
 */
final readonly class CartGiftCard
{
    public function __construct(
        public ?string $id = null,
        public string $last4 = '',
        public int $applied = 0,
        public int $balance = 0,
        public ?string $expiresAt = null,
        public bool $lowered = false,
        public ?string $problem = null,
        // 店の決済代行が本番の鍵か（テストの支払いで売ったカードを使わせない。注文の確定の錠の中でも同じ値で確かめる）
        public bool $liveShop = false,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function remaining(): int
    {
        return $this->balance - $this->applied;
    }

    /**
     * 「−¥3,000」（使っていなければ null）
     */
    public function appliedLabel(): ?string
    {
        return $this->applied > 0 ? '−'.Money::format($this->applied) : null;
    }

    public function remainingLabel(): string
    {
        return Money::format($this->remaining());
    }
}
