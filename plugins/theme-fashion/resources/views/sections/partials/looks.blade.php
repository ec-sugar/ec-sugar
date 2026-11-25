{{-- 新作（コーディネート）（パターンごとの部品から読む）。$class（写真の並べ方）・$count（並べる数）・$reveal（スクロールで浮かべるか）。
     見出しと、写真のある組が 1 つ以上あるときだけ出す --}}
@if ($section['heading'] && ($looks = array_filter($section->items, static fn (array $look): bool => $look['image'] !== null)) !== [])
    <section aria-labelledby="{{ $section->id }}-title" class="fashion-section" data-test="looks" data-pattern="{{ $section->pattern }}" data-section="{{ $section->id }}">
        @include('theme-fashion::partials.section-title', ['eyebrow' => 'NEW COLLECTIONS', 'title' => $section['heading'], 'id' => $section->id.'-title'])
        @if ($section['body'])
            <p class="mb-8 text-center text-sm whitespace-pre-line text-ink-muted">{{ $section['body'] }}</p>
        @endif
        <div class="{{ $class }}">
            @foreach (array_slice($looks, 0, $count) as $look)
                <figure @if ($reveal) data-motion-reveal @endif>
                    <img src="{{ $look['image']->url('large') }}" width="{{ $look['image']->width }}" height="{{ $look['image']->height }}" alt="{{ $look['image']->alt ?? '' }}" loading="lazy" decoding="async" class="aspect-[4/5] w-full bg-photo-bg object-cover">
                    <figcaption class="py-4 text-center text-sm">{{ $look['title'] }}</figcaption>
                </figure>
            @endforeach
        </div>
    </section>
@endif
