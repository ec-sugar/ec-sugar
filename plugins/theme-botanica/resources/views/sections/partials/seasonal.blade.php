{{-- 季節の特集（パターンごとの部品から読む）。$class（組み方）。見出しと写真の両方があるときだけ出す。
     区画の高さは文の側が決め、写真はその高さに合わせて切り取る（季節 2。写真が上のパターンだけは写真の比で） --}}
@if ($section['heading'] && $section['image'])
    <section aria-labelledby="{{ $section->id }}-title" class="{{ $class }}" data-test="seasonal" data-pattern="{{ $section->pattern }}" data-section="{{ $section->id }}">
        <div class="botanica-seasonal-media">
            <img src="{{ $section['image']->url('large') }}" width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}" loading="lazy" decoding="async" class="botanica-seasonal-photo">
        </div>
        <div class="botanica-seasonal-copy" @if ($section->pattern !== 'split') data-motion-reveal @endif>
            @if ($section['label'])
                <p class="text-xs tracking-widest text-ink-muted">{{ $section['label'] }}</p>
            @endif
            <h2 id="{{ $section->id }}-title" class="font-display text-3xl leading-snug whitespace-pre-line sm:text-4xl">{{ $section['heading'] }}</h2>
            @if ($section['body'])
                <p class="text-sm leading-loose whitespace-pre-line text-ink-muted">{{ $section['body'] }}</p>
            @endif
            @if ($section['highlight'])
                <p class="font-display text-4xl">{{ $section['highlight'] }}</p>
            @endif
            @if ($section['note'])
                <p class="text-xs text-ink-soft">{{ $section['note'] }}</p>
            @endif
            <a href="{{ route('storefront.products') }}" class="botanica-text-link">商品を見る</a>
        </div>
    </section>
@endif
