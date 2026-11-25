{{-- 商品の並び「横に流れる」。パソコンで 5 点が見え、続きは横にずらして見る（スマホは 2 点と少し）。
     はみ出すのは並びの箱の中だけ（overflow-x-auto）で、ページは横に広がらない --}}
<section aria-labelledby="products-{{ $section->id }}" class="flex min-w-0 flex-col gap-7" data-section="{{ $section->id }}" data-test="products" data-pattern="scroll">
    <div class="flex flex-wrap items-end justify-between gap-4">
        @include('theme-default::partials.section-title', ['eyebrow' => $section['eyebrow'] ?? 'NEW ITEM', 'title' => $section['title'] ?? '新着', 'id' => 'products-'.$section->id, 'align' => 'start'])
        <a href="{{ route('storefront.products') }}" class="inline-flex min-h-11 items-center text-[13px] font-semibold tracking-[0.06em] underline underline-offset-4" data-test="see-all">すべての商品を見る</a>
    </div>
    @if (($cards = $section['products'] ?: array_slice($listings ?? [], 0, 8)) === [])
        <p class="w-full bg-band px-6 py-12 text-center text-ink-soft">まだ商品がありません。</p>
    @else
        <ul class="relative -mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-px-4 px-4 pb-4 sm:mx-0 sm:scroll-px-0 sm:px-0" aria-labelledby="products-{{ $section->id }}">
            @foreach ($cards as $listing)
                <li class="w-[44%] shrink-0 snap-start sm:w-[calc((100%-2rem)/3)] lg:w-[calc((100%-4rem)/5)]">@include('theme-default::partials.product-card', ['listing' => $listing, 'sizes' => '(min-width: 1024px) 20vw, (min-width: 640px) 33vw, 44vw'])</li>
            @endforeach
        </ul>
    @endif
</section>
