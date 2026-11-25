{{-- ヒーロー「左右」: 左に写真、右に濃いセージの文字の面を幅いっぱいに。狭い画面では写真の下に面。写真が無ければ面だけ --}}
<section class="botanica-hero-split {{ $section['image'] ? 'botanica-hero-photo' : '' }}" data-test="botanica-hero" data-pattern="split" data-section="{{ $section->id }}">
    @if ($heroImage = $section['image'])
        <div class="botanica-hero-media">
            <img src="{{ $heroImage->url() }}" srcset="{{ $heroImage->srcset() }}" sizes="(min-width: 768px) 50vw, 100vw" width="{{ $heroImage->width }}" height="{{ $heroImage->height }}" alt="" fetchpriority="high" decoding="async" class="botanica-hero-image" data-test="hero-image" data-motion-zoom>
        </div>
    @endif
    <div class="botanica-hero-panel" @once('motion-intro') data-motion-intro @endonce>
        @include('theme-botanica::sections.partials.hero-copy')
    </div>
</section>
