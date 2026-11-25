{{-- カテゴリ「カード」（既定のパターン。区画にする前のトップと同じ見た目）。帯の色の地に、写真と名前の白いカード。
     数が揃わなくても真ん中に寄せる（6 つで 1 列）。カテゴリが 1 つも無ければ区画を出さない --}}
@if ($section['list'] !== [])
    <section aria-labelledby="category-{{ $section->id }}" class="flex flex-col items-center gap-7 bg-band px-4 py-14 sm:px-10" data-section="{{ $section->id }}" data-test="categories" data-pattern="default">
        @include('theme-default::partials.section-title', ['eyebrow' => 'CATEGORY', 'title' => 'カテゴリ', 'id' => 'category-'.$section->id])
        <ul class="flex w-full flex-wrap justify-center gap-4">
            @foreach ($section['list'] as $category)
                <li class="w-[calc(50%-0.5rem)] sm:w-[calc((100%-2rem)/3)] lg:w-[calc((100%-5rem)/6)]">
                    <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}" class="flex flex-col bg-canvas">
                        <img src="{{ $category['photo']->url('medium') }}" width="{{ $category['photo']->width }}" height="{{ $category['photo']->height }}" alt=""
                             loading="lazy" decoding="async" class="aspect-square w-full bg-photo object-cover">
                        <span class="px-3.5 py-3 text-sm font-semibold [overflow-wrap:anywhere]">{{ $category['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
