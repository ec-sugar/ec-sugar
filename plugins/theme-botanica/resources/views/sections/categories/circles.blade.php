{{-- カテゴリ「丸」: 丸く切り取った写真の下に明朝の名前。折り返して真ん中に並べる --}}
@if ($categories = $section['list'])
    <section aria-label="カテゴリから探す" class="botanica-category-circles" data-test="categories" data-pattern="circles" data-section="{{ $section->id }}">
        @foreach ($categories as $category)
            <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}" class="botanica-category-circle" data-motion-card>
                <span class="botanica-category-circle-photo">
                    <img src="{{ $category['photo']->url('medium') }}" srcset="{{ $category['photo']->srcset() }}" sizes="160px" width="{{ $category['photo']->width }}" height="{{ $category['photo']->height }}" alt="" loading="lazy" decoding="async">
                </span>
                <span class="botanica-category-circle-label">{{ $category['label'] }}</span>
            </a>
        @endforeach
    </section>
@endif
