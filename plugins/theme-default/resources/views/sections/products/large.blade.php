{{-- 商品の並び「2 列の大」。札を大きく 2 列で 4 点まで（スマホは 1 列）。見出しは左に寄せ、右に「すべて見る」 --}}
<section aria-labelledby="products-{{ $section->id }}" class="flex flex-col gap-9" data-section="{{ $section->id }}" data-test="products" data-pattern="large">
    <div class="flex flex-wrap items-end justify-between gap-4">
        @include('theme-default::partials.section-title', ['eyebrow' => $section['eyebrow'] ?? 'NEW ITEM', 'title' => $section['title'] ?? '新着', 'id' => 'products-'.$section->id, 'align' => 'start'])
        <a href="{{ route('storefront.products') }}" class="inline-flex min-h-11 items-center text-[13px] font-semibold tracking-[0.06em] underline underline-offset-4" data-test="see-all">すべての商品を見る</a>
    </div>
    @if (($cards = array_slice($section['products'] ?: ($listings ?? []), 0, 4)) === [])
        <p class="w-full bg-band px-6 py-12 text-center text-ink-soft">まだ商品がありません。</p>
    @else
        <ul class="grid w-full grid-cols-1 gap-x-6 gap-y-12 sm:grid-cols-2">
            @foreach ($cards as $listing)
                <li class="min-w-0">@include('theme-default::partials.product-card', ['listing' => $listing, 'sizes' => '(min-width: 1200px) 600px, (min-width: 640px) 50vw, 100vw'])</li>
            @endforeach
        </ul>
    @endif
</section>
