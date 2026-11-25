<?php

declare(strict_types=1);

namespace App\Storefront\Cart;

use App\Core\Money\Money;

/**
 * カートと注文の確かめのポイント（ADR 0068。テーマ API の一部。CartView::$points）。1 ポイント = 1 円。
 *
 * - enabled: 店がポイントを使っている（使わない店では、ほかの値は全部 0 で、何も出さない）
 * - member: ログインした会員（貯めて使える）。ゲストは earn に「会員なら」付く数だけが入る
 * - used: このご注文で使うポイント（値引きの後の合計〔送料込み〕から引く）。byRate はその税率ごとの割り振り（送料の分は標準の率）、onShipping は送料に掛かった分
 * - earn: このご注文で付くポイント（発送の時。商品の代金〔値引きと使ったポイントの後、税込み、送料を除く〕× 店の率の切り捨て）。earnBase はその元の額
 * - balance: 会員の今の残高（使う前）
 * - lowered: 使った後の合計が 1〜49 円になるので、50 円が残る数に抑えた（Stripe の円の最低額。「数を入れる」なら入力の画面で断る）
 */
final readonly class CartPoints
{
    /**
     * @param  array<int, int>  $byRate  税率（basis point）=> 使ったポイント
     */
    public function __construct(
        public bool $member = false,
        public int $earn = 0,
        public int $used = 0,
        public int $balance = 0,
        public array $byRate = [],
        public int $onShipping = 0,
        public int $earnBase = 0,
        public bool $enabled = true,
        public bool $lowered = false,
    ) {}

    /** 使った後に残してよいいちばん小さい合計（Stripe の円の最低額）。使った後の合計は 0 円か、これ以上 */
    public const int MINIMUM_CHARGE = 50;

    public const string BELOW_MINIMUM = 'ポイントを使った後のお支払い額が 1〜49 円になる使い方はできません（お支払いは 50 円以上が必要です）。ポイントをすべて使うか、お支払い額が 50 円以上残るポイント数にしてください。';

    public static function none(): self
    {
        return new self(enabled: false);
    }

    /**
     * 「−¥300」（使っていなければ null）
     */
    public function usedLabel(): ?string
    {
        return $this->used > 0 ? '−'.Money::format($this->used) : null;
    }

    /**
     * 付くポイントの一言（カート・確かめの画面）。会員は「このご注文で 30 ポイント付きます（発送の時）」、ゲストは「会員なら 30 ポイント付きます」。付かなければ null
     */
    public function earnNote(): ?string
    {
        if (! $this->enabled || $this->earn <= 0) {
            return null;
        }

        return $this->member
            ? 'このご注文で '.number_format($this->earn).' ポイントが付きます（発送時）'
            : '会員の方は '.number_format($this->earn).' ポイントが付きます';
    }
}
