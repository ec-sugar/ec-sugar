{{-- 種類だけの部品（パターンの部品が無いとき。ここでは格子） --}}
<section data-test="hero" data-pattern="{{ $section->pattern }}" data-fallback data-section="{{ $section->id }}">
    @include('theme-sample::sections.partials.hero-body')
</section>
