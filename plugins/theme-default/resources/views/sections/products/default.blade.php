{{-- 商品の並び「4 列」（既定のパターン。区画にする前のトップの新着と同じ見た目）。スマホ 2 列・タブレット 3 列・パソコン 4 列。
     店の人が商品を選んでいなければ、トップでは新着（$listings）を 8 点まで並べる（自由なページには $listings が無いので空） --}}
<section aria-labelledby="products-{{ $section->id }}" class="flex flex-col items-center gap-9" data-section="{{ $section->id }}" data-test="products" data-pattern="default">
    @include('theme-default::partials.section-title', ['eyebrow' => $section['eyebrow'] ?? 'NEW ITEM', 'title' => $section['title'] ?? '新着', 'id' => 'products-'.$section->id])
    @if (($cards = $section['products'] ?: array_slice($listings ?? [], 0, 8)) === [])
        <p class="w-full bg-band px-6 py-12 text-center text-ink-soft">まだ商品がありません。</p>
    @else
        <ul class="grid w-full grid-cols-2 gap-x-4 gap-y-10 sm:gap-x-6 md:grid-cols-3 lg:grid-cols-4">
            @foreach ($cards as $listing)
                <li class="min-w-0">@include('theme-default::partials.product-card', ['listing' => $listing])</li>
            @endforeach
        </ul>
        <a href="{{ route('storefront.products') }}" class="inline-flex h-12 items-center border border-ink px-10 text-[13px] font-semibold tracking-[0.06em]" data-test="see-all">すべての商品を見る</a>
    @endif
</section>
