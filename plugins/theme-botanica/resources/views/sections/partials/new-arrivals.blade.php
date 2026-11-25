{{-- 新着の商品（パターンごとの部品から読む）。$section・$count（並べる数）。商品は店頭がトップに渡す新しい順の $listings。
     ページ内のリンク（メニューの「新着」の #new）は、ページの最初の新着の区画に付ける --}}
<section @once('anchor-new') id="new" @endonce aria-labelledby="{{ $section->id }}-title" class="botanica-section" data-test="new-arrivals" data-section="{{ $section->id }}">
    @include('theme-botanica::partials.section-title', ['eyebrow' => 'Fresh greens', 'title' => $section['heading'] ?? '新しく届いたもの', 'id' => $section->id.'-title'])
    @if (($listings ?? []) === [])
        <p class="bg-band px-6 py-12 text-center text-ink-soft" data-test="products-empty">まだ商品がありません。</p>
    @else
        @include('theme-botanica::sections.partials.product-rail', ['listings' => array_slice($listings, 0, $count), 'pattern' => $section->pattern])
    @endif
    <div class="mt-8 text-center"><a href="{{ route('storefront.products') }}" class="botanica-text-link" data-test="see-all">すべての商品を見る</a></div>
</section>
