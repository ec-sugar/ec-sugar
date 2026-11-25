{{-- カテゴリ「丸」: 丸く切り取った写真の下に名前。折り返して真ん中に並べる --}}
@if ($categories = $section['list'])
    <section aria-label="カテゴリから探す" class="fashion-section fashion-category-circles" data-test="categories" data-pattern="circles" data-section="{{ $section->id }}">
        @foreach ($categories as $category)
            <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}" class="fashion-category-circle" data-motion-card>
                <span class="fashion-category-circle-photo">
                    <img src="{{ $category['photo']->url('medium') }}" srcset="{{ $category['photo']->srcset() }}" sizes="160px" width="{{ $category['photo']->width }}" height="{{ $category['photo']->height }}" alt="" loading="lazy" decoding="async">
                </span>
                <span class="fashion-category-circle-label">{{ $category['label'] }}</span>
            </a>
        @endforeach
    </section>
@endif
