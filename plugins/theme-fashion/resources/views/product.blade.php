@extends('theme-fashion::layout')

{{-- $product（ProductView: card（ListingCard。summary は短い説明）・description・photos（list<ListingPhoto>）・breadcrumbs・url・blocks（説明のブロック）・specs（仕様の表）・options と variants（種類。ADR 0036）・addons（オプション）、summary()・hasDescription()・hasVariants()・hasAddons()）。$purchaseOptions（App の買い方）・$productPanels（App の開いて読む並び） --}}

{{-- 題・説明・canonical・共有の見え方・構造化データは、layout の核の部品（<x-storefront::seo>）が出す（ADR 0061） --}}

@section('content')
    <div class="mx-auto flex max-w-[1360px] flex-col gap-8 px-5 pt-10 pb-22 sm:px-8">
        <nav aria-label="パンくず" class="text-xs text-ink-soft">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('storefront.home') }}">ホーム</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('storefront.products') }}">すべての商品</a></li>
                @foreach ($product->breadcrumbs as $crumb)
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a></li>
                @endforeach
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="text-ink">{{ $product->card->title }}</li>
            </ol>
        </nav>

        <div class="grid grid-cols-1 gap-10 lg:grid-cols-[1.4fr_1fr] lg:gap-16">
            <div class="flex flex-col gap-4" data-test="product-photos">
                @forelse ($product->photos as $photo)
                    <img src="{{ $photo->url('large') }}" srcset="{{ $photo->srcset() }}" sizes="(min-width: 1024px) 55vw, 100vw"
                         width="{{ $photo->width }}" height="{{ $photo->height }}" alt="{{ $photo->alt }}"
                         @if ($loop->first) fetchpriority="high" data-variant-photo @else loading="lazy" @endif decoding="async" class="w-full bg-photo-bg">
                @empty
                    <div class="flex aspect-[4/5] items-center justify-center bg-photo-bg text-[13px] text-ink-soft"><span aria-hidden="true">写真なし</span></div>
                @endforelse
            </div>

            <div class="flex flex-col gap-6 lg:sticky lg:top-8 lg:self-start">
                <div class="flex flex-col gap-3">
                    <h1 class="font-mincho text-[26px] leading-snug font-medium tracking-[0.04em]" data-test="product-title">{{ $product->card->title }}</h1>
                    @if ($product->card->summary)
                        <p class="text-sm text-ink-soft" data-test="product-summary">{{ $product->card->summary }}</p>
                    @endif
                    <p class="tabular text-xl" data-test="product-price"><span data-variant-price>{{ $product->card->priceLabel() }}</span> <span class="text-xs text-ink-soft">税込</span></p>
                    {{-- この商品に今使える自動の割引の名前（核の部品。無ければ何も出さない。ADR 0065） --}}
                    <x-storefront::product-discounts :names="$productDiscounts ?? []" />
                    <x-storefront::product-points :points="$productPoints ?? null" />
                    {{-- 年齢の確かめが要る商品の一言（核の部品。「20 歳未満の方はお求めいただけません」。ADR 0077） --}}
                    <x-storefront::age-notice :age="$productAge ?? null" />
                    <p class="text-[13px] data-[sold-out]:text-sold" data-test="product-availability" data-variant-availability>
                        @if ($product->card->preorder !== null)
                            {{-- 予約の商品（ADR 0073）: 「予約商品（11月20日ごろ発送）」。受付を終えたら「予約の受付を終えました」 --}}
                            <span @class(['text-sold' => $product->card->availability === 'sold_out']) data-test="product-preorder">{{ $product->card->availabilityLabel() }}</span>
                        @elseif ($product->card->availability === 'sold_out')
                            <span class="text-sold">売り切れ</span>
                        @elseif ($product->card->availability === 'low_stock')
                            残り {{ $product->card->remaining }} 点
                        @else
                            在庫あり
                        @endif
                    </p>
                </div>

                @if ($product->maxQuantity > 0)
                    <form method="post" action="{{ route('storefront.cart.add') }}" class="flex flex-col gap-3" data-cart-form data-cart-page="{{ route('storefront.cart') }}">
                        @csrf
                        <input type="hidden" name="listing_id" value="{{ $product->card->id }}">
                        {{-- 種類（色・サイズ）を選ぶのは核の部品（種類の無い商品では何も出さない。見た目は theme.css の [data-variant-picker]。ADR 0036） --}}
                        <x-storefront::variant-picker :product="$product" data-test="variant-picker" />
                        {{-- オプション（名入れ・ギフト包装など）も核の部品（オプションの無い商品では何も出さない。見た目は theme.css の [data-addon-fields]。ADR 0036） --}}
                        <x-storefront::addon-fields :product="$product" data-test="addon-fields" />
                        <div class="flex items-end gap-3">
                            {{-- 数は「− 1 +」。JavaScript が無いときは数を打ち込む --}}
                            <div class="flex h-13 items-center border border-line-strong" data-stepper>
                                <button type="button" class="flex size-12 items-center justify-center text-lg disabled:opacity-40" aria-label="1 つ減らす" data-step="-1" data-test="quantity-decrease">−</button>
                                <input type="number" name="quantity" value="1" min="1" max="{{ $product->maxQuantity }}" inputmode="numeric" aria-label="数量"
                                       class="tabular h-full w-10 bg-transparent text-center text-sm [appearance:textfield] focus:outline-none [&::-webkit-inner-spin-button]:appearance-none" data-test="quantity">
                                <button type="button" class="flex size-12 items-center justify-center text-lg disabled:opacity-40" aria-label="1 つ増やす" data-step="1" data-test="quantity-increase">+</button>
                            </div>
                            <button type="submit" class="inline-flex h-13 flex-1 items-center justify-center bg-accent px-8 text-sm tracking-[0.08em] text-on-accent hover:bg-accent-hover" data-test="add-to-cart">{{ $product->card->addToCartLabel() }}</button>
                        </div>
                    </form>
                    {{-- App の買い方（定期便など。核の部品。使っている App が無ければ何も出さない。ADR 0050） --}}
                    <x-storefront::purchase-options :options="$purchaseOptions ?? []" />
                @else
                    <button type="button" disabled class="inline-flex h-13 items-center justify-center bg-band-strong px-8 text-sm tracking-[0.08em] text-ink-soft">売り切れ</button>
                    {{-- 売り切れの時にも出す App の買い方（「入荷したら知らせる」など。核の部品。印の無い買い方は出さない） --}}
                    <x-storefront::purchase-options :options="$purchaseOptions ?? []" sold-out />
                @endif
                @if ($flash['error'])
                    <p role="alert" class="text-[13px] text-sold" data-test="cart-error">{{ $flash['error'] }}</p>
                @endif

                @if ($product->hasDescription())
                    <section aria-labelledby="description-title" class="flex flex-col gap-3 border-t border-line pt-6">
                        <h2 id="description-title" class="text-[13px] font-semibold">商品の説明</h2>
                        {{-- 説明のブロックは核の部品で描く（見た目は theme.css の [data-blocks]。ADR 0032） --}}
                        <x-storefront::description-blocks :blocks="$product->blocks" class="text-sm leading-loose text-ink-muted" data-test="product-description" />
                    </section>
                @endif
                @if ($product->specs !== [])
                    <section aria-labelledby="specs-title" class="flex flex-col gap-3 border-t border-line pt-6">
                        <h2 id="specs-title" class="text-[13px] font-semibold">仕様</h2>
                        <x-storefront::product-specs :specs="$product->specs" class="text-sm text-ink-muted" data-test="product-specs" />
                    </section>
                @endif
                {{-- App の開いて読む並び（よくある質問など。核の部品。使っている App が出すものが無ければ何も出さない） --}}
                @foreach ($productPanels ?? [] as $panel)
                    <section aria-labelledby="panel-{{ $panel->plugin }}-title" class="flex flex-col gap-3 border-t border-line pt-6">
                        <h2 id="panel-{{ $panel->plugin }}-title" class="text-[13px] font-semibold">{{ $panel->title }}</h2>
                        <x-storefront::product-panel :panel="$panel" class="text-sm text-ink-muted" />
                    </section>
                @endforeach
            </div>
        </div>
    </div>
@endsection
