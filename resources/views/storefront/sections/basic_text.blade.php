{{-- 核の汎用の区画「見出しと文」（ADR 0039 区切り 3）。パターンは left（左寄せ）・center（中央）。見た目は核の CSS（resources/storefront/sections.css） --}}
<section class="sugar-section sugar-text" data-section="{{ $section->id }}" data-section-type="basic_text" data-pattern="{{ $section->pattern }}">
    @if ($section['heading'])
        <h2 class="sugar-section__heading">{{ $section['heading'] }}</h2>
    @endif
    @if ($section['body'])
        <p class="sugar-section__body">{{ $section['body'] }}</p>
    @endif
</section>
