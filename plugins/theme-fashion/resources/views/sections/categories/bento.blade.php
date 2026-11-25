{{-- カテゴリ「ベント」: 最初のカテゴリを大きく、残りを小さく詰めて並べる（広い画面は 4 列、狭い画面は 2 列） --}}
@if ($categories = $section['list'])
    <section aria-label="カテゴリから探す" class="fashion-category-bento" data-test="categories" data-pattern="bento" data-section="{{ $section->id }}">
        @foreach ($categories as $category)
            <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}" class="fashion-category" data-motion-card>
                <img src="{{ $category['photo']->url('large') }}" srcset="{{ $category['photo']->srcset() }}" sizes="{{ $loop->first ? '(min-width: 768px) 50vw, 100vw' : '(min-width: 768px) 25vw, 50vw' }}" width="{{ $category['photo']->width }}" height="{{ $category['photo']->height }}" alt="" loading="lazy" decoding="async">
                <span>{{ $category['label'] }}</span>
            </a>
        @endforeach
    </section>
@endif
