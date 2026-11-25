{{-- ヒーロー「格子」: 上に文字、下にヒーローの写真と写真のブロック（3 つまで）を格子に並べる。広い画面は 4 列、狭い画面は 2 列 --}}
<section class="fashion-hero-grid" data-test="fashion-hero" data-pattern="grid" data-section="{{ $section->id }}">
    <div class="fashion-hero-grid-copy" @once('motion-intro') data-motion-intro @endonce>
        @include('theme-fashion::sections.partials.hero-copy')
    </div>
    @if ($photos = array_values(array_filter([$section['image'], ...array_map(static fn ($block) => $block->key === 'photo' ? $block['image'] : null, $section->blocks)])))
        <div class="fashion-hero-grid-photos" data-count="{{ count($photos) }}">
            @foreach (array_slice($photos, 0, 4) as $photo)
                <img src="{{ $photo->url('large') }}" srcset="{{ $photo->srcset() }}" sizes="(min-width: 768px) 25vw, 50vw" width="{{ $photo->width }}" height="{{ $photo->height }}" alt="{{ $photo->alt ?? '' }}" decoding="async" @if ($loop->first) data-test="hero-image" @else loading="lazy" @endif>
            @endforeach
        </div>
    @endif
</section>
