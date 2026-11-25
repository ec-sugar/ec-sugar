{{-- カテゴリ「写真のカード」（既定。今までのトップの見た目）: 3 列の縦長の写真に名前を重ねる --}}
@if ($categories = $section['list'])
    <section aria-label="カテゴリから探す" class="fashion-categories" data-test="categories" data-section="{{ $section->id }}">
        @foreach ($categories as $category)
            <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}" class="fashion-category">
                <img src="{{ $category['photo']->url('large') }}" srcset="{{ $category['photo']->srcset() }}" sizes="(min-width: 768px) 33vw, 50vw" width="{{ $category['photo']->width }}" height="{{ $category['photo']->height }}" alt="" loading="lazy" decoding="async">
                <span>{{ $category['label'] }}</span>
            </a>
        @endforeach
    </section>
@endif
