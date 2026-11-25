<?php

declare(strict_types=1);

namespace App\Storefront\Cart;

use App\Core\Discount\Services\AutomaticDiscounts;
use App\Core\Money\Money;
use App\Core\Plugin\OrderCharge\ChargeQuote;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Shop\Shipping\ShippingQuote;
use App\Core\Tax\TaxClass;
use App\Core\Transaction\TaxBreakdown;
use App\Storefront\Catalog\ListingCard;

/**
 * 店頭に出すカート（テーマ API の一部）。開くたびにサーバーの商品から計算し直す（入れた時の価格や在庫を信じない）。
 * 合計は、今買える行だけ。notice は、入れた後に変わったことのお知らせ（価格・在庫・販売の終了）。
 * 送料は送料の表（配送の方法ごとの都道府県の額と無料の線。ADR 0064）で、便ごとの品の小計（クーポンの値引きの後。ADR 0054）から決める。
 * お届け先をまだ知らない（カート・注文の入力）ときは目安（shippingUpTo まで。「¥800〜¥1,500」）、確かめの画面からはお届け先の都道府県の額。
 * couponCode はカートに入れたコード、couponNotice はそのコードを使っていない理由（使えない・自動の割引の方が得。使っていれば null）、discount は商品の値引きの額（正の円）。
 * 値引きは 1 つの注文に 1 つ（ADR 0065）: 効いた値引き（クーポンか自動の割引）は discountId・discountName（「クーポン（CODE）」か自動の割引の名前）・discountAutomatic、
 * 対象の行は discountKeys（注文の確定が割り振りに使う）。discountHint は次に届く自動の割引の一言（「あと 1 点で「まとめ買い 10%」」。無ければ null）。
 * taxAmounts は税率ごとの額（値引きと送料と使ったポイントの後。ADR 0063）。内訳は taxBreakdown()。
 * points はポイント（ADR 0068。使う数・付く数・残高。CartPoints）。合計は値引きの後の合計（送料込み）から使ったポイントを引いた額。
 * paymentFee は支払いの手数料（代引き手数料。ADR 0074。税込み・標準の率）。確かめの画面と確定でだけ足す（withPaymentFee）。合計と税率ごとの額に入る
 * giftCard はギフトカード（ADR 0076。CartGiftCard）。支払いの一部なので合計は変えず、お支払いの額（due）はその合計からギフトカードの額を引いた額。
 * charges は App が足す料金の行（ラッピング・のしなど。ADR 0078。kind・title・amount。税込み・標準の率）。確かめの画面と確定でだけ足す（withCharges）。
 * 使ったポイントの後・代引き手数料の前に足し（ポイントでは払わない）、合計と税率ごとの額に入る。ギフトカードは料金を含めた合計に充て直す。
 * minimumSubtotal は 1 回のご注文の商品の合計の下限（App の決まり。ADR 0109。無ければ 0）。今買える商品の合計（値引きの前）がこれに届かなければ注文に進めない（belowMinimum）。
 * 予約の商品（ADR 0073）は行の card->preorder に発売日。予約の品があれば全部をいちばん遅い発売日にまとめて送る（preorderShipDate。送料は今のまま 1 回）。
 */
final readonly class CartView
{
    /**
     * lineTotalLabel は行の小計の見せ方（「¥7,660」。Money::format の形。テーマは金額を自分で整えない）
     *
     * @param  list<array{card: ListingCard|null, title: string, quantity: int, maxQuantity: int, lineTotal: int, lineTotalLabel: string, purchasable: bool, notice: string|null, listingId: string, lineId: string, taxRateBp: int, digital?: bool, inPerson?: bool}>  $lines
     * @param  array<int, int>  $taxAmounts  税率（basis point）=> 額（税込み）
     * @param  list<string>  $discountKeys  値引きの対象の行（カートの行の ID）
     * @param  list<array{kind: string, title: string, amount: int}>  $charges  App の料金の行（ADR 0078）
     */
    public function __construct(
        public array $lines,
        public int $subtotal,
        public int $count,
        public int $shipping = 0,
        public ?int $freeShippingRemaining = null,
        public int $discount = 0,
        public ?string $couponCode = null,
        public ?string $couponNotice = null,
        public bool $shippingWaived = false,
        public int $waivedShipping = 0,
        public array $taxAmounts = [],
        public ?ShippingQuote $shippingQuote = null,
        public ?string $discountId = null,
        public ?string $discountName = null,
        public bool $discountAutomatic = false,
        public array $discountKeys = [],
        public ?string $discountHint = null,
        public CartPoints $points = new CartPoints(enabled: false),
        public int $paymentFee = 0,
        public CartGiftCard $giftCard = new CartGiftCard,
        public array $charges = [],
        public int $minimumSubtotal = 0,
    ) {}

    /**
     * 支払いの手数料（代引き手数料）を足した写し。手数料は標準の率で、使ったポイントの後に足す（ポイントでは払わない。ADR 0074）。
     * App の料金の行とギフトカードはそのまま持ち越す（ギフトカードは手数料の前の合計に充てたまま。手数料は残りのお支払いに入る。ADR 0076）
     */
    public function withPaymentFee(int $fee): self
    {
        if ($fee <= 0) {
            return $this;
        }

        return $this->copy(self::addStandard($this->taxAmounts, $fee), $this->paymentFee + $fee, $this->giftCard, $this->charges);
    }

    /**
     * App の料金の行（ADR 0078）を足した写し。行は標準の率で、使ったポイントの後・代引き手数料の前に足す（ポイントでは払わない）。
     * ギフトカードは料金を含めた合計に充て直したもの（$giftCard。CartGiftCards::apply）を受け取る
     *
     * @param  list<array{kind: string, title: string, amount: int}>  $charges
     */
    public function withCharges(array $charges, CartGiftCard $giftCard): self
    {
        if ($charges === []) {
            return $this;
        }

        return $this->copy(self::addStandard($this->taxAmounts, array_sum(array_column($charges, 'amount'))), $this->paymentFee, $giftCard, [...$this->charges, ...$charges]);
    }

    /**
     * App の料金の行の合計（税込み）
     */
    public function chargesTotal(): int
    {
        return array_sum(array_column($this->charges, 'amount'));
    }

    /**
     * App の料金の行の見せ方（確かめの画面の合計の欄。核の部品 <x-storefront::charges-used>）。0 円は「無料」
     *
     * @return list<array{title: string, amountLabel: string}>
     */
    public function chargeLines(): array
    {
        return array_map(static fn (array $c): array => ['title' => $c['title'], 'amountLabel' => ChargeQuote::amountLabel($c['amount'])], $this->charges);
    }

    /**
     * @param  array<int, int>  $taxAmounts
     * @return array<int, int>
     */
    private static function addStandard(array $taxAmounts, int $amount): array
    {
        $rate = TaxClass::Standard->rateBp();
        $taxAmounts[$rate] = ($taxAmounts[$rate] ?? 0) + $amount;
        ksort($taxAmounts);

        return $taxAmounts;
    }

    /**
     * @param  array<int, int>  $taxAmounts
     * @param  list<array{kind: string, title: string, amount: int}>  $charges
     */
    private function copy(array $taxAmounts, int $paymentFee, CartGiftCard $giftCard, array $charges): self
    {
        return new self($this->lines, $this->subtotal, $this->count, $this->shipping, $this->freeShippingRemaining, $this->discount, $this->couponCode, $this->couponNotice,
            $this->shippingWaived, $this->waivedShipping, $taxAmounts, $this->shippingQuote, $this->discountId, $this->discountName, $this->discountAutomatic, $this->discountKeys,
            $this->discountHint, $this->points, $paymentFee, $giftCard, $charges, $this->minimumSubtotal);
    }

    /**
     * 手数料（「¥330」）。無ければ null
     */
    public function paymentFeeLabel(): ?string
    {
        return $this->paymentFee > 0 ? Money::format($this->paymentFee) : null;
    }

    public static function empty(): self
    {
        return new self([], 0, 0);
    }

    public function total(): int
    {
        return $this->subtotal - $this->discount - $this->points->used + $this->shipping + $this->chargesTotal() + $this->paymentFee;
    }

    /**
     * 決済代行で払ってもらう額（合計 − ギフトカードで払う額。ADR 0076）。0 ならお支払いは要らない
     */
    public function due(): int
    {
        return $this->total() - $this->giftCard->applied;
    }

    public function dueLabel(): string
    {
        return Money::format($this->due());
    }

    public function totalLabel(): string
    {
        $upTo = $this->shippingUpTo();

        return $upTo === null ? Money::format($this->total()) : Money::format($this->total()).'〜'.Money::format($this->total() - $this->shipping + $upTo);
    }

    /**
     * 送料の目安のいちばん高い額（お届け先をまだ知らず、地域で額が違う時だけ。ほかは null）
     */
    public function shippingUpTo(): ?int
    {
        return $this->shippingQuote?->upTo();
    }

    /**
     * 「¥800」「無料」「¥800〜¥1,500」。店に配送の方法が 2 つ以上・便が 2 つ以上なら方法の名前を添える（「¥2,000（通常便 ¥800・クール便 ¥1,200）」）
     */
    public function shippingLabel(): string
    {
        $upTo = $this->shippingUpTo();
        $label = match (true) {
            $upTo !== null => Money::format($this->shipping).'〜'.Money::format($upTo),
            $this->shipping === 0 => $this->shippingWaived ? '無料（'.($this->discountAutomatic ? $this->discountName : 'クーポン').'）' : '無料',
            default => Money::format($this->shipping),
        };
        $detail = $this->shippingQuote?->detail();

        return $detail === null ? $label : $label.'（'.$detail.'）';
    }

    /**
     * 送料の添え書き（目安のとき・便を分けて送るとき。無ければ null）
     */
    public function shippingNote(): ?string
    {
        $notes = [];

        if ($this->shippingUpTo() !== null) {
            $notes[] = '送料はお届け先の都道府県で決まります（ご注文内容の確認画面でお知らせします）。';
        }

        if (count($this->shippingQuote->groups ?? []) > 1) {
            $notes[] = '配送方法の異なる商品は別々にお送りするため、送料はそれぞれにかかります。';
        }

        // 予約の品（ADR 0073）: ふつうの品や発売日の違う予約の品と一緒なら、いちばん遅い発売日にまとめて送る
        $shipDate = $this->preorderShipDate();
        if ($shipDate !== null) {
            $notes[] = $this->hasRegularItems() || count($this->preorderDates()) > 1
                ? '予約商品の発売日（'.PreorderTerms::dateLabel($shipDate).'ごろ）にまとめてお送りします。'
                : '予約商品は発売日（'.PreorderTerms::dateLabel($shipDate).'ごろ）にお送りします。';
        }

        return $notes === [] ? null : implode('', $notes);
    }

    /**
     * 予約の品をまとめて送る日（今買える行の予約の商品の、いちばん遅い発売日。Y-m-d。予約の品が無ければ null。ADR 0073）。
     * 注文の受渡はこの日まで発送できず、お届け日時の指定（ADR 0059）は受けない
     */
    public function preorderShipDate(): ?string
    {
        $dates = $this->preorderDates();

        return $dates === [] ? null : max($dates);
    }

    /**
     * @return list<string> 今買える行の予約の商品の発売日（重ねない）
     */
    private function preorderDates(): array
    {
        $dates = [];

        foreach ($this->lines as $line) {
            if ($line['purchasable'] && $line['card']?->preorder !== null) {
                $dates[$line['card']->preorder] = true;
            }
        }

        return array_keys($dates);
    }

    /**
     * 今買える行に、予約でない品がある
     */
    private function hasRegularItems(): bool
    {
        foreach ($this->lines as $line) {
            if ($line['purchasable'] && $line['card'] !== null && $line['card']->preorder === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * 値引きの額（「−¥500」。クーポンか自動の割引。無ければ null。ADR 0054・0065）。行の名前は discountName
     */
    public function discountLabel(): ?string
    {
        return $this->discount > 0 ? '−'.Money::format($this->discount) : null;
    }

    /**
     * 入れたクーポンが効いている（コードを入れていて、使えない理由が無い）
     */
    public function couponApplied(): bool
    {
        return $this->couponCode !== null && $this->couponNotice === null;
    }

    /**
     * 注文の最終確認に出す、入れたコードを使わない知らせ（無ければ null）。使えない理由（期限・回数など）には「（クーポンは使わずにご注文になります）」を添え、
     * もともと「クーポンは使っていません」と言い切る知らせ（自動の割引が効いている・合計が下がらない）はそのまま（同じことを 2 度言わない。ADR 0065）
     */
    public function couponNoticeForConfirm(): ?string
    {
        if ($this->couponNotice === null) {
            return null;
        }

        return AutomaticDiscounts::isSkipNotice($this->couponNotice) ? $this->couponNotice : $this->couponNotice.'（クーポンは適用されずにご注文となります）';
    }

    /**
     * 値引き（クーポンか自動の割引）が効いている。送料無料の値引きは discount が 0 のまま効く
     */
    public function discountApplied(): bool
    {
        return $this->discountId !== null;
    }

    /**
     * 「あと ¥3,000 で送料無料」（当てはまらなければ null）
     */
    public function freeShippingNote(): ?string
    {
        if ($this->freeShippingRemaining === null) {
            return null;
        }

        $name = $this->shippingQuote?->remainingForFree()['name'] ?? null;

        return 'あと '.Money::format($this->freeShippingRemaining).' で'.($name === null ? '' : $name.'の').'送料無料';
    }

    /**
     * 税率ごとの対象の額と消費税額（ADR 0063。「8% 対象 ¥1,080（うち消費税 ¥80）」）。注文の確定と同じ計算
     */
    public function taxBreakdown(): TaxBreakdown
    {
        return TaxBreakdown::ofAmounts($this->taxAmounts);
    }

    public function subtotalLabel(): string
    {
        return Money::format($this->subtotal);
    }

    /**
     * 送る品があるか（今買える行に、送らない品〔ダウンロードなど。〕でない行がある）。無ければ注文の入力でお届け先・贈り物・お届け日時を聞かない
     */
    public function needsShipping(): bool
    {
        foreach ($this->lines as $line) {
            if ($line['purchasable'] && ! ($line['digital'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 送らない品（ダウンロードなど）が入っているか（今買える行で）
     */
    public function hasDigital(): bool
    {
        foreach ($this->lines as $line) {
            if ($line['purchasable'] && ($line['digital'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function hasNotices(): bool
    {
        return array_filter(array_column($this->lines, 'notice')) !== [];
    }

    /**
     * 今は買えない行（販売の終了・売り切れ・在庫より多い数）がある。注文に進む前に、カートで直してもらう
     */
    public function hasUnavailable(): bool
    {
        return in_array(false, array_column($this->lines, 'purchasable'), true);
    }

    /**
     * 商品の合計が、店の決めた 1 回のご注文の下限に届いていない（ADR 0109）。比べるのは今買える商品の合計（値引き・送料の前）
     */
    public function belowMinimum(): bool
    {
        return $this->count > 0 && $this->subtotal < $this->minimumSubtotal;
    }

    /**
     * 下限に届いていない時の一言（「ご注文は商品の合計 ¥3,000 から承ります（あと ¥1,200）。」）。届いていれば null
     */
    public function minimumNotice(): ?string
    {
        return $this->belowMinimum()
            ? 'ご注文は商品の合計 '.Money::format($this->minimumSubtotal).' から承ります（あと '.Money::format($this->minimumSubtotal - $this->subtotal).'）。'
            : null;
    }

    /**
     * JS の部品に渡す形（数字と文字だけ）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'subtotal' => $this->subtotal,
            'subtotal_label' => $this->subtotalLabel(),
            'shipping_label' => $this->shippingLabel(),
            'total_label' => $this->totalLabel(),
            'free_shipping_note' => $this->freeShippingNote(),
            'shipping_note' => $this->shippingNote(),
            'discount_label' => $this->discountLabel(),
            'discount_name' => $this->discountName,
            'discount_hint' => $this->discountHint,
            'minimum_notice' => $this->minimumNotice(),
            'points_note' => $this->points->earnNote(),
            'coupon_code' => $this->couponCode,
            'coupon_notice' => $this->couponNotice,
            'lines' => array_map(static fn (array $line): array => [
                'line_id' => $line['lineId'],
                'listing_id' => $line['listingId'],
                'title' => $line['title'],
                'url' => $line['card']?->url(),
                'photo' => $line['card']?->photo?->url('medium'),
                'price_label' => $line['card']?->priceLabel(),
                'quantity' => $line['quantity'],
                'max_quantity' => $line['maxQuantity'],
                'line_total_label' => $line['lineTotalLabel'],
                'purchasable' => $line['purchasable'],
                'notice' => $line['notice'],
                'preorder' => $line['card']?->preorderLabel(),
            ], $this->lines),
        ];
    }
}
