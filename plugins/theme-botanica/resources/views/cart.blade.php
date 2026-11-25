@extends('theme-botanica::layout')

{{-- テーマ API 1: $cart（CartView: lines・subtotal・count、subtotalLabel()・discountLabel()・discountName・discountHint・couponCode・isEmpty()・hasNotices()）、$flash（message・error） --}}

@section('title', 'カート — '.$shop->name)

@section('content')
    <div class="mx-auto flex max-w-[1100px] flex-col gap-8 px-5 pt-10 pb-22 sm:px-8" data-cart-page>
        <h1 class="font-mincho text-[28px] font-medium tracking-[0.06em]">カート</h1>

        <p role="status" class="bg-band px-5 py-3 text-[13px] data-[error]:text-sold" data-test="cart-message" data-cart-page-message @if (! $flash['message']) hidden @endif>{{ $flash['message'] }}</p>
        @if ($flash['error'])
            <p role="alert" class="bg-band px-5 py-3 text-[13px] text-sold" data-test="cart-error">{{ $flash['error'] }}</p>
        @endif

        @if ($cart->isEmpty())
            <div class="flex flex-col items-center gap-5 bg-band px-6 py-16 text-center" data-test="cart-empty">
                <p class="text-ink-soft">カートは空です。</p>
                <a href="{{ route('storefront.products') }}" class="inline-flex h-12 items-center bg-accent px-8 text-sm tracking-[0.08em] text-on-accent hover:bg-accent-hover hover:text-on-accent">商品を見る</a>
            </div>
        @else
            <div class="grid grid-cols-1 gap-10 lg:grid-cols-[1fr_320px]">
                <ul class="flex flex-col divide-y divide-line border-y border-line">
                    @foreach ($cart->lines as $line)
                        <li class="group grid grid-cols-[96px_1fr] gap-5 py-6 sm:grid-cols-[120px_1fr]" data-test="cart-line" data-cart-line="{{ $line['lineId'] }}" @if (! $line['purchasable']) data-unavailable @endif>
                            <div class="aspect-[4/5] overflow-hidden bg-photo-bg">
                                @if ($line['card']?->photo)
                                    <img src="{{ $line['card']->photo->url('medium') }}" width="{{ $line['card']->photo->width }}" height="{{ $line['card']->photo->height }}" alt=""
                                         loading="lazy" decoding="async" class="size-full object-cover">
                                @endif
                            </div>
                            <div class="flex flex-col gap-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="flex flex-col gap-1">
                                        @if ($line['card'])
                                            <a href="{{ $line['card']->url() }}" class="text-sm">{{ $line['title'] }}</a>
                                            <span class="tabular text-[13px] text-ink-soft">{{ $line['card']->priceLabel() }} <span class="text-[11px]">税込</span></span>
                                            @if ($line['card']->preorder !== null)
                                                {{-- 予約の商品（ADR 0073）: 「予約商品（11月20日ごろ発送）」 --}}
                                                <span class="text-[13px] text-ink-soft" data-test="cart-preorder">{{ $line['card']->preorderLabel() }}</span>
                                            @endif
                                        @else
                                            <span class="text-sm text-ink-soft">{{ $line['title'] }}</span>
                                        @endif
                                    </div>
                                    <span class="tabular text-sm group-data-[unavailable]:text-ink-soft group-data-[unavailable]:line-through" data-field="total">{{ $line['lineTotalLabel'] }}</span>
                                </div>
                                {{-- 注文できない行（data-unavailable）は合計を打ち消す。知らせの欄は無くても置いて隠す（数を変えたとき、核の部品が両方を書き換える） --}}
                                <p class="bg-band px-3 py-2 text-xs text-sold" data-test="cart-notice" data-field="notice" @if (! $line['notice']) hidden @endif>{{ $line['notice'] }}</p>
                                <div class="flex flex-wrap items-center gap-3">
                                    {{-- 数は「− 数 +」。押すと 1 つ減らした・増やした数を送る（JavaScript が無くても動く）。1 個のときの − は押せない（消すのは「外す」） --}}
                                    <form method="post" action="{{ route('storefront.cart.quantity', ['lineId' => $line['lineId']]) }}" class="flex items-center border border-line-strong" data-test="line-stepper" data-cart-line-form>
                                        @csrf
                                        <button type="submit" name="quantity" value="{{ $line['quantity'] - 1 }}" class="flex size-10 items-center justify-center text-lg disabled:text-ink-soft disabled:opacity-40"
                                                aria-label="{{ $line['title'] }}を 1 つ減らす" @disabled($line['quantity'] <= 1) data-test="decrease" data-step-button="-1">−</button>
                                        <span class="tabular w-8 text-center text-sm" aria-live="polite" data-test="line-quantity" data-field="quantity">{{ $line['quantity'] }}</span>
                                        <button type="submit" name="quantity" value="{{ $line['quantity'] + 1 }}" class="flex size-10 items-center justify-center text-lg disabled:text-ink-soft disabled:opacity-40"
                                                aria-label="{{ $line['title'] }}を 1 つ増やす" @disabled($line['quantity'] >= $line['maxQuantity']) data-test="increase" data-step-button="1">+</button>
                                    </form>
                                    <form method="post" action="{{ route('storefront.cart.remove', ['lineId' => $line['lineId']]) }}" data-cart-line-form>
                                        @csrf
                                        <button type="submit" class="h-10 px-2 text-xs text-ink-soft underline underline-offset-4 hover:text-ink" data-test="remove-line">削除</button>
                                    </form>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <aside aria-labelledby="summary-title" class="flex flex-col gap-4 self-start bg-band p-6">
                    <h2 id="summary-title" class="text-[13px] font-semibold">ご注文の内容</h2>
                    <div class="flex items-baseline justify-between">
                        <span class="text-[13px]">小計（<span data-cart-total-count>{{ $cart->count }}</span> 点）</span>
                        <span class="tabular" data-test="cart-subtotal" data-cart-subtotal>{{ $cart->subtotalLabel() }}</span>
                    </div>
                    <div class="flex items-baseline justify-between text-[13px] {{ $cart->discountLabel() ? '' : 'hidden' }}" data-cart-discount-row>
                        <span data-test="cart-discount-name" data-cart-discount-name>{{ $cart->discountName ?? '値引き' }}</span>
                        <span class="tabular" data-test="cart-discount" data-cart-discount>{{ $cart->discountLabel() }}</span>
                    </div>
                    <div class="flex items-baseline justify-between text-[13px]">
                        <span>送料</span>
                        <span class="tabular" data-test="cart-shipping" data-cart-shipping>{{ $cart->shippingLabel() }}</span>
                    </div>
                    <p class="text-xs text-accent-hover {{ $cart->freeShippingNote() ? '' : 'hidden' }}" data-test="free-shipping-note" data-cart-free-note>{{ $cart->freeShippingNote() }}</p>
                    <p class="text-xs text-ink-soft {{ $cart->shippingNote() ? '' : 'hidden' }}" data-test="shipping-note" data-cart-shipping-note>{{ $cart->shippingNote() }}</p>
                    {{-- 次に届く自動の割引の一言（核の部品。無ければ隠す。ADR 0065） --}}
                    <x-storefront::discount-hint :cart="$cart" />
                    {{-- 1 回のご注文の下限に届いていない時の一言（核の部品。ADR 0109） --}}
                    <x-storefront::minimum-notice :cart="$cart" />
                    {{-- 付くポイントの一言（核の部品。ADR 0068） --}}
                    <x-storefront::points-note :points="$cart->points" />
                    <x-storefront::coupon-form :cart="$cart" />
                    <div class="flex items-baseline justify-between border-t border-line pt-4">
                        <span class="text-[13px] font-semibold">合計</span>
                        <span class="tabular text-xl" data-test="cart-total" data-cart-total>{{ $cart->totalLabel() }}</span>
                    </div>
                    <p class="text-xs text-ink-soft">税込み。</p>
                    <a href="{{ route('storefront.checkout') }}" class="inline-flex h-12 items-center justify-center bg-accent px-6 text-sm tracking-[0.08em] text-on-accent hover:bg-accent-hover hover:text-on-accent" data-test="to-checkout">注文へ進む</a>
                    <a href="{{ route('storefront.products') }}" class="text-center text-xs underline underline-offset-4">買い物を続ける</a>
                </aside>
            </div>
        @endif
    </div>
@endsection
