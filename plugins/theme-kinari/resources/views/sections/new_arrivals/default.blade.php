{{-- 新着「左に見出し・4 列」（既定のパターン。区画にする前のトップと同じ見た目）。左の列に見出しと文、右に 4 列で 8 点まで（スマホ 2 列）。
     店の人が商品を選んでいなければ、トップでは新着（$listings）を並べる（自由なページには $listings が無いので空）。
     飛び先の id（#new）はヒーローとフッターのリンクが使う --}}
<section id="new" aria-labelledby="new-{{ $section->id }}" class="mx-auto grid w-full max-w-[1360px] grid-cols-2 gap-x-5 gap-y-10 px-5 first:mt-18 sm:px-8 md:grid-cols-5" data-section="{{ $section->id }}" data-test="new-arrivals" data-pattern="default">
    <div class="col-span-2 flex flex-col gap-3.5 md:col-span-1 md:pr-2">
        @include('theme-kinari::partials.section-title', ['eyebrow' => 'NEW ARRIVALS', 'title' => $section['heading'] ?? '新着', 'id' => 'new-'.$section->id])
        <p class="text-[13px] text-ink-soft">{{ $section['body'] ?? '新しく入った商品です。' }}</p>
        <a href="{{ route('storefront.products') }}" class="inline-flex min-h-11 items-center gap-2 text-[13px]" data-test="see-all">すべて見る @include('theme-kinari::partials.icon', ['name' => 'arrow', 'size' => 16])</a>
    </div>

    @if (($cards = $section['products'] ?: array_slice($listings ?? [], 0, 8)) === [])
        <p class="col-span-2 bg-band px-6 py-12 text-center text-ink-soft md:col-span-4">まだ商品がありません。</p>
    @else
        @foreach ($cards as $listing)
            @include('theme-kinari::partials.product-card', ['listing' => $listing])
            @if ($loop->iteration === 4 && count($cards) > 4)
                <div class="hidden md:block"></div>
            @endif
        @endforeach
    @endif
</section>
