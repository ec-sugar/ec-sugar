{{-- ヒーロー「格子」: 左に文字の面、右にヒーローの写真と写真のブロック（3 つまで）を 2 × 2 の格子に。狭い画面では面の下に 2 列 --}}
<section class="botanica-hero-grid" data-test="botanica-hero" data-pattern="grid" data-section="{{ $section->id }}">
    <div class="botanica-hero-panel" @once('motion-intro') data-motion-intro @endonce>
        @include('theme-botanica::sections.partials.hero-copy')
    </div>
    @if ($photos = array_values(array_filter([$section['image'], ...array_map(static fn ($block) => $block->key === 'photo' ? $block['image'] : null, $section->blocks)])))
        <div class="botanica-hero-grid-photos" data-count="{{ count($photos) }}">
            @foreach (array_slice($photos, 0, 4) as $photo)
                <img src="{{ $photo->url('large') }}" srcset="{{ $photo->srcset() }}" sizes="(min-width: 768px) 25vw, 50vw" width="{{ $photo->width }}" height="{{ $photo->height }}" alt="{{ $photo->alt ?? '' }}" decoding="async" @if ($loop->first) data-test="hero-image" @else loading="lazy" @endif>
            @endforeach
        </div>
    @endif
</section>
