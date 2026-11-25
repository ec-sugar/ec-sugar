<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Fulfillment\DeliveryChoice;
use App\Core\Money\Money;
use App\Core\Payment\CheckoutMethods;
use App\Core\Payment\Services\PaymentService;
use App\Storefront\Cart\CartView;

/**
 * 注文の最終確認の画面に渡す形（テーマ API の一部）。特商法の最終確認の画面の項目:
 * 分量（商品と数）・支払総額・支払いの方法と時期・引き渡しの時期・申込みの撤回（返品）の条件
 * orderKey は注文の合い言葉（二重送信を防ぐ。確定のフォームに入れる）
 */
final readonly class CheckoutSummary
{
    public function __construct(
        public CartView $cart,
        public CheckoutInput $input,
        public ?string $delivery,
        public ?string $returns,
        public string $orderKey = '',
        public bool $payable = true,
        // 支払いの画面に出す手段（店の設定と、この注文の額で使えるもの。ADR 0058）。渡されなければカードだけ
        public ?CheckoutMethods $methods = null,
        public bool $deliveryOffered = false,
        // 店はコンビニ払いを使うが、お届け日を選んだので出さない（ADR 0058・0059）。「支払いの方法」に理由を添える
        public bool $konbiniWithheld = false,
        // 注文の項目の値（ADR 0066。核の部品 <x-storefront::order-fields-summary> に渡す。値のある欄だけ）と、その決まりと値の要約（指紋に入れる）
        /** @var list<array{name: string, value: string}> */
        public array $orderFields = [],
        public string $fieldsDigest = '',
        // 支払いの選び方（stripe・bank_transfer・cod。ADR 0074）と、銀行振込の払う期限の日数。代引き手数料は cart->paymentFee
        public string $payment = CheckoutInput::PAY_STRIPE,
        public int $bankDays = 7,
        // App の料金の要約（ラッピング・のしなど。ADR 0078。核の部品 <x-storefront::charges-summary> に渡す。入れた本人のセッションなので名入れの文字も出す）と、
        // 選んだ値と使った設定の要約（指紋に入れる。ChargeQuote::fingerprint）。料金の行そのものは cart->chargeLines()
        /** @var list<array{name: string, value: string}> */
        public array $charges = [],
        public string $chargesDigest = '',
    ) {}

    /**
     * 指紋に入れる支払いの選び方（決済代行の画面で選ぶ時は空。銀行振込は期限の日数、代引きは手数料も。ADR 0074）
     */
    public static function paymentKey(string $payment, int $bankDays, int $fee): string
    {
        return match ($payment) {
            'bank_transfer' => 'bank_transfer:'.$bankDays,
            'cod' => 'cod:'.$fee,
            default => '',
        };
    }

    /**
     * 確定のボタンの下の一言（決済代行の画面へ移るか、銀行振込・代引きで受け付けるか）
     */
    public function placeNote(): string
    {
        return match (true) {
            $this->cart->total() === 0 => 'ボタンを押すとご注文が確定します（お支払いは不要です）。',
            $this->payment === 'bank_transfer' => 'ボタンを押すとご注文が確定し、お振込み先をお知らせします。',
            $this->payment === 'cod' => 'ボタンを押すとご注文が確定します。代金は商品のお届け時にお支払いください。',
            default => 'ボタンを押すとご注文が確定し、お支払いの画面（Stripe）へ移動します。',
        };
    }

    /**
     * お届け日時の希望の見せ方（ADR 0059。核の部品 <x-storefront::delivery-summary> に渡す）。
     * 店が受けていて選ばなかったら「指定なし」、受けていない店・贈り物（届く日は受け取る人しだい）は null（出さない）
     */
    public function deliveryLabel(): ?string
    {
        if ($this->input->gift !== null) {
            return null;
        }

        return $this->input->delivery?->label() ?? ($this->deliveryOffered ? '指定なし（最短でお届けします）' : null);
    }

    /**
     * 支払いの準備ができていない店の案内（確定のボタンの代わりに出す）。払えるなら null
     */
    public function unavailableNotice(): ?string
    {
        return $this->payable ? null : PaymentService::UNAVAILABLE;
    }

    /**
     * 確認の画面で見せた内容の指紋（商品・数・金額・送料・合計・支払いの手段・お届けの希望・注文の項目・ポイント）。確定のときに計算し直して違えば、確定しない
     */
    public function fingerprint(): string
    {
        return self::fingerprintOf($this->cart, $this->methods, $this->input->deliveryChoice(), $this->fieldsDigest, self::paymentKey($this->payment, $this->bankDays, $this->cart->paymentFee), $this->chargesDigest);
    }

    /**
     * @param  ?CheckoutMethods  $methods  見せた支払いの手段（渡されなければカードだけ。確認の画面と同じ決め方で渡す）
     * @param  ?DeliveryChoice  $delivery  見せたお届けの希望（指定なし・贈り物は null）
     * @param  string  $fields  注文の項目の決まりと値の要約（OrderFields::digest。欄の無い店は空）
     * @param  string  $payment  銀行振込・代引きを選んだ時の要約（paymentKey。決済代行の画面で選ぶ時は空で、出す手段の並びを入れる）
     * @param  string  $charges  App の料金の要約（ChargeQuote::fingerprint。何も選んでいなければ空）
     */
    public static function fingerprintOf(CartView $cart, ?CheckoutMethods $methods = null, ?DeliveryChoice $delivery = null, string $fields = '', string $payment = '', string $charges = ''): string
    {
        $parts = [];

        foreach ($cart->lines as $line) {
            if ($line['purchasable']) {
                $parts[] = $line['lineId'].':'.$line['quantity'].':'.$line['lineTotal'];
            }
        }

        // 値引き（ADR 0054・0065）: 効いた値引き（クーポンか自動の割引）の ID・名前（確かめた後に名前を直されたら、見せた行と注文の行が食い違う）と額・免じた送料も。確かめた後に条件が変わる・効く割引が入れ替われば確定しない。
        // 税率ごとの内訳（ADR 0063）も: 確かめた後に商品の税率が変われば、見せた内訳と注文が食い違うので確定しない
        // 送料（ADR 0064）: 便ごとの方法と額も。確かめた後に表・商品の方法が変われば確定しない
        // 支払いの手段の並び（ADR 0058）とお届けの希望（ADR 0059）も: 特商法の最終確認で見せた「支払いの方法と時期」「引き渡しの時期」と、確定する注文を食い違わせない
        return hash('sha256', implode('|', $parts).'|'.$cart->shipping.'|'.$cart->total().'|'.($cart->discountId ?? '').':'.($cart->discountName ?? '').':'.$cart->discount.':'.$cart->waivedShipping
            .'|'.$cart->taxBreakdown()->fingerprint().'|'.($cart->shippingQuote?->fingerprint() ?? '')
            // 銀行振込・代引き（ADR 0074）は、選んだ手段と期限の日数・手数料（決済代行の手段の並びの代わり）。代引き手数料は合計と税率ごとの内訳にも入る
            .'|'.($payment === '' ? ($methods ?? CheckoutMethods::cardOnly())->toString() : 'pay:'.$payment).'|'.($delivery === null ? '' : ($delivery->date ?? '').'/'.($delivery->slot->value ?? ''))
            // 注文の項目（ADR 0066）: 見せた値と、その時の欄の決まり。欄の無い店は足さない（指紋は前と同じ）
            .($fields === '' ? '' : '|fields:'.$fields)
            // ポイント（ADR 0068）: 使う数（残高・合計まで）と付く数。確かめた後に残高が減って使える数が変わった・率が変わったら確定しない。使わず付かない注文は足さない
            .($cart->points->used === 0 && $cart->points->earn === 0 ? '' : '|points:'.$cart->points->used.':'.$cart->points->earn.':'.($cart->points->member ? 'm' : 'g'))
            // ギフトカード（ADR 0076）: 使うカードと額。確かめた後にほかの注文で残高が減った・止められたら確定しない。使わない注文は足さない
            .($cart->giftCard->id === null ? '' : '|gift_card:'.$cart->giftCard->id.':'.$cart->giftCard->applied)
            // 予約の品（ADR 0073）: まとめて送る日（引き渡しの時期）。確かめた後に発売日が変わったら確定しない。予約の品の無い注文は足さない
            .(($ship = $cart->preorderShipDate()) === null ? '' : '|preorder:'.$ship)
            // App の料金（ADR 0078）: 行の種類・額・名前と、選んだ値と使った設定の要約。確かめた後に店が値段・選択肢を変えたら確定しない。選んでいない注文は足さない
            .($charges === '' ? '' : '|charges:'.$charges));
    }

    /**
     * 特商法の最終確認の「支払いの方法」。選べる手段を並べる（どれで払うかは、次の決済代行の画面で選ぶ。ADR 0058）
     */
    public function paymentMethod(): string
    {
        // 全額をギフトカードで払う注文（ADR 0076）
        if ($this->cart->total() > 0 && $this->cart->due() === 0) {
            return 'ギフトカード（'.Money::format($this->cart->giftCard->applied).'。ほかのお支払いは不要です）';
        }

        // 一部をギフトカードで払う注文は、残りを決済代行の画面で
        $prefix = $this->cart->giftCard->applied > 0 ? 'ギフトカード（'.Money::format($this->cart->giftCard->applied).'）と、残りの '.Money::format($this->cart->due()).' を ' : '';

        // 合計 0 円の注文は支払いが無い
        if ($this->cart->total() === 0) {
            $by = array_values(array_filter([$this->cart->discount > 0 ? ($this->cart->discountAutomatic ? '割引' : 'クーポン') : null, $this->cart->points->used > 0 ? 'ポイント' : null]));

            return $by !== [] ? 'お支払いは不要です（'.implode('と', $by).'で 0 円のご注文）' : 'お支払いは不要です（無料の商品のご注文）';
        }

        // 銀行振込・代引き（ADR 0074）
        if ($this->payment === 'bank_transfer') {
            return '銀行振込（お振込み先は、ご注文確定後の画面とメールでお知らせします。振込手数料はお客さまのご負担です）';
        }

        if ($this->payment === 'cod') {
            return '代金引換（商品のお届け時に配達員へお支払い。代引き手数料 '.($this->cart->paymentFeeLabel() ?? Money::format(0)).' を含みます）';
        }

        $methods = $this->methods ?? CheckoutMethods::cardOnly();
        $withheld = $this->konbiniWithheld ? '。お届け日をご指定のご注文は、コンビニ払いをお選びいただけません' : '';

        if ($methods->types === ['card']) {
            return $prefix.'クレジットカード（次の決済画面でお支払い'.$withheld.'）';
        }

        $labels = array_map(static fn (string $type): string => $type === 'card' ? 'クレジットカード' : (string) CheckoutMethods::label($type), $methods->types);

        return $prefix.implode('・', $labels).'（次の決済画面でお選びいただけます'.$withheld.'）';
    }

    /**
     * 特商法の最終確認の「支払いの時期」。コンビニ払いは、払込票の期限までにお店で払う
     */
    public function paymentTiming(): string
    {
        if ($this->cart->due() === 0) {
            return 'お支払いはありません。ご注文の確定で受付が完了します。';
        }

        if ($this->payment === 'bank_transfer') {
            return 'ご注文から '.$this->bankDays.' 日後の 23:59 までにお振込みください（期限までにお振込みがないときは、ご注文を取り消します）。ご入金を確認してから発送します。';
        }

        if ($this->payment === 'cod') {
            return '商品のお届け時にお支払いください。';
        }

        $methods = $this->methods ?? CheckoutMethods::cardOnly();

        if (! $methods->has('konbini')) {
            return 'ご注文の確定と同時にお支払いいただきます。';
        }

        return 'コンビニ払いのほかは、ご注文の確定と同時にお支払いいただきます。コンビニ払いは、ご注文から '.$methods->konbiniDays.' 日後の 23:59 までにコンビニでお支払いください（期限までにお支払いがないときは、ご注文を取り消します）。';
    }

    /**
     * @param  array<string, string|null>  $address
     */
    public static function addressLine(array $address): string
    {
        return '〒'.CheckoutInput::postalLabel((string) $address['postal']).' '.$address['prefecture'].$address['city'].$address['line1'].(($address['line2'] ?? null) ? ' '.$address['line2'] : '');
    }
}
