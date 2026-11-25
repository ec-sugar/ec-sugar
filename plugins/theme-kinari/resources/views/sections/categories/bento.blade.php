{{-- カテゴリ「ベント」。最初のカテゴリを大きな縦長、残りを小さな縦長にして詰める（スマホ 2 列・パソコン 4 列）。
     名前は写真の下に生成りの帯で重ねる --}}
@if ($section['list'] !== [])
    <section id="category" aria-labelledby="category-{{ $section->id }}" class="mx-auto flex w-full max-w-[1360px] flex-col gap-7 px-5 first:mt-18 sm:px-8" data-section="{{ $section->id }}" data-test="categories" data-pattern="bento">
        @include('theme-kinari::partials.section-title', ['eyebrow' => 'CATEGORY', 'title' => 'カテゴリから探す', 'id' => 'category-'.$section->id])
        <ul class="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-4">
            @foreach ($section['list'] as $category)
                <li class="{{ $loop->first ? 'col-span-2 row-span-2' : '' }} min-w-0">
                    <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}" class="relative block aspect-[4/5] overflow-hidden bg-photo-bg" data-motion-card>
                        <img src="{{ $category['photo']->url($loop->first ? 'large' : 'medium') }}" width="{{ $category['photo']->width }}" height="{{ $category['photo']->height }}" alt=""
                             loading="lazy" decoding="async" class="absolute inset-0 size-full object-cover">
                        <span class="absolute inset-x-0 bottom-0 bg-canvas/90 px-3 py-2.5 text-center text-sm tracking-[0.06em] [overflow-wrap:anywhere] {{ $loop->first ? 'font-mincho sm:text-lg' : '' }}">{{ $category['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
