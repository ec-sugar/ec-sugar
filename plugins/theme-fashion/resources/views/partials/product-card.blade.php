{{-- 商品のカード。$listing（ListingCard。summary は一覧に出す短い説明）
     札の外枠（.sugar-card）の中に、App の押す・戻すのボタン（お気に入りのハートなど。核の部品。リンクの外に置く。ADR 0071） --}}
<div class="sugar-card">
    <a href="{{ $listing->url() }}" class="fashion-product flex flex-col gap-2.5" data-test="product-card" data-motion-card data-motion-reveal>
        <div class="relative flex aspect-[3/4] items-center justify-center overflow-hidden bg-photo-bg text-[13px] text-ink-soft">
            @if ($listing->photo)
                <img src="{{ $listing->photo->url() }}" srcset="{{ $listing->photo->srcset() }}" sizes="(min-width: 1024px) 20vw, (min-width: 768px) 33vw, 50vw"
                     width="{{ $listing->photo->width }}" height="{{ $listing->photo->height }}" alt="{{ $listing->photo->alt }}"
                     loading="lazy" decoding="async" class="size-full object-cover">
            @else
                <span aria-hidden="true">写真なし</span>
            @endif
            @if ($listing->availability === 'low_stock')
                <span class="absolute top-3 left-3 bg-canvas px-2.5 py-0.5 text-[11px] text-ink">残り {{ $listing->remaining }} 点</span>
            @elseif ($listing->availability === 'sold_out')
                <span class="absolute top-3 left-3 bg-canvas px-2.5 py-0.5 text-[11px] text-sold">売り切れ</span>
            @endif
        </div>
        <div class="flex flex-col gap-1 text-center">
            <span class="text-[13px] leading-normal">{{ $listing->title }}</span>
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
