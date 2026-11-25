{{-- ヒーロー「左右」: 左に縦長の写真、右に文字。狭い画面では写真の下に文字。写真が無ければ文字だけ --}}
<section class="fashion-hero-split" data-test="fashion-hero" data-pattern="split" data-section="{{ $section->id }}">
    @if ($heroImage = $section['image'])
        <div class="fashion-hero-split-media">
            <img src="{{ $heroImage->url() }}" srcset="{{ $heroImage->srcset() }}" sizes="(min-width: 768px) 50vw, 100vw" width="{{ $heroImage->width }}" height="{{ $heroImage->height }}" alt="" fetchpriority="high" decoding="async" data-test="hero-image" data-motion-zoom>
        </div>
    @endif
    <div class="fashion-hero-split-copy" @once('motion-intro') data-motion-intro @endonce>
        @include('theme-fashion::sections.partials.hero-copy')
    </div>
</section>
