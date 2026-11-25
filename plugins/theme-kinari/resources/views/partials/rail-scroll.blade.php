{{-- 商品の並び「横に流れる」（新着・よく選ばれている商品で共有）。パソコンで 5 点が見え、続きは横にずらして見る（スマホは 2 点と少し）。
     はみ出すのは並びの箱の中だけ（overflow-x-auto）で、ページは横に広がらない。
     $section・$cards（list<ListingCard>）・$eyebrow・$title・$anchor（飛び先の id か null）・$test（data-test の名前） --}}
<section @if ($anchor) id="{{ $anchor }}" @endif aria-labelledby="rail-{{ $section->id }}" class="mx-auto flex w-full max-w-[1360px] min-w-0 flex-col gap-7 px-5 first:mt-18 sm:px-8" data-section="{{ $section->id }}" data-test="{{ $test }}" data-pattern="scroll">
    <div class="flex flex-wrap items-end justify-between gap-4">
        @include('theme-kinari::partials.section-title', ['eyebrow' => $eyebrow, 'title' => $title, 'id' => 'rail-'.$section->id])
        <a href="{{ route('storefront.products') }}" class="inline-flex min-h-11 items-center gap-2 text-[13px]" data-test="see-all">すべて見る @include('theme-kinari::partials.icon', ['name' => 'arrow', 'size' => 16])</a>
    </div>
    @if ($cards === [])
        <p class="bg-band px-6 py-12 text-center text-ink-soft">まだ商品がありません。</p>
    @else
        <ul class="relative -mx-5 flex snap-x snap-mandatory gap-5 overflow-x-auto scroll-px-5 px-5 pb-4 sm:mx-0 sm:scroll-px-0 sm:px-0" aria-labelledby="rail-{{ $section->id }}">
            @foreach ($cards as $listing)
                <li class="w-[44%] shrink-0 snap-start sm:w-[calc((100%-2.5rem)/3)] lg:w-[calc((100%-5rem)/5)]">@include('theme-kinari::partials.product-card', ['listing' => $listing, 'sizes' => '(min-width: 1024px) 20vw, (min-width: 640px) 33vw, 44vw'])</li>
            @endforeach
        </ul>
    @endif
</section>
