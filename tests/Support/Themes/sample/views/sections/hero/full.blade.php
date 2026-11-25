{{-- ヒーローの「全面」。左右は hero/split、格子は部品が無いので hero（種類だけの部品）で描く --}}
<section data-test="hero" data-pattern="full" data-section="{{ $section->id }}">
    @include('theme-sample::sections.partials.hero-body')
</section>
