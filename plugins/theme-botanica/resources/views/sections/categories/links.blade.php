{{-- カテゴリ「文字」（既定。今までのトップの見た目）: 明朝の文字のリンクを真ん中に並べる --}}
@if ($categories = $section['list'])
    <nav aria-label="カテゴリから探す" class="botanica-category-links" data-test="categories" data-section="{{ $section->id }}">
        @foreach ($categories as $category)
            <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}">{{ $category['label'] }}</a>
        @endforeach
    </nav>
@endif
