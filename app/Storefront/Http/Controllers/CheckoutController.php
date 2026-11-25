<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Event\Models\DomainEvent;
use App\Core\GiftCard\Models\GiftCard;
use App\Core\GiftCard\Models\OrderGiftCard;
use App\Core\Order\Gifts;
use App\Core\Order\Models\OrderField;
use App\Core\Order\Services\OrderFields;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerAddress;
use App\Core\Party\Services\CustomerAddresses;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Payment\PaymentUnavailableException;
use App\Core\Payment\Services\PaymentMethodSettings;
use App\Core\Payment\Services\PaymentService;
use App\Core\Plugin\AgeCheck\AgeCheckRefused;
use App\Core\Plugin\OrderCharge\ChargeRejected;
use App\Core\Points\Services\PointService;
use App\Core\Points\Services\PointSettings;
use App\Core\Shop\Services\DeliveryDates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Apps\PurchaseData;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartPoints;
use App\Storefront\Cart\CartService;
use App\Storefront\Cart\CartView;
use App\Storefront\Checkout\CheckoutAgeCheck;
use App\Storefront\Checkout\CheckoutCharges;
use App\Storefront\Checkout\CheckoutForm;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Checkout\CheckoutOptIns;
use App\Storefront\Checkout\CheckoutSummary;
use App\Storefront\Checkout\DeliveryOptions;
use App\Storefront\Checkout\GiftCardCodes;
use App\Storefront\Checkout\LastOrder;
use App\Storefront\Checkout\OrderException;
use App\Storefront\Checkout\OrderReceipt;
use App\Storefront\Checkout\PaymentChoices;
use App\Storefront\Checkout\PaymentOffer;
use App\Storefront\Checkout\PlaceOrder;
use App\Storefront\Checkout\UnpaidOrderLimits;
use App\Storefront\Members\AddressView;
use App\Storefront\Members\Member;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

/**
 * 注文の入力と最終確認（会員登録なしで買える）。
 * 入力は確定するまでサーバーのセッション（暗号化して保存）に置く。DB には書かない。確定で取引を作る（PlaceOrder）。
 * 確定したら Stripe の支払いの画面へ送る。支払いの確定は Webhook で、戻ってきた URL は証拠にしない
 */
final class CheckoutController
{
    private const string SESSION = 'checkout';

    private const string ORDER_KEY = 'checkout_order_key';

    private const string LAST_ORDER = LastOrder::SESSION;

    private const string LAST_ORDER_KEY = 'checkout_last_order_key';

    public function __construct(
        private readonly CartService $carts,
        private readonly StorefrontView $view,
        private readonly ShopSettingsService $settings,
        private readonly PaymentService $payments,
        private readonly CustomerAddresses $addresses,
        private readonly Gifts $gifts,
        private readonly PaymentMethodSettings $methods,
        private readonly DeliveryDates $deliveryDates,
        private readonly CheckoutOptIns $optIns,
        private readonly OrderFields $orderFields,
        private readonly PointService $points,
        private readonly PointSettings $pointSettings,
        private readonly PaymentChoices $paymentChoices,
        private readonly GiftCardCodes $giftCardCodes,
        private readonly CheckoutAgeCheck $ageCheck,
        private readonly CheckoutCharges $charges,
        private readonly UnpaidOrderLimits $limits,
    ) {}

    public function form(Request $request): Response|RedirectResponse
    {
        $cart = $this->cart($request);

        if ($cart->count === 0) {
            return $this->backToCart();
        }

        // 店の決めた 1 回のご注文の下限に届いていなければ、カートへ戻す（ADR 0109。確定の時も錠の中で確かめ直す）
        if ($cart->belowMinimum()) {
            return redirect()->route('storefront.cart')->with('cart_error', $cart->minimumNotice());
        }

        $errors = $request->session()->get('errors');
        [$saved, $addresses] = $this->memberDefaults($request, (array) $request->session()->get(self::SESSION, []));

        return $this->view->render('checkout', [
            'cart' => $cart,
            'form' => new CheckoutForm(
                (array) $request->session()->getOldInput(),
                $saved,
                $errors instanceof ViewErrorBag ? $errors : new ViewErrorBag,
                $addresses,
                // 送る品の無い注文（ダウンロードなどだけ）では贈り物とお届け日時の欄を出さない（お届け先の欄はテーマが $cart->needsShipping() で隠す）
                $this->gifts->available() && $cart->needsShipping(),
                // 予約の品のある注文（ADR 0073）は発売日にまとめて送るので、お届け日時の欄を出さない（送料の添え書きにまとめて送る日を出す）
                $cart->preorderShipDate() === null && $cart->needsShipping() ? DeliveryOptions::of($this->deliveryDates->policy()) : null,
                $this->optIns->offer(),
                // 店が足した注文の項目（ADR 0066）。使う欄を並びの順に
                array_map(static fn (OrderField $f): array => [
                    'id' => $f->id, 'name' => $f->name, 'description' => $f->description, 'type' => $f->type->value,
                    'options' => $f->options, 'required' => $f->required, 'maxLength' => $f->type->maxLength(),
                ], $this->orderFields->active()),
                $this->pointsOffer($request),
                // 支払いの選び方（ADR 0074）。入れた入力があれば、その贈り物・お届け日で決める（選べない理由は送った時と確かめの画面でも出す）
                $this->paymentChoices->offer($cart, CheckoutInput::fromArray((array) $request->session()->get(self::SESSION, []))),
                $this->giftCardCodes->inUse((array) $request->session()->get(self::SESSION, [])),
                GiftCard::query()->exists(),
                // 年齢の確かめ（ADR 0077）。年齢の要る品のあるカートだけ。会員が前に確かめた年齢なら生年月日を聞かない
                $this->ageCheck->forForm($request, $cart, (array) $request->session()->get(self::SESSION, [])),
                // App の料金の欄（ラッピング・のしなど。ADR 0078）。選べる商品がカートに無ければ出さない
                $this->charges->form($cart, CheckoutInput::fromArray((array) $request->session()->get(self::SESSION, []))),
            ),
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $cart = $this->cart($request);

        if ($cart->count === 0) {
            return $this->backToCart();
        }

        // 店の決めた 1 回のご注文の下限に届いていなければ、カートへ戻す（ADR 0109。確定の時も錠の中で確かめ直す）
        if ($cart->belowMinimum()) {
            return redirect()->route('storefront.cart')->with('cart_error', $cart->minimumNotice());
        }

        // 形が違えば ValidationException → 入力の画面に戻り、理由と入れた値が出る
        $fields = $request->only(['buyer', 'recipient', 'same_as_buyer', 'gift', 'delivery', 'opt_in', 'fields', 'points', 'payment', 'charges']);

        // 送る品の無い注文（ダウンロードなどだけ）: お届け先・贈り物・お届け日時は使わない（欄を出していないので、送られてきても捨てる。お届け先はご依頼主と同じ）
        if (! $cart->needsShipping()) {
            $fields = ['same_as_buyer' => '1'] + array_diff_key($fields, array_flip(['recipient', 'same_as_buyer', 'gift', 'delivery']));
        }

        $input = CheckoutInput::validate($fields);

        if (! $cart->needsShipping()) {
            $input = $input->withoutShipping();
        }

        // 注文の項目（ADR 0066）: 店の今の決まりで確かめて形をそろえる（使わない欄・知らない欄の値は捨てる）。誤りは欄の下に
        $input = $input->withFields($this->orderFields->check($input->fields));

        // 「お知らせのメールを受け取る」は、その App を使っている店だけ（使っていない店で送られても受け取らない）
        if ($input->optIn && $this->optIns->handler() === null) {
            $input = $input->withoutOptIn();
        }

        // 贈り物は、贈り物の App を使っている店だけ（ADR 0055。使っていない店の画面には欄も出さない）
        if ($input->gift !== null && ! $this->gifts->available()) {
            throw ValidationException::withMessages(['gift.on' => '現在、贈り物として送ることはできません。お届け先を入力してください。']);
        }

        // お届け日時: 店が受けていなければ欄を出していないので、送られてきても使わない。受けていれば今選べる範囲か確かめる。
        // 予約の品のある注文（ADR 0073）も欄を出していないので使わない
        if ($input->delivery !== null) {
            if (! $this->deliveryDates->policy()->offered() || $cart->preorderShipDate() !== null) {
                $input = $input->withDelivery(null);
            } elseif (($problem = $this->deliveryDates->problem($input->delivery)) !== null) {
                throw ValidationException::withMessages($problem);
            }
        }

        // ポイント（ADR 0068）: 会員だけ・使う店だけ（ほかでは欄を出していないので、送られてきても使わない）。数を入れたなら残高まで
        if ($input->pointsMode !== 'none') {
            $account = $this->pointsAccount($request);

            if ($account === null) {
                $input = $input->withoutPoints();
            } elseif (($problem = $this->pointsProblem($input, $account, $this->carts->view($this->carts->find(CartCookie::token($request)), $input->destination(), (string) $input->buyer['email'], $input->pointsRequested()))) !== null) {
                throw ValidationException::withMessages(['points.amount' => $problem]);
            }
        }

        // 年齢の確かめ（ADR 0077）: 生年月日から満年齢をサーバーで数え、足りなければ入力の画面へ。生年月日はここで捨てる（セッションにも置かない）
        try {
            $input = $this->ageCheck->apply($request, $input, $cart, $request->input('age'), CheckoutInput::fromArray((array) $request->session()->get(self::SESSION, []))?->age);
        } catch (AgeCheckRefused $e) {
            return redirect()->route('storefront.cart')->with('cart_error', $e->getMessage());
        }

        // ギフトカード（ADR 0076）: コードを入れたら探して ID だけを持つ（回数の上限つき。コードはセッションにも置かない）。入れなければ前に入れたカードのまま（外すを選んだら外す）
        $input = $this->withGiftCard($request, $input);
        $view = $this->carts->view($this->carts->find(CartCookie::token($request)), $input->destination(), (string) $input->buyer['email'], $input->pointsRequested(), $input->giftCardId);

        // App の料金の欄（ADR 0078）: 出した欄の値だけを残し、App が店の設定で確かめて額を決める（選べない値は欄の下に理由）
        try {
            $input = $this->charges->settle($view, $input);
            [$view] = $this->charges->apply($view, $input);
        } catch (ChargeRejected $e) {
            throw CheckoutCharges::invalid($e);
        }

        // 支払いの選び方（ADR 0074）: 店が使っていて、この注文で選べる手段だけ（代引きは贈り物・予約・デジタルの品・上限の額を超える注文では選べない）。
        // 決済の画面で選ぶ（選び方の無い店の既定）が使えない店（Stripe の鍵が無く、振込だけ・代引きだけ）は、選べる手段で決め直してから置く（代引きの段は App の料金を含めた合計で）
        $input = $this->settlePayment($input, $this->paymentChoices->offer($view, $input));

        // 名前と連絡先を初めてセッションに置くときは、セッションの番号を作り直す（前から持っていた番号を、ほかの人に知られていても〔隣のホストから
        // 置かれた Cookie など〕その番号では入力を読めない。見直しの中 1。中身は持ち越す）
        if (! $request->session()->has(self::SESSION)) {
            $request->session()->regenerate();
        }

        $request->session()->put(self::SESSION, $input->toArray());

        return redirect()->route('storefront.checkout.confirm');
    }

    public function confirm(Request $request): Response|RedirectResponse
    {
        $cart = $this->cart($request);

        if ($cart->count === 0) {
            return $this->backToCart();
        }

        // 店の決めた 1 回のご注文の下限に届いていなければ、カートへ戻す（ADR 0109。確定の時も錠の中で確かめ直す）
        if ($cart->belowMinimum()) {
            return redirect()->route('storefront.cart')->with('cart_error', $cart->minimumNotice());
        }

        // 買えない行が混ざったまま最終確認には進めない（確定の前にカートで直す）
        if ($cart->hasUnavailable()) {
            return redirect()->route('storefront.cart')->with('cart_error', PlaceOrder::UNAVAILABLE);
        }

        $input = CheckoutInput::fromArray((array) $request->session()->get(self::SESSION, []));

        if ($input === null) {
            return redirect()->route('storefront.checkout');
        }

        // 入力の後にカートが送らない品（ダウンロードなど）だけになった: お届け先・贈り物・お届け日時は使わない（確定も同じ決め方）
        if (! $cart->needsShipping()) {
            $input = $input->withoutShipping();
        }

        // 入力の後に贈り物の App を使うのをやめた: お届け先を入れ直してもらう
        if ($input->gift !== null && ! $this->gifts->available()) {
            return redirect()->route('storefront.checkout')->with('cart_error', '現在、贈り物として送ることはできません。お届け先を入力してください。');
        }

        // 予約の品のある注文（ADR 0073）は発売日にまとめて送るので、お届け日時の希望を使わない（入力の後にカートへ予約の品を足した時も。確定も同じ決め方）
        if ($cart->preorderShipDate() !== null) {
            $input = $input->withDelivery(null);
        }

        // 入力の後に日が変わった・店が決まりを変えた: 選んだお届け日時が今は選べなければ、入力の画面で選び直してもらう
        $problem = $input->delivery === null ? null : $this->deliveryDates->problem($input->delivery);
        if ($problem !== null) {
            return redirect()->route('storefront.checkout')->withErrors($problem);
        }

        // 入力の後に店が注文の項目を変えた（必須にした・選択肢を変えた）: 今の決まりに合わなければ、入力の画面で入れ直してもらう（ADR 0066）
        try {
            $fields = $this->orderFields->check($input->fields);
        } catch (ValidationException $e) {
            return redirect()->route('storefront.checkout')->withErrors($e->errors());
        }

        // 送料はお届け先の都道府県（贈り物はいちばん高い額）で確定する（ADR 0064）。指紋もこの額で。
        // 値引きの 1 人の回数は買い手のメールの台帳で見る（確定と同じ決め方。ADR 0065）。ポイントは残高と合計までで決める（確定と同じ。ADR 0068）
        $cart = $this->carts->view($this->carts->find(CartCookie::token($request)), $input->destination(), (string) $input->buyer['email'], $input->pointsRequested(), $input->giftCardId);

        // 入力の後にカートへ年齢の要る品を足した・贈り物の受け取る人の同意が無い: 入力の画面で確かめ直してもらう（ADR 0077。確定も錠の中で照らし直す）
        $ageProblem = $this->ageCheck->problem($request, $input, $cart);
        if ($ageProblem !== null) {
            return redirect()->route('storefront.checkout')->withErrors(['age.consent' => $ageProblem]);
        }

        // 入力の後にギフトカードが使えなくなった（止められた・期限が過ぎた・ほかの注文で使い切った）: 入力の画面で外すか、ほかのカードを入れてもらう（ADR 0076）
        if ($cart->giftCard->problem !== null) {
            return redirect()->route('storefront.checkout')->withErrors(['gift_card.code' => $cart->giftCard->problem]);
        }

        // 入力の後にポイントが減った（ほかの注文で使った・期限で消えた）・合計が変わった: 入れた数が残高を超える・合計が 1〜49 円になるなら、入力の画面で入れ直してもらう（ADR 0068）
        $pointsAccount = $input->pointsMode === 'none' ? null : $this->pointsAccount($request);
        $problem = $pointsAccount === null ? null : $this->pointsProblem($input, $pointsAccount, $cart);
        if ($problem !== null) {
            return redirect()->route('storefront.checkout')->withErrors(['points.amount' => $problem]);
        }

        // App の料金（ADR 0078）: 確定と同じ決め方で額を足す。選べる商品が無くなった・App をやめたなら値を外し（料金の無い合計を見てもらう）、
        // 欄はあるが選んだものが無くなった・選べなくなったなら、入力の画面で選び直してもらう
        if (($settled = $this->charges->settle($cart, $input))->charges !== $input->charges) {
            [$before, $input] = [$input, $settled];
            $request->session()->put(self::SESSION, $input->toArray());

            if (CheckoutCharges::lostChoice($before, $input) && $this->charges->form($cart, $input) !== null) {
                return redirect()->route('storefront.checkout')->withErrors(['charges._form' => CheckoutCharges::CHANGED]);
            }
        }

        try {
            [$cart, $chargeQuote] = $this->charges->apply($cart, $input);
        } catch (ChargeRejected $e) {
            return redirect()->route('storefront.checkout')->withErrors(CheckoutCharges::invalid($e)->errors());
        }
        $terms = $this->settings->orderTerms();
        // 注文の合い言葉（二重送信を防ぐ）。確認の画面を初めて出すときに作り、確定まで同じものを使う
        $key = $request->session()->get(self::ORDER_KEY);

        if (! is_string($key)) {
            $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $request->session()->put(self::ORDER_KEY, $key);
        }

        // 支払いの選び方（ADR 0074）: 入力の後に店が設定を変えた・この注文で選べなくなった（贈り物・上限の額）なら、入力の画面で選び直してもらう。
        // 代引きは手数料を合計に足す（確定〔PlaceOrder〕も同じ決め方で指紋を作る）
        $offer = $this->paymentChoices->offer($cart, $input);

        try {
            $settled = $this->settlePayment($input, $offer);
        } catch (ValidationException $e) {
            return redirect()->route('storefront.checkout')->withErrors($e->errors());
        }

        // 決め直した手段をセッションにも（確定〔PlaceOrder〕はセッションの入力で指紋を作る）
        if ($settled->payment !== $input->payment) {
            $input = $settled;
            $request->session()->put(self::SESSION, $input->toArray());
        }

        // 払ってもらう額が 0 円（合計 0 円・全額をギフトカードで払う）は払うものが無い（確定〔PlaceOrder〕と同じ決め方）
        $payment = $cart->due() === 0 ? CheckoutInput::PAY_STRIPE : $input->payment;
        $cart = $cart->withPaymentFee($payment === 'cod' ? $offer->fee('cod') : 0);

        // 支払いの方法と時期は、店が選んだ手段のうち、この注文の額で使えるものから書く（ADR 0058）。お届け日を選んだ注文にコンビニ払いは出さない（ADR 0059）。
        // 確定（PlaceOrder）も同じ決め方で指紋を作る
        // 額はギフトカードで払う分を引いた、払ってもらう額（ADR 0076）
        // 送らない品（ダウンロードなど）のある注文にもコンビニ払いは出さない（支払いの画面を作る時の決まり〔PaymentService〕と同じ。ADR 0069）
        $methods = $this->methods->forCheckout($cart->due(), false, $input->datedDelivery() || $cart->hasDigital());

        return $this->view->render('checkout-confirm', ['order' => new CheckoutSummary($cart, $input, $terms['delivery'], $terms['returns'], $key,
            // 銀行振込・代引きは Stripe の鍵が無くても受けられる（ADR 0074）
            $payment !== CheckoutInput::PAY_STRIPE || $this->payments->available(),
            $methods, $this->deliveryDates->policy()->offered() && $cart->preorderShipDate() === null,
            konbiniWithheld: ! $methods->has('konbini') && $input->datedDelivery() && $this->methods->forCheckout($cart->due())->has('konbini'),
            orderFields: $this->orderFields->preview($fields), fieldsDigest: $this->orderFields->digest($fields), payment: $payment, bankDays: $offer->bankDays,
            charges: $chargeQuote->summary, chargesDigest: $chargeQuote->fingerprint())]);
    }

    public function place(Request $request, PlaceOrder $orders): RedirectResponse
    {
        $cart = $this->carts->find(CartCookie::token($request));
        $input = CheckoutInput::fromArray((array) $request->session()->get(self::SESSION, []));
        $sessionKey = $request->session()->get(self::ORDER_KEY);
        $key = $request->input('order_key');
        $fingerprint = $request->input('fingerprint');

        // 確定した後に届いた二回目の送信（戻るボタン・二度押し）は、受け付けた画面へ
        $placed = $request->session()->get(self::LAST_ORDER_KEY);
        if (is_string($placed) && is_string($key) && hash_equals($placed, hash('sha256', $key))) {
            return redirect()->route('storefront.checkout.complete');
        }

        if ($cart === null || $input === null) {
            return $this->backToCart();
        }

        // 合い言葉はこのセッションで作ったものだけ（ほかの人の画面の合い言葉では確定できない）
        if (! is_string($sessionKey) || ! is_string($key) || ! hash_equals($sessionKey, $key) || ! is_string($fingerprint)) {
            return redirect()->route('storefront.checkout.confirm')->with('cart_error', '画面の有効期限が切れました。内容を確認して、もう一度お進みください。');
        }

        // 支払いの準備ができていない店では、払えない注文（在庫を押さえたまま）を作らない（銀行振込・代引きは Stripe の鍵が無くても受ける。ADR 0074）
        if ($input->payment === CheckoutInput::PAY_STRIPE && ! $this->payments->available()) {
            return redirect()->route('storefront.checkout.confirm');
        }

        // 準備中のショップで本番の鍵なら、注文を作らない（0 円の注文も。試せるのはテストの鍵だけ。支払いの始まる所でも断る）
        if ($this->payments->liveBeforeOpening()) {
            return redirect()->route('storefront.checkout.confirm')->with('cart_error', PaymentService::NOT_OPEN);
        }

        // まだ払われていない注文の確定は、同じ接続元から 1 時間に決めた回数まで（在庫やクーポンを押さえ続ける・注文のメールを出させ続ける悪用を防ぐ。
        // 0 円の注文は 3 回、払う額のある注文〔Stripe・銀行振込・代引き〕は ）。先に足してから比べる（同時に送っても上限を超えない。確定できなかった試みも数える）。
        // 同じメールの数は、確定できた注文だけを確定の錠の中で数える（PlaceOrder）
        $free = $this->carts->view($cart, $input->destination(), (string) $input->buyer['email'], $input->pointsRequested())->total() === 0;
        $tooMany = $this->limits->hitSource($request->ip(), $free);

        if ($tooMany !== null) {
            return redirect()->route('storefront.checkout.confirm')->with('cart_error', $tooMany);
        }

        try {
            $transaction = $orders->place($cart, $input, $key, $fingerprint);
        } catch (OrderException $e) {
            // 回数の上限で断った回数は、ロールバックの後に数える（錠の中で中央の監査ログを書かない）
            if ($e->limitedBy !== null) {
                $this->limits->refused($e->limitedBy);
            }

            return $e->changed
                ? redirect()->route('storefront.checkout.confirm')->with('cart_error', $e->getMessage())
                : redirect()->route('storefront.cart')->with('cart_error', $e->getMessage());
        }

        $request->session()->forget([self::SESSION, self::ORDER_KEY]);

        // 「お知らせのメールを受け取る」を選んだ人だけ、App にメールを渡す（注文の確定の後で、App が落ちても注文は変わらない）
        $member = $request->user('customer');
        $member = $member instanceof Member && $member->belongsHere() ? $member->account : null;
        $this->optIns->accept($input, $member?->id, $member?->email, $transaction->id);

        $request->session()->put(self::LAST_ORDER, $transaction->id);
        $request->session()->put(self::LAST_ORDER_KEY, hash('sha256', $key));

        return $this->toPayment($transaction);
    }

    /**
     * 受け付けた画面の「お支払いへ進む」。この人が今確定した注文だけ
     */
    public function pay(Request $request): RedirectResponse
    {
        $transaction = $this->lastOrder($request);

        return $transaction === null ? redirect()->route('storefront.home') : $this->toPayment($transaction);
    }

    /**
     * 注文を受け付けた画面。この人が今確定した注文だけ（番号を知っていても、ほかの人の注文は出さない）
     */
    public function complete(Request $request): Response|RedirectResponse
    {
        $transaction = $this->lastOrder($request);

        if ($transaction === null) {
            return redirect()->route('storefront.home');
        }

        $cancelled = $transaction->status === TransactionStatus::Cancelled;
        $receipt = new OrderReceipt(
            $transaction->id,
            (int) $transaction->total_amount,
            $this->payments->isPaid($transaction),
            $request->query('returned') === '1',
            $cancelled,
            ! $cancelled && self::free($transaction),
            $cancelled && DomainEvent::query()->where('aggregate_id', $transaction->id)->where('type', 'payment.order_expired')->exists(),
            // 贈り物（ADR 0055）: この画面には App のタグを差し込むので、受け取りのリンクそのものは渡さない（買う人だけが開ける App の画面で出す）
            $this->gifts->share($transaction->id, private: false),
            // 銀行振込の振込先と期限・代引き（ADR 0074）。この画面は確定した本人だけが見る（App のタグは差し込むが、振込先は店の口座で個人情報ではない）
            $this->payments->state($transaction),
        );

        // 購入の計測の App に渡すのは、取り消していない注文の番号・金額・通貨・品の ID と数だけ（ADR 0034）
        return $this->view->render('checkout-complete', ['receipt' => $receipt], purchase: $cancelled ? null : PurchaseData::of($transaction, $receipt->number()));
    }

    /**
     * Stripe の支払いの画面へ。用意できなければ受け付けた画面に理由を出す（注文は残り、もう一度押せる）
     */
    private function toPayment(Transaction $transaction): RedirectResponse
    {
        // 合計 0 円の注文は確定で済んでいる（支払いの画面は作らない）。銀行振込・代引きの注文も Stripe へは進まない（ADR 0074）
        if (self::free($transaction) || PaymentAttempt::direct($transaction->id) !== null) {
            return redirect()->route('storefront.checkout.complete');
        }

        try {
            $session = $this->payments->startCheckout(
                $transaction,
                route('storefront.checkout.complete', ['returned' => 1]),
                route('storefront.checkout.complete'),
                PlaceOrder::ACTOR,
            );
        } catch (PaymentUnavailableException $e) {
            return redirect()->route('storefront.checkout.complete')->with('cart_error', $e->getMessage());
        }

        return redirect()->away($session->url, 303);
    }

    /**
     * 支払いの選び方を決める（ADR 0074）。選べればそのまま。決済の画面で選ぶ（既定）が使えなければ、選べる最初の手段に決め直す
     * （Stripe の鍵が無く、振込だけ・代引きだけの店）。銀行振込・代引きを選んで選べなければ、理由を payment の誤りで返す
     *
     * @throws ValidationException
     */
    private function settlePayment(CheckoutInput $input, PaymentOffer $offer): CheckoutInput
    {
        if ($offer->allows($input->payment)) {
            return $input;
        }

        if ($input->payment === CheckoutInput::PAY_STRIPE) {
            $resolved = $offer->resolve(CheckoutInput::PAY_STRIPE);

            // Stripe の鍵が無く、銀行振込・代引きを会員だけにした店のゲスト: 選べる手段が無いので、確かめの画面へ進めずに「ログインすると選べる」を欄の下に
            if (! $offer->allows($resolved) && $offer->note !== null) {
                throw ValidationException::withMessages(['payment' => $offer->note]);
            }

            return $input->withPayment($resolved);
        }

        $reason = $offer->reason($input->payment);

        // 入金待ちの数の上限で断った試みは数える（監査ログに回数だけ）
        if ($reason === UnpaidOrderLimits::PENDING) {
            $this->limits->refused(UnpaidOrderLimits::RULE_PENDING);
        }

        throw ValidationException::withMessages(['payment' => $reason ?? 'このお支払い方法は、現在ご利用いただけません。']);
    }

    /**
     * ポイントを使える会員の台帳（ログインした会員で、店がポイントを使っている時だけ。ほかは null）
     */
    private function pointsAccount(Request $request): ?CustomerAccount
    {
        $member = $request->user('customer');

        return $member instanceof Member && $member->belongsHere() && PointService::isMember($member->account) && $this->pointSettings->enabled() ? $member->account : null;
    }

    /**
     * 入れた数が残高を超える・使った後の合計が 1〜49 円になるなら、その理由（全部・使わないは断らない。全部はカートの計算が 50 円残る数に抑える）
     */
    private function pointsProblem(CheckoutInput $input, CustomerAccount $account, CartView $cart): ?string
    {
        if ($input->pointsMode !== 'some') {
            return null;
        }

        $balance = $this->points->balance($account->id);

        if ($input->pointsAmount > $balance) {
            return 'お持ちのポイント（'.number_format($balance).' ポイント）より多くは使えません。';
        }

        return $cart->points->lowered ? CartPoints::BELOW_MINIMUM : null;
    }

    /**
     * 注文の入力の「ポイントを使う」の欄（ADR 0068。核の部品 <x-storefront::points-fields>）。使える会員で残高がある時だけ
     *
     * @return array{balance: int, expiresAt: string|null}|null
     */
    private function pointsOffer(Request $request): ?array
    {
        $account = $this->pointsAccount($request);

        if ($account === null) {
            return null;
        }

        $balance = $this->points->balance($account->id);

        return $balance > 0 ? ['balance' => $balance, 'expiresAt' => $this->points->expiresAt($account->id)?->timezone('Asia/Tokyo')->format('Y/m/d')] : null;
    }

    private function lastOrder(Request $request): ?Transaction
    {
        $id = $request->session()->get(self::LAST_ORDER);

        return is_string($id) ? Transaction::query()->find($id) : null;
    }

    private function cart(Request $request): CartView
    {
        return $this->carts->view($this->carts->find(CartCookie::token($request)));
    }

    private function backToCart(): RedirectResponse
    {
        return redirect()->route('storefront.cart')->with('cart_error', 'カートに、現在ご購入いただける商品がありません。');
    }

    /**
     * 支払いが要らずに確定した注文（払ってもらう額が 0 円〔合計 0 円か、全額をギフトカードで。ADR 0076〕で、合意の段を過ぎた）
     */
    private static function free(Transaction $transaction): bool
    {
        return OrderGiftCard::dueOf($transaction) === 0 && $transaction->phase !== Phase::Agreement;
    }

    /**
     * 入れたギフトカードのコードを入力に写す（ADR 0076）。コードを入れたら探して ID を（見つからない・使えない・回数を超えたら欄の下に理由）。
     * 入れていなければ、前に入れたカードのまま（「使わない」を選んだら外す）
     */
    private function withGiftCard(Request $request, CheckoutInput $input): CheckoutInput
    {
        $code = $request->input('gift_card.code');

        if (is_string($code) && trim($code) !== '') {
            return $input->withGiftCard($this->giftCardCodes->lookup($request->ip(), $code)->id);
        }

        $previous = $request->session()->get(self::SESSION.'.gift_card_id');

        return $input->withGiftCard(filter_var($request->input('gift_card.remove', false), FILTER_VALIDATE_BOOLEAN) || ! is_string($previous) ? null : $previous);
    }

    /**
     * 会員なら（ADR 0044 の 3）: まだ何も入れていなければ、ご依頼主にいつもの届け先と会員のメールを入れる。
     * ?ship_to=<届け先> で選んだ届け先をお届け先に入れる（ほかの会員の届け先の ID は無視）。会員でなければそのまま
     *
     * @param  array<string, mixed>  $saved
     * @return array{0: array<string, mixed>, 1: list<array{id: string, label: string, url: string, selected: bool}>}
     */
    private function memberDefaults(Request $request, array $saved): array
    {
        $member = $request->user('customer');

        if (! $member instanceof Member || ! $member->belongsHere()) {
            return [$saved, []];
        }

        $account = $member->account;
        $list = $this->addresses->list($account);
        $default = $list->first(static fn (CustomerAddress $a): bool => $a->is_default);

        if ($saved === []) {
            $saved = ['buyer' => [...($default->address ?? []), 'email' => $account->email], 'recipient' => null];
        }

        $shipTo = $request->query('ship_to');
        $chosen = is_string($shipTo) ? $this->addresses->find($account, $shipTo) : null;

        if ($chosen !== null) {
            $buyer = (array) ($saved['buyer'] ?? []);
            $sameAsBuyer = AddressView::fields($chosen->address) == AddressView::fields($buyer);
            $saved['recipient'] = $sameAsBuyer ? null : $chosen->address;
        }

        $current = $saved['recipient'] ?? $saved['buyer'] ?? [];

        return [$saved, $list->map(static fn (CustomerAddress $a): array => [
            'id' => $a->id,
            'label' => $a->address['name'].'　'.AddressView::of($a)['summary'],
            'url' => route('storefront.checkout', ['ship_to' => $a->id]),
            'selected' => is_array($current) && AddressView::fields($a->address) == AddressView::fields($current),
        ])->values()->all()];
    }
}
