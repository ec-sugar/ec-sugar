{{-- 商品の並び「2 列の大」（新着・よく選ばれている商品で共有）。札を大きく 2 列で 4 点まで（スマホは 2 列のまま小さく）。
     $section・$cards（list<ListingCard>）・$eyebrow・$title・$anchor（飛び先の id か null）・$test（data-test の名前） --}}
<section @if ($anchor) id="{{ $anchor }}" @endif aria-labelledby="rail-{{ $section->id }}" class="mx-auto flex w-full max-w-[1360px] flex-col gap-8 px-5 first:mt-18 sm:px-8" data-section="{{ $section->id }}" data-test="{{ $test }}" data-pattern="large">
    <div class="flex flex-wrap items-end justify-between gap-4">
        @include('theme-kinari::partials.section-title', ['eyebrow' => $eyebrow, 'title' => $title, 'id' => 'rail-'.$section->id])
        <a href="{{ route('storefront.products') }}" class="inline-flex min-h-11 items-center gap-2 text-[13px]" data-test="see-all">すべて見る @include('theme-kinari::partials.icon', ['name' => 'arrow', 'size' => 16])</a>
    </div>
    @if (($cards = array_slice($cards, 0, 4)) === [])
        <p class="bg-band px-6 py-12 text-center text-ink-soft">まだ商品がありません。</p>
    @else
        <ul class="grid grid-cols-2 gap-x-4 gap-y-10 sm:gap-x-8">
            @foreach ($cards as $listing)
                <li class="min-w-0">@include('theme-kinari::partials.product-card', ['listing' => $listing, 'sizes' => '(min-width: 1360px) 660px, 50vw'])</li>
            @endforeach
        </ul>
    @endif
</section>
