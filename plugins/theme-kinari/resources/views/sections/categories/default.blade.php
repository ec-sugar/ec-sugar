{{-- カテゴリ「四角」（既定のパターン。区画にする前のトップと同じ見た目）。正方形の写真と名前を、スマホ 3 列・パソコン 6 列で並べる。
     カテゴリが 1 つも無ければ区画を出さない。飛び先の id（#category）はヒーローとメニューのリンクが使う --}}
@if ($section['list'] !== [])
    <section id="category" aria-labelledby="category-{{ $section->id }}" class="mx-auto flex w-full max-w-[1360px] flex-col gap-7 px-5 first:mt-18 sm:px-8" data-section="{{ $section->id }}" data-test="categories" data-pattern="default">
        @include('theme-kinari::partials.section-title', ['eyebrow' => 'CATEGORY', 'title' => 'カテゴリから探す', 'id' => 'category-'.$section->id])
        <ul class="grid grid-cols-3 gap-4 md:grid-cols-6 md:gap-5">
            @foreach ($section['list'] as $category)
                <li class="min-w-0">
                    <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}" class="flex flex-col gap-3 text-center">
                        <img src="{{ $category['photo']->url('medium') }}" width="{{ $category['photo']->width }}" height="{{ $category['photo']->height }}" alt=""
                             loading="lazy" decoding="async" class="aspect-square w-full bg-photo-bg object-cover">
                        <span class="text-sm tracking-[0.06em] [overflow-wrap:anywhere]">{{ $category['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
