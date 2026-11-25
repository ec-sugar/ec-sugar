{{-- カテゴリ「ベント」。最初のカテゴリを大きな正方形、残りを小さな正方形にして詰める（スマホ 2 列・パソコン 4 列）。
     名前は写真の左下に白い札で重ねる --}}
@if ($section['list'] !== [])
    <section aria-labelledby="category-{{ $section->id }}" class="flex flex-col gap-8" data-section="{{ $section->id }}" data-test="categories" data-pattern="bento">
        @include('theme-default::partials.section-title', ['eyebrow' => 'CATEGORY', 'title' => 'カテゴリ', 'id' => 'category-'.$section->id, 'align' => 'start'])
        <ul class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
            @foreach ($section['list'] as $category)
                <li class="{{ $loop->first ? 'col-span-2 row-span-2' : '' }} min-w-0">
                    <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}" class="relative block aspect-square overflow-hidden bg-photo" data-motion-card>
                        <img src="{{ $category['photo']->url($loop->first ? 'large' : 'medium') }}" width="{{ $category['photo']->width }}" height="{{ $category['photo']->height }}" alt=""
                             loading="lazy" decoding="async" class="absolute inset-0 size-full object-cover">
                        <span class="absolute bottom-3 left-3 max-w-[calc(100%-1.5rem)] bg-canvas px-3 py-1.5 text-[13px] font-semibold [overflow-wrap:anywhere] {{ $loop->first ? 'sm:text-base' : '' }}">{{ $category['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
