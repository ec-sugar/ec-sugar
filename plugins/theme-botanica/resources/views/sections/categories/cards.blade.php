{{-- カテゴリ「写真のカード」: 3 列の写真に名前を重ねる（狭い画面も 3 列で縦長に） --}}
@if ($categories = $section['list'])
    <section aria-label="カテゴリから探す" class="botanica-categories" data-test="categories" data-pattern="cards" data-section="{{ $section->id }}">
        @foreach ($categories as $category)
            <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}" class="botanica-category" data-motion-card>
                <img src="{{ $category['photo']->url('large') }}" srcset="{{ $category['photo']->srcset() }}" sizes="(min-width: 768px) 33vw, 33vw" width="{{ $category['photo']->width }}" height="{{ $category['photo']->height }}" alt="" loading="lazy" decoding="async">
                <span>{{ $category['label'] }}</span>
            </a>
        @endforeach
    </section>
@endif
