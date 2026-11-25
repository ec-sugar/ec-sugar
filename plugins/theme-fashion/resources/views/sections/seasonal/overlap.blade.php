{{-- 季節の特集「回り込み」: 大きな写真の右の端に、白い文字の面を重ねる（狭い画面では写真の下に少し重ねる）。見出しと写真の両方があるときだけ --}}
@if ($section['heading'] && $section['image'])
    <section aria-labelledby="{{ $section->id }}-title" class="fashion-seasonal-overlap" data-test="seasonal" data-pattern="overlap" data-section="{{ $section->id }}">
        <div class="fashion-seasonal-overlap-media">
            <img src="{{ $section['image']->url('large') }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 768px) 66vw, 100vw" width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}" loading="lazy" decoding="async">
        </div>
        <div class="fashion-seasonal-overlap-copy" data-motion-reveal>
            @include('theme-fashion::sections.partials.seasonal-copy')
        </div>
    </section>
@endif
