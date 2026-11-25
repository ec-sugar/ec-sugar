{{-- 右の特集（パターンごとの部品から読む）。$class（並べ方）。写真のあるものだけ（2 つまで）。
     特集は「写真｜文字の面」に分け、題を写真に重ねない（C 案）。行き先は店の人が決める（空なら商品の一覧） --}}
@if ($looks = array_filter($section->items, static fn (array $look): bool => $look['image'] !== null))
    <section class="{{ $class }}" aria-label="植物の特集" data-test="looks" data-pattern="{{ $section->pattern }}" data-section="{{ $section->id }}">
        @foreach (array_slice($looks, 0, 2) as $look)
            <a href="{{ $look['link'] ?? route('storefront.products') }}" class="botanica-promo" @if ($section->pattern !== 'stack') data-motion-card @endif>
                <div class="botanica-promo-media">
                    <img src="{{ $look['image']->url('large') }}" width="{{ $look['image']->width }}" height="{{ $look['image']->height }}" alt="{{ $look['image']->alt ?? '' }}" loading="lazy" decoding="async">
                </div>
                <div class="botanica-promo-copy">
                    <p class="botanica-kicker">{{ $look['kicker'] ?? 'Green selection' }}</p>
                    <h2>{{ $look['title'] }}</h2>
                    <span class="botanica-text-link">商品を見る</span>
                </div>
            </a>
        @endforeach
    </section>
@endif
