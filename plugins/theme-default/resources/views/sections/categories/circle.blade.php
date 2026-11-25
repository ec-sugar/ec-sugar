{{-- カテゴリ「丸」。白い地に、丸く切り取った写真と名前を真ん中に寄せて並べる（スマホ 3 つ・パソコン 6 つで 1 列） --}}
@if ($section['list'] !== [])
    <section aria-labelledby="category-{{ $section->id }}" class="flex flex-col items-center gap-8" data-section="{{ $section->id }}" data-test="categories" data-pattern="circle">
        @include('theme-default::partials.section-title', ['eyebrow' => 'CATEGORY', 'title' => 'カテゴリ', 'id' => 'category-'.$section->id])
        <ul class="flex w-full flex-wrap justify-center gap-x-4 gap-y-7 sm:gap-x-8">
            @foreach ($section['list'] as $category)
                <li class="w-[calc((100%-2rem)/3)] sm:w-32">
                    <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}" class="flex flex-col items-center gap-3 text-center" data-motion-card>
                        <span class="block aspect-square w-full overflow-hidden rounded-full border border-line bg-photo">
                            <img src="{{ $category['photo']->url('medium') }}" width="{{ $category['photo']->width }}" height="{{ $category['photo']->height }}" alt=""
                                 loading="lazy" decoding="async" class="size-full object-cover">
                        </span>
                        <span class="text-[13px] font-semibold [overflow-wrap:anywhere]">{{ $category['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
