{{--
    クーポンのコードを入れる欄（ADR 0054）。核の部品で、テーマのカートの画面が置く。JavaScript が無くても送れる。
    使えない理由（期限切れ・最低の購入額など）は、コードを入れたまま下に出す。値引きの額はサーバーが決める。
    自動の割引が効いている・使っても合計が下がらない時は、コードを入れたまま「…クーポンは使っていません。」を出す（ADR 0065。カートが変われば入れ替わる）
--}}
@props(['cart'])
<div class="sugar-coupon" data-test="coupon-form">
    @if ($cart->couponCode !== null)
        <div class="sugar-coupon__applied">
            <span>クーポン <strong data-test="coupon-code">{{ $cart->couponCode }}</strong></span>
            <form method="post" action="{{ route('storefront.cart.coupon.remove') }}">
                @csrf
                <button type="submit" class="sugar-coupon__remove" data-test="coupon-remove">解除</button>
            </form>
        </div>
        @if ($cart->couponNotice !== null)
            <p @class(['sugar-coupon__notice', 'sugar-coupon__notice--info' => \App\Core\Discount\Services\AutomaticDiscounts::isSkipNotice($cart->couponNotice)]) role="status" data-test="coupon-notice">{{ $cart->couponNotice }}</p>
        @endif
    @else
        <form method="post" action="{{ route('storefront.cart.coupon') }}" class="sugar-coupon__form">
            @csrf
            <label for="coupon-code" class="sugar-coupon__label">クーポンコード</label>
            <div class="sugar-coupon__row">
                <input id="coupon-code" name="code" type="text" maxlength="32" autocomplete="off" autocapitalize="characters" spellcheck="false" class="sugar-coupon__input" data-test="coupon-input">
                <button type="submit" class="sugar-coupon__apply" data-test="coupon-apply">適用する</button>
            </div>
        </form>
    @endif
</div>
