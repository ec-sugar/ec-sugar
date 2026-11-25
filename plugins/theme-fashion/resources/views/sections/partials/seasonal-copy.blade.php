{{-- 季節の特集の文字（パターンごとの部品から読む）。$section（季節の特集） --}}
@if ($section['label'])
    <p class="text-xs tracking-widest text-ink-muted">{{ $section['label'] }}</p>
@endif
<h2 id="{{ $section->id }}-title" class="font-display text-3xl leading-relaxed whitespace-pre-line sm:text-4xl">{{ $section['heading'] }}</h2>
@if ($section['body'])
    <p class="text-sm leading-loose whitespace-pre-line text-ink-muted">{{ $section['body'] }}</p>
@endif
@if ($section['highlight'])
    <p class="font-display text-4xl">{{ $section['highlight'] }}</p>
@endif
@if ($section['note'])
    <p class="text-xs text-ink-soft">{{ $section['note'] }}</p>
@endif
<a href="{{ route('storefront.products') }}" class="fashion-text-link">商品を見る</a>
