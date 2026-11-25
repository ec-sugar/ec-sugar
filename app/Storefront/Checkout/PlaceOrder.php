<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Discount\Models\Coupon;
use App\Core\Discount\Services\AutomaticDiscounts;
use App\Core\Discount\Services\CouponRejected;
use App\Core\Discount\Services\CouponService;
use App\Core\Event\EventStore;
use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Services\FulfillmentService;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\GiftCard\Services\GiftCardException;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\ComponentStock;
use App\Core\Inventory\Services\InsufficientStockException;
use App\Core\Listing\Addons\AddonSelection;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Money\Allocation;
use App\Core\Order\AgeChecks;
use App\Core\Order\Gifts;
use App\Core\Order\GiftUnavailable;
use App\Core\Order\OrderCharges;
use App\Core\Order\Preorders;
use App\Core\Order\Services\DirectPayments;
use App\Core\Order\Services\OrderFields;
use App\Core\Order\Services\OrderShippingCharges;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Payment\DirectMethod;
use App\Core\Payment\Events\PaymentConfirmed;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Plugin\AgeCheck\AgeCheckRefused;
use App\Core\Plugin\OrderCharge\ChargeRejected;
use App\Core\Plugin\Preorder\PreorderUnavailable;
use App\Core\Plugin\Services\StockComponents;
use App\Core\Points\Services\PointException;
use App\Core\Points\Services\PointService;
use App\Core\Sample\Demo\DemoOrders;
use App\Core\Shop\Services\DeliveryDates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Tax\TaxClass;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartLine;
use App\Storefront\Cart\CartService;
use App\Storefront\Cart\CartView;
use App\Storefront\Members\Member;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 店頭の注文の確定（ADR 0021: ここから店の台帳に書く）。
 *
 * - 同じ注文の合い言葉（二重送信）なら、最初の注文をそのまま返す。同時に押されてもカートの行のロックで順番になる
 * - 価格はカートを信じず、サーバーの商品から計算し直す。確認の画面で見せた内容（指紋）と違えば確定しない。今は買えない行があればカートへ戻す
 * - 取引（sale）を合意のフェーズで開き、在庫を押さえる（TransactionService）。在庫は拠点ごとに割り当てる
 * - お客さんとお届け先は暗号化した列に入れる（Party・Fulfillment）。台帳のイベントには個人情報を入れない
 * - 注文はお客さんの台帳（メールの完全一致。ADR 0044）につなぐ。注文の時の名前・住所は Party に、その注文の値として残す
 * - 値引き（クーポン・自動の割引・会員価格。1 つの注文に 1 つ）は調整の行（discount）で送料の前に。使った記録は錠の中で照らし直して残す（ADR 0054・0065）。
 *   会員価格（ADR 0079）は使った記録を持たず、効いた印のイベント（order.member_price_applied）を同じトランザクションで残す
 * - 税率は商品の区分から（ADR 0063）。オプションの行は親の商品の率、送料は標準。値引きは税率ごとに分けて行にする（対象の行の率で）
 * - 贈り物（ADR 0055）は、お届け先の無い受渡（受け取る人が後で入れる）で作り、印のイベントと差出人・メッセージ（App が暗号化して持つ）を同じトランザクションで残す。
 *   送料は買う時に、選べるお届け先の中でいちばん高い送料（今は全国一律なので、その額）
 * - お届けの日と時間帯の希望（ADR 0059）は、錠の中で今の時刻と店の決まりで照らし直し、選べなければ確定しない（確認の画面の後に日が変わったなど）。受渡に持たせる。贈り物では持たない
 *   送料は買う時に、選べるお届け先の中でいちばん高い送料（ADR 0064。受け取りの住所で決まる額との差額は受け取りの時に返金する）
 * - 送料は配送の方法の便ごと（ADR 0064）。方法の違う品は受渡を分け、送料の行も便ごとに
 * - 注文の項目（ADR 0066）は、錠の中で今の欄の決まりで照らし直し（確かめの画面の指紋にも決まりと値の要約が入る）、値を暗号化して同じトランザクションで残す。記録には欄の ID だけ
 * - ポイント（ADR 0068）: 使った分は調整の行（points。率ごと、送料の後）で、錠の中で会員の残高を確かめてから引く。付く予定の数と注文の時の率を注文に写す
 * - 銀行振込・代金引換（ADR 0074）: 選べるかを錠の中で店の今の設定とこの注文で決め直す（確かめの画面の指紋に手段・期限の日数・手数料が入る）。
 *   代引き手数料は調整の行（cod_fee。税込み・標準の率）をポイントの後に足す。確定したら店が受け取る支払いを始める（銀行振込は入金待ち、代引きは発送待ち）
 * - ギフトカード（ADR 0076）: 値引きでなく支払いの一部なので行にしない（合計も税も変えない）。錠の中でカードの残高を確かめて引き、注文に使った額を写す。
 *   全額をギフトカードで払う注文は、台帳に「ギフトカードでの受け取り」を書いて支払いを飛ばして確定する
 * - App の料金（ラッピング・のしなど。ADR 0078）: 錠の中で App に見積もり直させ（確かめの画面の指紋に行と選んだ値の要約が入る）、調整の行（App の種類。税込み・標準の率）を
 *   ポイントの後・代引き手数料の前に足す（ポイントでは払わない。ギフトカードは料金を含めた合計に充てる）。選んだ値（名入れなど）は App が暗号化して同じトランザクションで残す
 * - 予約販売（ADR 0073）: 発売日の前の予約の品は在庫を押さえない（拠点の無い行）。予約の数は錠の中で App が数え直す。予約の品があれば、拠点で分けずに便ごとに
 *   1 つの受渡にまとめ、いちばん遅い発売日（発送を始める日）を持たせる。お届けの希望は使わない
 * - 年齢の確かめ（ADR 0077）: 年齢の要る品があれば、錠の中で今の決まりと答え（生年月日で数えた年齢か会員が前に確かめた年齢と同意）を照らし直し、
 *   確かめた事実を同じトランザクションのイベントに残す（生年月日は持たない）
 * - まだ払われていない注文の上限（ADR 0085）: 同じメールの 1 時間の確定の数（確定できた注文だけ）と、銀行振込・代引きの会員だけの設定・
 *   同じメール・同じ接続元の入金待ちの数（支払いの選び方の中）を錠の中で数え直す。接続元の 1 時間の試みの数は確定の経路（CheckoutController）が錠の前に数える。
 *   断った回数の記録は、ロールバックの後に確定の経路が OrderException::limitedBy を見て残す
 * - 確定したらカートを空にする
 */
final class PlaceOrder
{
    public const string ACTOR = 'storefront';

    public const string UNAVAILABLE = 'カートに、現在ご購入いただけない商品があります（売り切れ・在庫不足・販売終了のいずれかです）。カートの内容を変更してから、もう一度お進みください。';

    public function __construct(
        private readonly EventStore $events,
        private readonly CartService $carts,
        private readonly TransactionService $transactions,
        private readonly FulfillmentService $fulfillments,
        private readonly ShopSettingsService $settings,
        private readonly CustomerAccounts $accounts,
        private readonly CouponService $coupons,
        private readonly Gifts $gifts,
        private readonly DeliveryDates $deliveryDates,
        private readonly OrderShippingCharges $shippingCharges,
        private readonly PaymentMethodSettings $paymentMethods,
        private readonly OrderFields $orderFields,
        private readonly PointService $points,
        private readonly PaymentChoices $paymentChoices,
        private readonly DirectPayments $directPayments,
        private readonly GiftCardService $giftCards,
        private readonly Preorders $preorders,
        private readonly AgeChecks $ageChecks,
        private readonly CheckoutCharges $checkoutCharges,
        private readonly OrderCharges $orderCharges,
        private readonly UnpaidOrderLimits $limits,
    ) {}

    public function place(Cart $cart, CheckoutInput $input, string $orderKey, string $fingerprint): Transaction
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $orderKey) !== 1) {
            throw new OrderException('画面の有効期限が切れました。ページを再読み込みして、もう一度お試しください。', changed: true);
        }

        $keyHash = hash('sha256', $orderKey);

        try {
            return DB::transaction(function () use ($cart, $input, $keyHash, $fingerprint): Transaction {
                // 決まりどおり台帳のロックを先に、次にカートの行。同じカートの確定は順番になる（二重送信・同時の押下）
                $this->events->lock();
                Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

                $existing = CheckoutOrder::query()->where('key_hash', $keyHash)->first();

                if ($existing !== null) {
                    return Transaction::query()->findOrFail($existing->transaction_id);
                }

                // デモの店では来訪者ごとに 1 日 5 件まで（ADR 0086。払わずに在庫を押さえ続けさせない。二重送信は上で返すので数えない）
                if (! DemoOrders::admit((string) $input->buyer['email'])) {
                    throw new OrderException(DemoOrders::REFUSED);
                }

                // 送料はお届け先の都道府県（贈り物はいちばん高い額）で決める（ADR 0064）。確かめの画面と同じお届け先なので、同じ指紋になる。
                // 値引き（クーポン・自動の割引）も錠の中で決め直す（ADR 0065。1 人の回数は確かめの画面と同じく買い手のメールの台帳で）
                // ポイント（ADR 0068）も錠の中で残高から決め直す（確かめの画面の後に残高が減っていれば、使える数が変わって指紋が合わない）
                // ギフトカード（ADR 0076）も錠の中で残高から決め直す（確かめの画面の後にほかの注文で使われた・止められたら、額が変わって指紋が合わない）
                $view = $this->carts->view($cart, $input->destination(), (string) $input->buyer['email'], $input->pointsRequested(), $input->giftCardId);

                if ($view->count === 0 || $view->hasUnavailable()) {
                    throw new OrderException(self::UNAVAILABLE);
                }

                // 店の決めた 1 回のご注文の下限（App の決まり。ADR 0109）。錠の中で読み直した額で確かめる
                if ($view->belowMinimum()) {
                    throw new OrderException((string) $view->minimumNotice());
                }

                // 送る品の無い注文（ダウンロードなどだけ）: お届け先・贈り物・お届け日時は使わない（確かめの画面と同じ決め方。指紋もこの入力で）
                if (! $view->needsShipping()) {
                    $input = $input->withoutShipping();
                }

                // 同じメールの 1 時間の確定の数（ADR 0085）: 確定できた注文だけを錠の中で数える（試みで数えると、他人のメールの枠を注文を作らずに使い切れる）。
                // 0 円の注文と払う額のある注文は別に数える（0 円の注文は 3 回）
                $free = $view->total() === 0;
                $tooMany = $this->limits->emailProblem((string) $input->buyer['email'], $free);
                if ($tooMany !== null) {
                    throw new OrderException($tooMany, changed: true, limitedBy: $free ? UnpaidOrderLimits::RULE_FREE : UnpaidOrderLimits::RULE_ORDERS);
                }

                // 予約の品のある注文（ADR 0073）は発売日にまとめて送るので、お届けの希望を使わない（確認の画面と同じ決め方）
                $shipsOn = $view->preorderShipDate();
                if ($shipsOn !== null) {
                    $input = $input->withDelivery(null);
                }

                if ($view->giftCard->problem !== null) {
                    throw new OrderException($view->giftCard->problem.' 入力画面でギフトカードの利用を取りやめるか、内容を確認してから、もう一度お進みください。', changed: true);
                }

                // App の料金（ADR 0078）: 錠の中で店の今の設定から見積もり直す（確かめの画面と同じ決め方。選べなくなった・App をやめたなら確定しない）
                try {
                    [$view, $chargeQuote] = $this->checkoutCharges->apply($view, $input);
                } catch (ChargeRejected $e) {
                    throw new OrderException(in_array(CheckoutCharges::BELOW_MINIMUM, $e->errors, true) ? CheckoutCharges::BELOW_MINIMUM
                        : 'お選びのラッピング・のしなどの内容が変わりました。内容を確認して、もう一度「注文を確定する」を押してください。', changed: true);
                }

                // 支払いの選び方（ADR 0074）: 錠の中で店の今の設定とこの注文で選べるかを決め直す。代引きは手数料を合計に足す（確かめの画面と同じ決め方）。
                // 払ってもらう額が 0 円（合計 0 円・全額をギフトカードで払う。ADR 0076）は払うものが無い。
                // 会員だけ・入金待ちの数の上限（ADR 0085）も同じ決め方の中で。入金待ちは連鎖のロックの中で数えるので、同時の確定でも上限を超えない
                $choice = $view->due() === 0 ? CheckoutInput::PAY_STRIPE : $input->payment;
                $offer = $this->paymentChoices->offer($view, $input);

                if ($choice !== CheckoutInput::PAY_STRIPE && ! $offer->allows($choice)) {
                    // 入金待ちの数の上限で断った回数は、ロールバックの後に確定の経路が数える（錠の中で中央の監査ログを書かない）
                    throw new OrderException(($offer->reason($choice) ?? 'お選びのお支払い方法は、現在ご利用いただけません。').' 入力画面でお支払い方法を選び直してください。', changed: true,
                        limitedBy: $offer->reason($choice) === UnpaidOrderLimits::PENDING ? UnpaidOrderLimits::RULE_PENDING : null);
                }

                $direct = DirectMethod::tryFrom($choice);
                $view = $view->withPaymentFee($direct === DirectMethod::Cod ? $offer->fee($choice) : 0);

                // 価格・数・送料・支払いの手段・お届けの希望のどれかが、確認の画面で見せたものと違う（手段は確認の画面と同じ決め方で。ADR 0058・0059・0074）
                $delivery = $input->deliveryChoice();
                $methods = $this->paymentMethods->forCheckout($view->due(), false, $input->datedDelivery() || $view->hasDigital());

                // 注文の項目（ADR 0066）: 確かめの後に店が欄を必須にした・消した・選択肢を変えたら、確定しない（入力の画面で入れ直してもらう）
                try {
                    $fields = $this->orderFields->check($input->fields);
                } catch (ValidationException) {
                    throw new OrderException('ご注文時の入力項目が変更されました。入力画面で確認してから、もう一度お進みください。', changed: true);
                }

                if (CheckoutSummary::fingerprintOf($view, $methods, $delivery, $this->orderFields->digest($fields), CheckoutSummary::paymentKey($choice, $offer->bankDays, $view->paymentFee), $chargeQuote->fingerprint()) !== $fingerprint) {
                    // 確かめた後にクーポンが使えなくなった（回数の上限・期限・止められた）: 理由を出す（ADR 0054）。
                    // 自動の割引の方が得になった・クーポンで合計が下がらなくなった・自動の割引が使えなくなった時は、ふつうの「内容が変わりました」（確かめの画面で新しい値引きを見てもらう）
                    if ($view->couponNotice !== null && ! AutomaticDiscounts::isSkipNotice($view->couponNotice)) {
                        throw new OrderException($view->couponNotice.' カートでクーポンを解除するか、内容を確認してから、もう一度お進みください。', changed: true);
                    }

                    throw new OrderException('ご注文の内容が変わりました（価格・在庫など）。内容を確認して、もう一度「注文を確定する」を押してください。', changed: true);
                }

                $deliveryProblem = $delivery === null ? null : $this->deliveryDates->problem($delivery);
                if ($deliveryProblem !== null) {
                    throw new OrderException(implode(' ', $deliveryProblem), changed: true);
                }

                // 年齢の確かめ（ADR 0077）: 入力の後に年齢の要る品を足した・店が年齢を上げた・App が落ちた時は確定しない（会員の覚えはログインした本人だけ）
                $member = Auth::guard('customer')->user();
                $memberId = $member instanceof Member && $member->belongsHere() ? $member->account->id : null;
                try {
                    $ageRequired = $this->ageChecks->required(array_values(array_map(static fn (array $l): string => (string) $l['listingId'], array_filter($view->lines, static fn (array $l): bool => $l['purchasable']))));
                    $this->ageChecks->guard($ageRequired, $input->age, $memberId, $input->gift !== null, answerRequired: true);
                } catch (AgeCheckRefused $e) {
                    throw new OrderException($e->getMessage(), changed: true);
                }

                $customer = Party::create([
                    'kind' => PartyKind::Customer,
                    'name' => $input->buyer['name'],
                    'email' => $input->buyer['email'],
                    'phone' => $input->buyer['phone'],
                    'address' => self::address($input->buyer),
                ]);

                // お客さんの台帳（ADR 0044）: 買い手のメールの完全一致で 1 人に。無ければ作る（連鎖のロックの中なので、同時の注文でも 1 行）
                $account = $this->accounts->forEmail((string) $input->buyer['email'], self::ACTOR);

                [$seller, $drafts, $placements, $methodOf, $preordered, $digitalPositions] = $this->drafts($cart, $view);
                // 効いた値引き（クーポンか自動の割引か会員価格。1 つの注文に 1 つ。ADR 0065・0079）。会員価格は表に無い（使った記録を持たない）
                $memberPrice = Coupon::isMemberPriceId($view->discountId);
                $coupon = $view->discountId === null || $memberPrice ? null : Coupon::query()->find($view->discountId);
                $allocations = $coupon === null && ! $memberPrice ? [] : $this->addDiscount($drafts, $view, (string) $view->discountName);
                $pointAllocations = $this->addPoints($drafts, $view, $allocations);

                // App の料金（ADR 0078）: ポイントの後に（ポイントでは払わない）。包装などの対価なので、商品の率によらず標準の率。0 円の行も残す（何を選んだかが残る）
                foreach ($view->charges as $charge) {
                    $drafts[] = LineDraft::adjustment($charge['kind'], $charge['title'], $charge['amount'], TaxClass::Standard->rateBp());
                }

                // 代引き手数料（ADR 0074）: ポイントの後に（ポイントでは払わない）。支払いの対価なので標準の率
                if ($view->paymentFee > 0) {
                    $drafts[] = LineDraft::adjustment('cod_fee', '代引き手数料', $view->paymentFee, TaxClass::Standard->rateBp());
                }
                $transaction = $this->transactions->open('sale', $seller, $customer, $drafts, actor: self::ACTOR, customerAccount: $account);

                // 守りの 2 枚目: 作った注文の合計が、確認の画面で見せた合計（指紋に入っている）と同じか。違えば明細の組み立ての誤りなので確定しない
                if ((int) $transaction->total_amount !== $view->total()) {
                    Log::error('注文の合計が確認の画面の合計と違うので確定しなかった', ['cart' => $cart->id, 'order' => (int) $transaction->total_amount, 'shown' => $view->total()]);

                    throw new OrderException('ご注文の内容が変わりました（価格・在庫など）。内容を確認して、もう一度「注文を確定する」を押してください。', changed: true);
                }

                // 予約の品（ADR 0073）: 錠の中で App が商品ごとの予約の数を数え直し（上限・受付の終わり）、注文の予約として残す。印のイベントも同じトランザクションで
                if ($shipsOn !== null) {
                    try {
                        $this->preorders->place($transaction->id, $preordered, $shipsOn, self::ACTOR);
                    } catch (PreorderUnavailable $e) {
                        throw new OrderException($e->getMessage());
                    }
                }

                // 確かめた事実（何歳以上で・どう確かめたか・同意の文の版）を同じトランザクションで。会員が生年月日で確かめた年齢は App が覚える
                $this->ageChecks->record($transaction->id, $ageRequired, $input->age, $memberId, self::ACTOR);

                // クーポン・自動の割引（ADR 0054・0065）: 錠の中で照らし直して使った記録を残す（全体と 1 人の回数。1 人は会員の台帳とメールの台帳の両方で数える。カートと同じ決め方）
                if ($coupon !== null) {
                    $member = Auth::guard('customer')->user();
                    $isMember = $member instanceof Member && $member->belongsHere();

                    try {
                        $this->coupons->redeem($transaction->id, $coupon, CartService::couponLines($view->lines), $this->carts->discountAccounts(null, $account->id), $isMember,
                            $view->discount + $view->waivedShipping, $allocations, $view->waivedShipping, self::ACTOR);
                    } catch (CouponRejected $e) {
                        throw new OrderException($e->getMessage().($coupon->isAutomatic() ? ' 内容を確認してから、もう一度お進みください。' : ' カートでクーポンを解除するか、内容を確認してから、もう一度お進みください。'), changed: true);
                    }
                }

                // 会員価格（ADR 0079）: ランクと率は錠の中で読み直したカートの答え（指紋で確かめ済み）。回数が無いので使った記録は持たず、効いた印と額をイベントに残す
                if ($memberPrice && $view->discount > 0) {
                    $this->events->append('order.member_price_applied', 'transaction', $transaction->id,
                        ['key' => substr((string) $view->discountId, strlen(Coupon::MEMBER_PRICE)), 'amount' => $view->discount, 'allocations' => $allocations], self::ACTOR);
                }

                // ポイント（ADR 0068）: 会員の台帳の錠の中で残高を確かめてから使った分を引き、付く予定の数と注文の時の率を注文に写す
                if ($view->points->member) {
                    $member = Auth::guard('customer')->user();

                    if (! $member instanceof Member || ! $member->belongsHere()) {
                        throw new OrderException('ご注文の内容が変わりました（価格・在庫など）。内容を確認して、もう一度「注文を確定する」を押してください。', changed: true);
                    }

                    try {
                        $this->points->recordOrder($transaction->id, $member->account->id, $view->points->used, $view->points->earnBase, $pointAllocations, self::ACTOR);
                    } catch (PointException $e) {
                        throw new OrderException($e->getMessage().' 入力画面で使うポイント数を確認してから、もう一度お進みください。', changed: true);
                    }
                }

                // ギフトカード（ADR 0076）: カードの行の錠の中で、止めていない・期限の前・残高が足りるかを確かめてから引く
                if ($view->giftCard->applied > 0) {
                    try {
                        $this->giftCards->spend($transaction->id, (string) $view->giftCard->id, $view->giftCard->applied, self::ACTOR, $view->giftCard->liveShop);
                    } catch (GiftCardException $e) {
                        throw new OrderException($e->getMessage().' 入力画面でギフトカードを確認してから、もう一度お進みください。', changed: true);
                    }
                }

                // 注文の項目の値（ADR 0066。暗号化して、その時の欄の名前と種類の写しと一緒に）
                $this->orderFields->record($transaction->id, $fields, self::ACTOR);

                // App の料金の選んだ値（ADR 0078。名入れなどは App が暗号化して。記録には ID と種類だけ）
                if (! $chargeQuote->isEmpty()) {
                    $this->orderCharges->record($transaction->id, CheckoutCharges::cartOf($view, $input), $input->charges, self::ACTOR);
                }

                // 便ごとの送料の決め方を残す（贈り物の差額の返金の元。ADR 0064）
                $this->shippingCharges->record($transaction->id, $view->shippingQuote, self::ACTOR);

                // お届け先は拠点 × 配送の方法ごとの受渡に（暗号化した列。方法の違う品は分けて送る。ADR 0064 の 3）。
                // 予約の品のある注文（ADR 0073）は拠点で分けず、便ごとに 1 つにまとめて発売日に送る（拠点は便の中の在庫の品の拠点、無ければ出品者のはじめの拠点）
                $lines = $transaction->lines()->where('line_type', LineType::Item)->orderBy('id')->get();
                $parcels = [];
                // 送らない品（ダウンロードなど）の行は、送る品と分けて 1 つの digital の受渡に（お届け先も拠点も無い）。
                // 役務（設置の代行など）の行は 1 つの in_person の受渡に（店の人が「提供済みにする」で済みにする）
                $digitalItems = [];
                $inPersonItems = [];
                foreach ($lines as $line) {
                    if (isset($digitalPositions[(int) $line->position])) {
                        if ($digitalPositions[(int) $line->position]) {
                            $inPersonItems[(string) $line->id] = (int) $line->quantity;
                        } else {
                            $digitalItems[(string) $line->id] = (int) $line->quantity;
                        }

                        continue;
                    }

                    $method = $methodOf[(int) $line->position] ?? null;
                    $parcel = ($shipsOn === null ? $line->location_id : '').'|'.$method;
                    $parcels[$parcel]['location'] ??= $line->location_id;
                    $parcels[$parcel]['method'] = $method;
                    $parcels[$parcel]['items'][(string) $line->id] = (int) $line->quantity;
                }
                // 役務はほかの品と同じ注文にしない（カートの読み出しが役務の行を止めるので、ここは二重の守りの 2 枚目）
                if ($inPersonItems !== [] && ($parcels !== [] || $digitalItems !== [])) {
                    throw new OrderException(CartService::SERVICES_MIXED, changed: true);
                }
                $firstLocation = null;
                // 贈り物はお届け先を空のまま（受け取る人が入れるまで発送できない。ADR 0055）
                foreach ($parcels as $parcel) {
                    $location = $parcel['location'] === null
                        ? ($firstLocation ??= Location::query()->where('seller_id', $seller->id)->orderBy('created_at')->orderBy('id')->first())
                        : $placements[$parcel['location']];
                    $this->fulfillments->create($transaction, FulfillmentMethod::Shipping, $parcel['items'], $location, $input->gift === null ? self::shipTo($input) : null, self::ACTOR,
                        $delivery, shippingMethod: $parcel['method'], shipsOn: $shipsOn);
                }

                if ($digitalItems !== []) {
                    $this->fulfillments->create($transaction, FulfillmentMethod::Digital, $digitalItems, null, null, self::ACTOR);
                }

                if ($inPersonItems !== []) {
                    $this->fulfillments->create($transaction, FulfillmentMethod::InPerson, $inPersonItems, null, null, self::ACTOR);
                }

                if ($input->gift !== null) {
                    try {
                        $this->gifts->place($transaction->id, $input->gift, self::ACTOR);
                    } catch (GiftUnavailable $e) {
                        throw new OrderException($e->getMessage(), changed: true);
                    }
                }

                // 注文には接続元とメールの印（店の鍵の HMAC）を付ける。入金待ち（銀行振込・代引き・コンビニ払い）とメールの 1 時間の確定の数を数えるためだけ
                // （ADR 0085。入金待ちとして数える日数の後に見回りが消す）
                CheckoutOrder::create(['key_hash' => $keyHash, 'transaction_id' => $transaction->id] + $this->limits->marks((string) $input->buyer['email'])
                    + ($direct === null ? [] : ['pays_with' => $direct->value]));
                CartLine::query()->where('cart_id', $cart->id)->delete();
                Cart::query()->whereKey($cart->id)->update(['coupon_code' => null]);

                // 合計 0 円（無料の品で送料も無い）: 払うものが無いので、支払いを飛ばして確定する（発送待ち）。お金は動かないので台帳に書かない
                // 合計が負の注文は作らない（将来の値引きの行への備え）
                if ((int) $transaction->total_amount < 0) {
                    Log::error('合計が負の注文を断った', ['cart' => $cart->id]);

                    throw new OrderException(self::UNAVAILABLE);
                }

                // 0 円の品を売る店だけ。ふつうは手前でカートの読み出し（Catalog）が同じ設定を見て断るので、ここは二重の守りの 2 枚目（テストでは単独で見張れない）。
                // クーポンの値引きで 0 円になった注文は、値段のある品なので通す（ADR 0054。0 円の注文の回数の上限は注文の入力の経路が掛ける）
                // ポイントで 0 円になった注文も同じ（ADR 0068）
                if ((int) $transaction->total_amount === 0 && ! $this->settings->sellsFreeItems() && $view->discount === 0 && $view->points->used === 0) {
                    throw new OrderException(self::UNAVAILABLE);
                }

                // 全額をギフトカードで払う注文（ADR 0076）も払うものが無い: ギフトカードでの受け取りを台帳に書いてから確定する。
                // 払ってもらう額は、カードから引いて注文に写した額で決める（確かめの画面の答えでなく、書いた事実で）
                if (OrderGiftCard::dueOf($transaction) === 0) {
                    $this->events->append('payment.not_required', 'transaction', $transaction->id, ['amount' => 0] + ($view->giftCard->applied > 0 ? ['gift_card' => $view->giftCard->applied] : []), self::ACTOR);
                    $this->giftCards->recordPayment($transaction, self::ACTOR);
                    $transaction = $this->transactions->transition($transaction, Phase::Payment, self::ACTOR);
                    // 注文の知らせのメールなどは、確定と同じトランザクションで知らせを記録に残し、コミットの後に届ける（ADR 0089）
                    $this->events->notice(new PaymentConfirmed($transaction->id), self::ACTOR);
                } elseif ($direct !== null) {
                    // 店が受け取る支払い（ADR 0074）: 銀行振込は入金待ち、代引きは払う前に発送できる段へ
                    $this->directPayments->begin($transaction, $direct, self::ACTOR);
                }

                return $transaction;
            });
        } catch (InsufficientStockException) {
            throw new OrderException('在庫が足りなくなった商品があります。カートを確認してください。');
        }
    }

    /**
     * 明細の下書き。数は在庫のある拠点から順に割り当てる。送料は便ごとの調整の行（ADR 0064）。出品者は 1 つだけ（複数の出品者は後で）
     *
     * 予約の品（ADR 0073）は在庫を割り当てず、拠点の無い 1 行にする（在庫を押さえない・発送で減らさない）。
     * 送らない品（ダウンロードなど）も在庫を使わないので、拠点の無い 1 行にする（受渡は送る品と分けた digital）
     *
     * @return array{0: Seller, 1: list<LineDraft>, 2: array<string, Location>, 3: array<int, string|null>, 4: array<string, int>, 5: array<int, bool>} [出品者, 下書き, 拠点, 商品の行の位置 => 配送の方法の名前, 予約の商品 => 数, 送らない品の行の位置 => 役務か]
     */
    private function drafts(Cart $cart, CartView $view): array
    {
        // カートの行 => 送らない品のうち役務か（今買える行だけ。カートの読み直しと同じ答え。役務は受渡を in_person に）
        $digitalLines = [];
        foreach ($view->lines as $viewLine) {
            if ($viewLine['purchasable'] && ($viewLine['digital'] ?? false)) {
                $digitalLines[$viewLine['lineId']] = $viewLine['inPerson'] ?? false;
            }
        }
        $digitalPositions = [];
        // カートの行 => 予約の商品の発売日（今買える予約の行だけ）
        $preorderLines = [];
        foreach ($view->lines as $viewLine) {
            if ($viewLine['purchasable'] && $viewLine['card']?->preorder !== null) {
                $preorderLines[$viewLine['lineId']] = true;
            }
        }
        $preordered = [];
        // カートの行 => 便の方法の名前（受渡を分ける）
        $methodByLine = [];
        foreach ($view->shippingQuote->groups ?? [] as $group) {
            foreach ($group->keys as $key) {
                $methodByLine[$key] = $group->name;
            }
        }
        $methodOf = [];
        $drafts = [];
        $locations = [];
        $seller = null;
        // 同じ種類がオプション違いで何行もあるとき、前の行で割り当てた数を拠点ごとに引く（確保は全部の行の後なので、読み直すだけでは同じ在庫を二度数える）
        $allocated = [];

        foreach (CartLine::query()->where('cart_id', $cart->id)->orderBy('created_at')->orderBy('id')->get() as $line) {
            $listing = Listing::query()->with('seller')->findOrFail($line->listing_id);
            $variant = ListingVariant::query()->whereKey($line->variant_id)->where('listing_id', $listing->id)->firstOrFail();

            if ($seller !== null && $seller->id !== $listing->seller_id) {
                throw new OrderException('複数の販売者の商品は、いっしょに注文できません。');
            }

            $seller = $listing->seller;
            $remaining = $line->quantity;
            $parentPosition = count($drafts) + 1;
            // 税率は商品の区分から（ADR 0063）。オプションの行も同じ率
            $taxRate = $listing->taxRateBp();

            // オプションは今の定義で照らし直す（カートの後に変わっていれば、注文の前に知らせる。額はサーバーで決める。ADR 0036）
            try {
                $addons = AddonSelection::resolve($listing, AddonSelection::fromStored($line->addons)->asInput());
            } catch (\InvalidArgumentException) {
                throw new OrderException('オプションの内容が変わった商品があります。カートを確認してください。');
            }

            // 送らない品は在庫を押さえない。商品の行は拠点の無い 1 行（送料の便にも入れない）
            if (isset($digitalLines[$line->id])) {
                $drafts[] = LineDraft::fromVariant($listing, $variant, $line->quantity, $taxRate);
                $digitalPositions[count($drafts)] = $digitalLines[$line->id];
                $remaining = 0;
            } elseif (isset($preorderLines[$line->id])) {
                // 予約の品は在庫を押さえない（数は App が錠の中で数える）。商品の行は拠点の無い 1 行
                $drafts[] = LineDraft::fromVariant($listing, $variant, $line->quantity, $taxRate);
                $methodOf[count($drafts)] = $methodByLine[$line->id] ?? null;
                $preordered[$listing->id] = ($preordered[$listing->id] ?? 0) + $line->quantity;
                $remaining = 0;
            }

            // 自分の在庫を持たない商品（セット商品）は、構成する品が揃っている拠点から出す。
            // 商品そのものの在庫の行は見ない（店の人が意味のない在庫を入れなくてよい）
            $components = $remaining === 0 ? [] : app(StockComponents::class)->of($listing->id);

            if ($components !== []) {
                // $allocated にはこの注文でもう割り当てた「構成する品」の数が入っているので、二重に数えない
                foreach (app(ComponentStock::class)->buildable($components, $allocated) as $locationId => $buildable) {
                    $take = min($remaining, $buildable);

                    if ($take <= 0) {
                        continue;
                    }

                    foreach ($components as $componentId => $each) {
                        $key = $componentId.'|'.$locationId;
                        $allocated[$key] = ($allocated[$key] ?? 0) + $take * $each;
                    }

                    $location = Location::query()->findOrFail($locationId);
                    $drafts[] = LineDraft::fromVariant($listing, $variant, $take, $taxRate, $location);
                    $methodOf[count($drafts)] = $methodByLine[$line->id] ?? null;
                    $locations[$location->id] = $location;
                    $remaining -= $take;

                    if ($remaining === 0) {
                        break;
                    }
                }
            }

            $stocks = $remaining === 0 || $components !== [] ? [] : InventoryStock::query()->where('variant_id', $variant->id)->whereRaw('quantity - reserved > 0')->orderBy('location_id')->get();

            foreach ($stocks as $stock) {
                $key = $variant->id.'|'.$stock->location_id;
                $take = min($remaining, $stock->available() - ($allocated[$key] ?? 0));

                if ($take <= 0) {
                    continue;
                }

                $allocated[$key] = ($allocated[$key] ?? 0) + $take;
                $location = Location::query()->findOrFail($stock->location_id);
                $drafts[] = LineDraft::fromVariant($listing, $variant, $take, $taxRate, $location);
                $methodOf[count($drafts)] = $methodByLine[$line->id] ?? null;
                $locations[$location->id] = $location;
                $remaining -= $take;

                if ($remaining === 0) {
                    break;
                }
            }

            if ($remaining > 0) {
                throw new InsufficientStockException('在庫が足りません');
            }

            // オプションの行は、数の分をまとめて商品の行に従わせる。入れた文字は名前に入れず、暗号化して持つ
            foreach ($addons->items as $addon) {
                // 0 円の選ぶ・付ける（包装「なし」など）は行にしない。入れる文字は 0 円でも文字を持つので行にする
                if ($addon['amount'] === 0 && $addon['kind'] !== 'text') {
                    continue;
                }

                $label = $addon['kind'] === 'choice' ? $addon['name'].'：'.$addon['value'] : $addon['name'];
                // 税区分のあるオプション（軽減の商品に付ける別料金の包装など）はその率、無ければ商品の率（カートの内訳と同じ。CartService::rateParts）
                $drafts[] = LineDraft::addon($parentPosition, mb_substr($label.'（×'.$line->quantity.'）', 0, 200), $addon['amount'] * $line->quantity, AddonSelection::rateOf($addon, $taxRate),
                    $addon['kind'] === 'text' ? $addon['value'] : null);
            }
        }

        if ($seller === null) {
            throw new OrderException('カートに、現在ご購入いただける商品がありません。');
        }

        // 送料は便ごとの行（無料の便は行にしない。送料無料のクーポンなら全部の便が 0 円）。配送の対価なので、商品の率によらず標準（ADR 0063）
        foreach ($view->shippingQuote->groups ?? [] as $group) {
            if ($group->fee > 0) {
                $drafts[] = LineDraft::adjustment('shipping', $view->shippingQuote->lineTitle($group), $group->fee, TaxClass::SHIPPING->rateBp());
            }
        }

        return [$seller, $drafts, $locations, $methodOf, $preordered, $digitalPositions];
    }

    /**
     * 値引き（クーポン・自動の割引）の行を送料の前に足し、対象の明細（商品とそのオプションの行）へ金額の割合で割り振る（ADR 0054 の 7）。
     * 税率ごとに分ける（ADR 0063）: 値引きをまず対象の額の率ごとの合計で率へ割り振り（カートの確かめの画面と同じ割り振り）、
     * 次に率の中で明細へ割り振る。値引きの行は率ごとに 1 行（名前は同じ。クーポンは「クーポン（CODE）」、自動の割引・会員価格は名前。画面は 1 つにまとめて見せる）
     *
     * @param  list<LineDraft>  $drafts
     * @return array<int, int> 明細の位置 => 値引きの額
     */
    private function addDiscount(array &$drafts, CartView $view, string $title): array
    {
        if ($view->discount === 0) {
            return [];
        }

        // 対象はカートで決めた答えと同じ（値引きの対象の行）。カートの行は商品ごとなので、商品で引く
        $listings = [];
        foreach ($view->lines as $line) {
            if (in_array($line['lineId'], $view->discountKeys, true)) {
                $listings[$line['listingId']] = true;
            }
        }

        /** @var array<int, array<int, int>> $weights 率 => 明細の位置 => 額 */
        $weights = [];
        foreach ($drafts as $index => $draft) {
            $listingId = $draft->listingId ?? ($draft->parentPosition !== null ? $drafts[$draft->parentPosition - 1]->listingId : null);

            if ($listingId !== null && isset($listings[$listingId]) && ($draft->lineType === LineType::Item || $draft->kind === 'addon')) {
                $weights[$draft->taxRateBp][$index + 1] = $draft->amount;
            }
        }

        ksort($weights);
        $byRate = Allocation::byWeight($view->discount, array_map(array_sum(...), $weights));
        $allocations = [];
        $lines = [];

        foreach ($byRate as $rate => $amount) {
            $allocations += Allocation::byWeight($amount, $weights[$rate]);

            if ($amount > 0) {
                $lines[] = LineDraft::adjustment('discount', mb_substr($title, 0, 200), -$amount, $rate);
            }
        }

        ksort($allocations);
        $shipping = array_search(true, array_map(static fn (LineDraft $d): bool => $d->kind === 'shipping', $drafts), true);
        if ($shipping === false) {
            array_push($drafts, ...$lines);
        } else {
            array_splice($drafts, $shipping, 0, $lines);
        }

        return $allocations;
    }

    /**
     * 使ったポイントの行（ADR 0068）を送料の後に足し、明細へ割り振る。行は率ごとに 1 行（「ポイントのご利用」。送料に掛かった分は標準の率の行に入る）。
     * 率の中の商品の分は、商品とオプションの行の値引きの後の額の割合で、送料の分は送料の行の額の割合で配る（端数は大きい順。ADR 0054 の 7 と同じ）。
     * 率ごとの数はカートの計算（CartService::pointsFor）と同じ割り振りなので、確かめの画面の税率ごとの内訳と食い違わない
     *
     * @param  list<LineDraft>  $drafts
     * @param  array<int, int>  $discounts  明細の位置 => 値引きの額（addDiscount の答え）
     * @return array<int, int> 明細の位置 => 使ったポイント
     */
    private function addPoints(array &$drafts, CartView $view, array $discounts): array
    {
        $points = $view->points;

        if ($points->used === 0) {
            return [];
        }

        $shippingRate = TaxClass::SHIPPING->rateBp();
        /** @var array<int, array<int, int>> $goods 率 => 明細の位置 => 値引きの後の額 */
        $goods = [];
        $shipping = [];
        foreach ($drafts as $index => $draft) {
            if ($draft->lineType === LineType::Item || $draft->kind === 'addon') {
                $goods[$draft->taxRateBp][$index + 1] = $draft->amount - ($discounts[$index + 1] ?? 0);
            } elseif ($draft->kind === 'shipping') {
                $shipping[$index + 1] = $draft->amount;
            }
        }

        $allocations = [];
        foreach ($points->byRate as $rate => $amount) {
            $onGoods = $amount - ($rate === $shippingRate ? $points->onShipping : 0);

            if ($onGoods > 0) {
                $allocations += Allocation::byWeight($onGoods, $goods[$rate] ?? []);
            }

            $drafts[] = LineDraft::adjustment('points', 'ポイントのご利用', -$amount, $rate);
        }

        if ($points->onShipping > 0) {
            $allocations += Allocation::byWeight($points->onShipping, $shipping);
        }

        ksort($allocations);

        return array_filter($allocations, static fn (int $v): bool => $v > 0);
    }

    /**
     * @param  array<string, string|null>  $address
     * @return array<string, string|null>
     */
    private static function address(array $address): array
    {
        return ['zip' => $address['postal'], 'prefecture' => $address['prefecture'], 'city' => $address['city'], 'line1' => $address['line1'], 'line2' => $address['line2'] ?? null];
    }

    /**
     * @return array<string, string|null>
     */
    private static function shipTo(CheckoutInput $input): array
    {
        $to = $input->shipTo();

        return ['name' => $to['name'], 'phone' => $to['phone'], ...self::address($to)];
    }
}
