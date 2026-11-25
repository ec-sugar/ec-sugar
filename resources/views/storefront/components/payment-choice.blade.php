{{--
    お支払いの方法を選ぶ欄（核の部品。ADR 0074）。テーマの注文の入力の画面が、フォームの中のポイントの欄の近くに置く。
    使い方（テーマ）: <x-storefront::payment-choice :form="$form" />
    店が銀行振込か代金引換を使う時だけ出す（カードなどだけの店では何も出さない。今までどおり決済代行の画面で選ぶ）。
    選べない手段（代引きを贈り物・予約・デジタルの品・上限の額を超える注文で）は、選べないまま理由を添えて並べる。選べるかは送った時と確定の時にサーバーが決め直す。
    代引き手数料は今の合計の目安の額。お届け先が決まった確かめの画面で決まる。
    店が銀行振込・代金引換を会員だけにした時（ADR 0085）、ゲストには出さず「会員の方だけ」の添え書きとログインへのリンクだけを出す（選び方が無くても）。
    Stripe の鍵が無い店のゲストには「ログインすると選べる」の添え書き
--}}
@props(['form'])
@if ($form->paymentOffer() !== null)
    <fieldset class="sugar-payment-choice" data-test="payment-choice">
        <legend class="sugar-payment-choice__legend">お支払い方法</legend>
        @foreach ($form->paymentOffer()->options as $option)
            <label class="sugar-payment-choice__option" @unless ($option['available']) aria-disabled="true" @endunless>
                <input type="radio" name="payment" value="{{ $option['value'] }}" @checked($form->payment() === $option['value']) @disabled(! $option['available'])
                    @if ($option['reason']) aria-describedby="payment-{{ $option['value'] }}-reason" @endif data-test="payment-{{ $option['value'] }}">
                <span>{{ $option['label'] }}</span>
                @if ($option['reason'])
                    <span id="payment-{{ $option['value'] }}-reason" class="sugar-payment-choice__reason" data-test="payment-{{ $option['value'] }}-reason">{{ $option['reason'] }}</span>
                @endif
            </label>
        @endforeach
        @if ($form->paymentNote())
            <p class="sugar-payment-choice__note" data-test="payment-note">{{ $form->paymentNote() }} <a href="{{ route('storefront.account.login') }}" data-test="payment-login">ログインする</a></p>
        @endif
        @if ($form->paymentError() && $form->paymentError() !== $form->paymentNote())
            <p class="sugar-payment-choice__error" role="alert" data-test="payment-error">{{ $form->paymentError() }}</p>
        @endif
    </fieldset>
@elseif ($form->paymentNote())
    <div class="sugar-payment-choice" data-test="payment-choice-note">
        <p class="sugar-payment-choice__note" @if ($form->paymentError()) role="alert" @endif data-test="payment-note">{{ $form->paymentNote() }} <a href="{{ route('storefront.account.login') }}" data-test="payment-login">ログインする</a></p>
        @if ($form->paymentError() && $form->paymentError() !== $form->paymentNote())
            <p class="sugar-payment-choice__error" role="alert" data-test="payment-error">{{ $form->paymentError() }}</p>
        @endif
    </div>
@endif
