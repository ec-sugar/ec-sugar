{{-- 商品のカード。$listing（ListingCard。summary は一覧に出す短い説明）。写真が無い商品は角砂糖を出す。
     $sizes（任意）は写真の sizes（区画のパターンで札の幅が変わるとき）
     札の外枠（.sugar-card）の中に、App の押す・戻すのボタン（お気に入りのハートなど。核の部品。リンクの外に置く。ADR 0071） --}}
<div class="sugar-card">
    <a href="{{ $listing->url() }}" class="flex flex-col gap-3 text-ink hover:text-ink" data-test="product-card" data-motion-card>
        <div class="relative flex aspect-square items-center justify-center overflow-hidden bg-photo">
            @if ($listing->photo)
                <img src="{{ $listing->photo->url() }}" srcset="{{ $listing->photo->srcset() }}" sizes="{{ $sizes ?? '(min-width: 1024px) 25vw, (min-width: 768px) 33vw, 50vw' }}"
                     width="{{ $listing->photo->width }}" height="{{ $listing->photo->height }}" alt="{{ $listing->photo->alt }}"
                     loading="lazy" decoding="async" class="size-full object-cover">
            @else
                <span data-test="no-photo">@include('theme-default::partials.cube')</span>
            @endif
            @if ($listing->availability === 'low_stock')
                <span class="absolute top-2.5 left-2.5 bg-canvas px-2.5 py-0.5 text-[11px] font-semibold" aria-hidden="true">残り {{ $listing->remaining }} 点</span>
            @elseif ($listing->availability === 'sold_out')
                <span class="absolute top-2.5 left-2.5 bg-canvas px-2.5 py-0.5 text-[11px] font-semibold text-sold" aria-hidden="true">売り切れ</span>
            @endif
        </div>
        <div class="flex flex-col gap-0.5">
            <span class="text-sm leading-normal font-semibold">{{ $listing->title }}</span>
            @if ($listing->summary)
                <span class="line-clamp-2 text-xs leading-normal text-ink-soft" data-test="product-card-summary">{{ $listing->summary }}</span>
            @endif
            <span class="tabular text-sm">{{ $listing->priceLabel() }} <span class="text-[11px] text-ink-soft">税込</span></span>
            @if ($listing->availability === 'low_stock')
                <span class="sr-only">残り {{ $listing->remaining }} 点</span>
            @elseif ($listing->availability === 'sold_out')
                <span class="sr-only">売り切れ</span>
            @endif
        </div>
    </a>
    <x-storefront::card-options :listing="$listing->id" />
</div>
