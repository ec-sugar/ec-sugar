@extends('theme-default::layout')

{{-- テーマ API 1: $cart（CartView）、$form（CheckoutForm: value()・name()・id()・error()・hasErrors()・sameAsBuyer()・prefectures()・giftAvailable()・gift()・delivery()〔お届け日時の選択肢。ADR 0059〕・optInOffer()・optIn()・orderFields()〔店が足した注文の項目。ADR 0066〕・charges()〔App の料金の欄。<x-storefront::charge-fields> が見る。ADR 0078〕）。$cart->needsShipping() が偽（送らない品だけの注文）ならお届け先の欄を出さない --}}

@section('title', 'ご注文の手続き — '.$shop->name)

@section('content')
    <div class="flex flex-col gap-8">
        <ol class="flex gap-3 text-xs text-ink-soft" aria-label="ご注文の手順">
            <li aria-current="step" class="font-semibold text-ink">1 お届け先の入力</li>
            <li aria-hidden="true">›</li>
            <li>2 ご注文の確認</li>
            <li aria-hidden="true">›</li>
            <li>3 お支払い</li>
        </ol>
        <h1 class="text-2xl font-semibold">お届け先の入力</h1>

        @if ($form->hasErrors())
            <p role="alert" class="bg-band px-5 py-3 text-[13px] text-sold" data-test="checkout-errors">入力内容に誤りがあります。各項目の下のメッセージをご確認ください。</p>
        @endif

        <div class="grid grid-cols-1 gap-10 lg:grid-cols-[1fr_320px]">
            {{-- 会員の登録した届け先から選ぶ（核の部品。会員でなければ何も出さない） --}}
            <x-storefront::saved-addresses :form="$form" />
            <form method="post" action="{{ route('storefront.checkout.submit') }}" class="flex flex-col gap-10" novalidate>
                @csrf
                <fieldset class="flex flex-col gap-5">
                    <legend class="mb-4 text-[15px] font-semibold">ご依頼主</legend>
                    @include('theme-default::partials.field', ['key' => 'buyer.email', 'label' => 'メールアドレス', 'type' => 'email', 'autocomplete' => 'email', 'maxlength' => 254, 'hint' => 'ご注文確認メールをお送りします'])
                    @include('theme-default::partials.address-fields', ['prefix' => 'buyer'])
                </fieldset>

                {{-- 贈り物として送る（核の部品。贈り物の App を使う店だけ。選ぶと data-ship-to のお届け先の欄を隠す。ADR 0055） --}}
                <x-storefront::gift-fields :form="$form" />

                {{-- お届け先は送る品のある注文だけ（ダウンロードなど送らない品だけの注文では聞かない） --}}
                @if ($cart->needsShipping())
                    <fieldset class="flex flex-col gap-5" data-ship-to>
                        <legend class="mb-4 text-[15px] font-semibold">お届け先</legend>
                        <label class="flex items-center gap-3 text-sm">
                            <input type="hidden" name="same_as_buyer" value="0">
                            <input type="checkbox" name="same_as_buyer" value="1" class="size-4 accent-accent" @checked($form->sameAsBuyer()) data-same-as-buyer data-test="same-as-buyer">
                            ご依頼主と同じ
                        </label>
                        <div data-recipient-fields>
                            @include('theme-default::partials.address-fields', ['prefix' => 'recipient'])
                        </div>
                    </fieldset>
                @endif

                {{-- お届け日時（核の部品。店が受けるときだけ。選択肢はサーバーが作る。贈り物を選ぶと隠す。ADR 0059） --}}
                <x-storefront::delivery-fields :form="$form" />

                {{-- 注文の項目（核の部品。店が足した欄だけ。のしの有無・ご要望など。ADR 0066） --}}
                <x-storefront::order-fields :form="$form" />

                {{-- ラッピング・のしなど App の料金の欄（核の部品。料金の口を持つ App を使う店で、選べる商品がある時だけ。ADR 0078） --}}
                <x-storefront::charge-fields :form="$form" />
                {{-- 年齢の確認（核の部品。年齢の要る品のある時だけ。生年月日と「20 歳以上です」。ADR 0077） --}}
                <x-storefront::age-check :offer="$form->ageOffer()" />

                {{-- ポイントを使う（核の部品。会員で残高がある時だけ。ADR 0068） --}}
                <x-storefront::points-fields :form="$form" />
                {{-- ギフトカードの欄（核の部品。ADR 0076） --}}
                <x-storefront::gift-card-fields :form="$form" />

                {{-- お支払いの方法（核の部品。銀行振込・代金引換を使う店だけ。ADR 0074） --}}
                <x-storefront::payment-choice :form="$form" />

                {{-- お知らせのメールを受け取る（核の部品。App「メルマガ・一斉メール」を使う店だけ。いつも選んでいない状態から） --}}
                <x-storefront::opt-in :form="$form" />

                <div class="flex flex-wrap items-center justify-between gap-4 border-t border-line pt-6">
                    <a href="{{ route('storefront.cart') }}" class="text-xs underline underline-offset-4">カートに戻る</a>
                    <button type="submit" class="inline-flex h-12 items-center justify-center rounded-xl bg-accent px-10 font-medium text-on-accent hover:bg-accent-hover" data-test="to-confirm">ご注文の確認へ</button>
                </div>
            </form>

            <aside aria-labelledby="summary-title" class="flex flex-col gap-3 self-start bg-band p-6 text-[13px]">
                <h2 id="summary-title" class="font-semibold">ご注文の内容</h2>
                <ul class="flex flex-col gap-2">
                    @foreach ($cart->lines as $line)
                        @if ($line['purchasable'])
                            <li class="flex justify-between gap-3"><span>{{ $line['title'] }} × {{ $line['quantity'] }}</span><span class="tabular">{{ $line['lineTotalLabel'] }}</span></li>
                        @endif
                    @endforeach
                </ul>
                <div class="flex justify-between border-t border-line pt-3"><span>小計</span><span class="tabular">{{ $cart->subtotalLabel() }}</span></div>
                @if ($cart->discountLabel())
                    <div class="flex justify-between" data-test="checkout-discount"><span>{{ $cart->discountName }}</span><span class="tabular">{{ $cart->discountLabel() }}</span></div>
                @endif
                <div class="flex justify-between"><span>送料</span><span class="tabular">{{ $cart->shippingLabel() }}</span></div>
                @if ($cart->shippingNote())
                    <p class="text-xs text-ink-soft" data-test="checkout-shipping-note">{{ $cart->shippingNote() }}</p>
                @endif
                <div class="flex justify-between border-t border-line pt-3 text-[15px] font-semibold"><span>合計</span><span class="tabular">{{ $cart->totalLabel() }}</span></div>
            </aside>
        </div>
    </div>
@endsection
