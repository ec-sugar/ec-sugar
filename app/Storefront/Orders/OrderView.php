<?php

declare(strict_types=1);

namespace App\Storefront\Orders;

use App\Core\Payment\PaymentState;
use App\Core\Plugin\Gift\GiftShare;
use App\Core\Plugin\OrderLink;
use App\Core\Transaction\TaxBreakdown;

/**
 * お客さんに見せる注文の様子（テーマ API の一部・確認のメール）。名前・住所・電話・メールは入れない
 */
final readonly class OrderView
{
    /**
     * @param  list<array{title: string, quantity: int, amountLabel: string, reduced: bool}>  $items  商品（reduced は軽減税率の対象。「※」を添える。ADR 0063）
     * @param  list<array{carrier: string|null, trackingNumber: string|null, shippedAt: string}>  $shipments  発送（運送会社の名前・お問い合わせ番号・発送日）
     * @param  bool  $free  合計 0 円で支払いが要らない注文
     * @param  list<array{title: string, amountLabel: string}>  $discounts  値引き（クーポン・定期便の値引き。「−¥500」。ADR 0054）と、使ったポイント（「ポイントのご利用」。ADR 0068）
     * @param  list<OrderLink>  $links  App のリンク（レビューを書く・領収書など。OrderLinks）。注文を見てよい人の画面でだけ入れる（メールには入れない）
     * @param  ?GiftShare  $gift  贈り物の注文の様子（ADR 0055。受け取りのリンクは、買う人だけが見る画面とメールなので入れる）
     * @param  bool  $hidePrices  値段を出さない印（贈り物の注文。発送の知らせ・納品書で、受け取る人の目に触れうる所に値段を出さない）
     * @param  ?PaymentState  $payment  支払いの様子（払われた手段・コンビニ払いの入金待ちと払いの期限と払込票の画面。ADR 0058）。支払いの記録が無ければ null
     * @param  ?string  $deliveryWish  お届け日時のご希望（「2026年11月7日（土） 午前中」。選ばなかった注文は null。ADR 0059）
     * @param  ?TaxBreakdown  $taxes  税率ごとの対象の額と消費税額（ADR 0063。注文の時の率で）
     * @param  list<array{name: string, value: string}>  $fields  注文の項目の選ぶ・チェックの答え（ADR 0066。注文の時の欄の名前で。チェックは「はい」「いいえ」）。文の答えは入れない
     * @param  bool  $fieldsWithheld  文の答え（ご要望など）がある注文（中身は出さず「お店に届いています」の一言だけ。ADR 0066 の決定 5）
     * @param  ?OrderPointsView  $points  ポイント（ADR 0068。使った数・付く予定の数・付いた数・一言）。核の部品 <x-storefront::order-points> に渡す。会員でない注文は null
     * @param  list<array{title: string, amountLabel: string}>  $charges  支払いの手数料（「代引き手数料 ¥330」。ADR 0074）。無ければ空
     * @param  bool  $bankTransfer  銀行振込で受けた注文（取り消した後も分かる。期限切れの知らせの文を変える。ADR 0074）
     * @param  bool  $inPerson  来店で受け渡す注文（予約の品。受渡が役務だけ。発送の言葉を使わない）
     * @param  ?OrderGiftCardView  $giftCard  ギフトカード（ADR 0076。末尾 4 文字・使った額・使った後の残り）。核の部品 <x-storefront::order-gift-card> に渡す。使っていない注文は null
     * @param  ?string  $preorder  予約の品のある注文の発送の予定（「11月20日ごろ発送（予約商品）」。ADR 0073）。予約でない・もう発送した・取り消した注文は null
     * @param  ?string  $preorderOrderedOn  注文の時に約束した発売日（「11月20日」）。発売日が変わって今の予定と違う時だけ。ほかは null
     */
    public function __construct(
        public string $orderId,
        public string $status,
        public string $statusLabel,
        public string $placedAt,
        public array $items,
        public ?string $shippingLabel,
        public string $totalLabel,
        public array $shipments,
        public bool $free = false,
        public array $discounts = [],
        public array $links = [],
        public ?GiftShare $gift = null,
        public bool $hidePrices = false,
        public ?PaymentState $payment = null,
        public ?string $deliveryWish = null,
        public ?TaxBreakdown $taxes = null,
        public array $fields = [],
        public bool $fieldsWithheld = false,
        public ?OrderPointsView $points = null,
        public array $charges = [],
        public bool $bankTransfer = false,
        public bool $inPerson = false,
        public ?OrderGiftCardView $giftCard = null,
        public ?string $preorder = null,
        public ?string $preorderOrderedOn = null,
    ) {}

    /**
     * App のリンクを付けた写し
     *
     * @param  list<OrderLink>  $links
     */
    public function withLinks(array $links): self
    {
        return new self($this->orderId, $this->status, $this->statusLabel, $this->placedAt, $this->items, $this->shippingLabel, $this->totalLabel, $this->shipments, $this->free, $this->discounts, $links, $this->gift, $this->hidePrices, $this->payment, $this->deliveryWish, $this->taxes, $this->fields, $this->fieldsWithheld, $this->points, $this->charges, $this->bankTransfer, $this->inPerson, $this->giftCard, $this->preorder, $this->preorderOrderedOn);
    }

    /**
     * お客さんに伝える注文番号（取引の ID を大文字で）
     */
    public function number(): string
    {
        return strtoupper($this->orderId);
    }

    /**
     * コンビニ払い・銀行振込の入金を待っている（ADR 0058・0074）
     */
    public function awaitingPayment(): bool
    {
        return $this->payment !== null && $this->payment->awaiting && ! $this->cancelled();
    }

    public function cancelled(): bool
    {
        return in_array($this->status, ['cancelled', 'refunded'], true);
    }
}
