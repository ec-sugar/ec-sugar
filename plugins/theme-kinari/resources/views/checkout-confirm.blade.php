@extends('theme-kinari::layout')

{{-- テーマ API 1: $order（CheckoutSummary: cart・input（buyer・recipient・gift〔贈り物の差出人とメッセージ。ADR 0055〕、shipTo()）・delivery・returns・orderKey・payable、paymentMethod()・paymentTiming()・addressLine()・fingerprint()・unavailableNotice()・deliveryLabel()〔お届け日時のご希望。ADR 0059〕・placeNote()〔確定のボタンの下の一言。ADR 0074〕・payment〔支払いの選び方〕・cart->paymentFeeLabel()〔代引き手数料。ADR 0074〕・orderFields〔注文の項目の値。ADR 0066〕、cart->points〔ポイント。<x-storefront::points-used>・<x-storefront::points-note> に渡す。ADR 0068〕、cart->giftCard〔ギフトカード。<x-storefront::gift-card-used> に渡す。ADR 0076〕、cart〔App の料金の行。<x-storefront::charges-used> に渡す〕・charges〔App の料金の選んだ内容。<x-storefront::charges-summary> に渡す。ADR 0078〕）。
     特定商取引法の最終確認の画面: 分量・支払総額・支払いの方法と時期・引き渡しの時期・返品の条件を、確定のボタンの前にまとめて出す --}}

@section('title', 'ご注文の確認 — '.$shop->name)

@section('content')
    <div class="mx-auto flex max-w-[900px] flex-col gap-8 px-5 pt-10 pb-22 sm:px-8">
        <ol class="flex gap-3 text-xs text-ink-soft" aria-label="ご注文の手順">
            <li>1 お届け先の入力</li>
            <li aria-hidden="true">›</li>
            <li aria-current="step" class="font-semibold text-ink">2 ご注文の確認</li>
            <li aria-hidden="true">›</li>
            <li>3 お支払い</li>
        </ol>
        <h1 class="font-mincho text-[28px] font-medium tracking-[0.06em]">ご注文の確認</h1>
        @if ($flash['error'])
            <p role="alert" class="bg-band px-5 py-3 text-[13px] text-sold" data-test="order-error">{{ $flash['error'] }}</p>
        @endif
        <p class="text-[13px] text-ink-muted">まだご注文は確定していません。内容をご確認のうえ、いちばん下の「注文を確定する」を押してください。</p>

        <section aria-labelledby="items-title" class="flex flex-col gap-4">
            <h2 id="items-title" class="text-[15px] font-semibold">ご注文の商品</h2>
            <ul class="flex flex-col divide-y divide-line border-y border-line">
                @foreach ($order->cart->lines as $line)
                    @if ($line['purchasable'])
                        <li class="flex items-center justify-between gap-4 py-4 text-sm" data-test="confirm-line">
                            <span>{{ $line['title'] }}@if ($line['card']?->reducedTax()) ※@endif</span>
                            <span class="flex items-center gap-6"><span class="text-ink-soft">{{ $line['quantity'] }} 点</span><span class="tabular">{{ $line['lineTotalLabel'] }}</span></span>
                        </li>
                    @endif
                @endforeach
            </ul>
            <dl class="ml-auto flex w-full max-w-md flex-col gap-2 text-sm">
                <div class="flex justify-between"><dt>小計</dt><dd class="tabular">{{ $order->cart->subtotalLabel() }}</dd></div>
                @if ($order->cart->discountLabel())
                    <div class="flex justify-between" data-test="confirm-discount"><dt>{{ $order->cart->discountName }}</dt><dd class="tabular">{{ $order->cart->discountLabel() }}</dd></div>
                @endif
                {{-- 入れたコードを使わない理由（使えない・自動の割引の方が得。ADR 0054・0065） --}}
                @if ($order->cart->couponNoticeForConfirm())
                    <p class="text-xs" data-test="confirm-coupon-notice">{{ $order->cart->couponNoticeForConfirm() }}</p>
                @endif
                <div class="flex justify-between"><dt>送料</dt><dd class="tabular" data-test="confirm-shipping">{{ $order->cart->shippingLabel() }}</dd></div>
                @if ($order->cart->shippingNote())
                    <p class="text-xs text-ink-soft" data-test="confirm-shipping-note">{{ $order->cart->shippingNote() }}</p>
                @endif
                {{-- 使ったポイント（核の部品。ADR 0068） --}}
                <x-storefront::points-used :points="$order->cart->points" />
                {{-- App の料金の行（ラッピング・のしなど。核の部品。ADR 0078） --}}
                <x-storefront::charges-used :cart="$order->cart" />
                {{-- 代引き手数料（代金引換を選んだ時だけ。ADR 0074） --}}
                @if ($order->cart->paymentFeeLabel())
                    <div class="flex justify-between" data-test="confirm-cod-fee"><dt>代引き手数料</dt><dd class="tabular">{{ $order->cart->paymentFeeLabel() }}</dd></div>
                @endif
                <div class="flex items-baseline justify-between gap-6 border-t border-line pt-3 text-[17px] font-semibold"><dt class="whitespace-nowrap">お支払いの合計 <span class="text-xs font-normal text-ink-soft">税込み</span></dt><dd class="tabular" data-test="confirm-total">{{ $order->cart->totalLabel() }}</dd></div>
                {{-- ギフトカードでのお支払いとお支払いの額（核の部品。合計は変えない。ADR 0076） --}}
                <x-storefront::gift-card-used :cart="$order->cart" />
            </dl>
            {{-- 税率ごとの内訳（核の部品。ADR 0063） --}}
            <x-storefront::tax-breakdown :taxes="$order->cart->taxBreakdown()" />
            {{-- 付くポイントの一言（核の部品。ADR 0068） --}}
            <x-storefront::points-note :points="$order->cart->points" />
        </section>

        <section aria-labelledby="address-title" class="grid grid-cols-1 gap-6 border-t border-line pt-8 sm:grid-cols-2">
            <h2 id="address-title" class="sr-only">ご依頼主とお届け先</h2>
            <div class="flex flex-col gap-1 text-sm" data-test="confirm-buyer">
                <h3 class="mb-1 text-[13px] font-semibold">ご依頼主</h3>
                <p>{{ $order->input->buyer['name'] }} 様</p>
                <p>{{ $order->addressLine($order->input->buyer) }}</p>
                <p>{{ $order->input->buyer['phone'] }}</p>
                <p>{{ $order->input->buyer['email'] }}</p>
            </div>
            {{-- お届け先は送る品のある注文だけ --}}
            @if ($order->cart->needsShipping())
                <div class="flex flex-col gap-1 text-sm" data-test="confirm-recipient">
                    <h3 class="mb-1 text-[13px] font-semibold">お届け先</h3>
                    @if ($order->input->gift !== null)
                        {{-- 贈り物（核の部品。住所はお相手が入れる。ADR 0055） --}}
                        <x-storefront::gift-summary :gift="$order->input->gift" />
                    @elseif ($order->input->recipient === null)
                        <p class="text-ink-soft">ご依頼主と同じ</p>
                    @else
                        <p>{{ $order->input->recipient['name'] }} 様</p>
                        <p>{{ $order->addressLine($order->input->recipient) }}</p>
                        <p>{{ $order->input->recipient['phone'] }}</p>
                    @endif
                </div>
            @endif
        </section>

        <section aria-labelledby="terms-title" class="flex flex-col gap-5 border-t border-line pt-8 text-sm">
            <h2 id="terms-title" class="text-[15px] font-semibold">お支払いとお届け</h2>
            <dl class="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-[10rem_1fr]">
                <dt class="text-ink-soft">お支払いの方法</dt><dd>{{ $order->paymentMethod() }}</dd>
                <dt class="text-ink-soft">お支払いの時期</dt><dd>{{ $order->paymentTiming() }}</dd>
                <dt class="text-ink-soft">お届けの目安</dt><dd class="whitespace-pre-line" data-test="confirm-delivery">{{ $order->delivery ?? '「特定商取引法に基づく表記」をご覧ください。' }}</dd>
                <dt class="text-ink-soft">返品・交換</dt><dd class="whitespace-pre-line" data-test="confirm-returns">{{ $order->returns ?? '「特定商取引法に基づく表記」をご覧ください。' }}</dd>
            </dl>
            {{-- お届け日時のご希望（核の部品。店が受けていない・贈り物なら何も出さない。ADR 0059） --}}
            <x-storefront::delivery-summary :label="$order->deliveryLabel()" />
            {{-- 注文の項目の値（核の部品。値のある欄だけ。ADR 0066） --}}
            <x-storefront::order-fields-summary :fields="$order->orderFields" />
            {{-- App の料金の選んだ内容（ラッピング・のしと名入れ。核の部品。ADR 0078） --}}
            <x-storefront::charges-summary :items="$order->charges" />
            {{-- 年齢の確認（核の部品。年齢の要る品のある時だけ。ADR 0077） --}}
            <x-storefront::age-check-summary :answer="$order->input->age" />
            <p class="text-xs text-ink-soft">そのほかの条件は <a href="{{ route('storefront.legal') }}" class="underline underline-offset-4">特定商取引法に基づく表記</a> をご覧ください。</p>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-4 border-t border-line pt-6">
            <a href="{{ route('storefront.checkout') }}" class="text-xs underline underline-offset-4" data-test="back-to-input">入力に戻る</a>
            @if ($order->payable)
                {{-- 確定は 1 回だけ（注文の合い言葉）。確認の画面で見せた内容と変わっていたら、サーバーが確定せずにここへ戻す --}}
                <form method="post" action="{{ route('storefront.checkout.place') }}" class="flex flex-col items-end gap-2">
                    @csrf
                    <input type="hidden" name="order_key" value="{{ $order->orderKey }}">
                    <input type="hidden" name="fingerprint" value="{{ $order->fingerprint() }}">
                    <button type="submit" class="inline-flex h-13 items-center justify-center bg-accent px-10 text-sm tracking-[0.08em] text-on-accent hover:bg-accent-hover" data-test="place-order">注文を確定する</button>
                    <span class="text-xs text-ink-soft" data-test="place-note">{{ $order->placeNote() }}</span>
                </form>
            @else
                {{-- 支払いの準備ができていない店では確定できない（払えない注文で在庫を押さえない） --}}
                <p role="alert" class="bg-band px-5 py-3 text-[13px] text-sold" data-test="payment-unavailable">{{ $order->unavailableNotice() }}</p>
            @endif
        </div>
    </div>
@endsection
