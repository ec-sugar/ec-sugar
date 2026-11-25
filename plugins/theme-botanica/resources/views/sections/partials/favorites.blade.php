{{-- おすすめの商品（パターンごとの部品から読む）。店の人が選んだ商品が 1 つ以上あるときだけ出す --}}
@if ($favorites = $section['products'])
    <section aria-labelledby="{{ $section->id }}-title" class="botanica-section" data-test="favorites" data-section="{{ $section->id }}">
        @include('theme-botanica::partials.section-title', ['eyebrow' => 'Our selection', 'title' => 'おすすめの商品', 'id' => $section->id.'-title'])
        @include('theme-botanica::sections.partials.product-rail', ['listings' => $favorites, 'pattern' => $section->pattern])
    </section>
@endif
