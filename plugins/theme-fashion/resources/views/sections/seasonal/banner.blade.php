{{-- 季節の特集「全面」: 写真を幅いっぱいに敷き、暗い膜の上に白い文字を真ん中に。見出しと写真の両方があるときだけ --}}
@if ($section['heading'] && $section['image'])
    <section aria-labelledby="{{ $section->id }}-title" class="fashion-seasonal-banner" data-test="seasonal" data-pattern="banner" data-section="{{ $section->id }}">
        <img src="{{ $section['image']->url('large') }}" srcset="{{ $section['image']->srcset() }}" sizes="100vw" width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}" loading="lazy" decoding="async">
        <div class="fashion-hero-shade" aria-hidden="true"></div>
        <div class="fashion-seasonal-banner-copy">
            @include('theme-fashion::sections.partials.seasonal-copy')
        </div>
    </section>
@endif
