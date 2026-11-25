{{-- 季節の特集「左右」（既定。今までのトップの見た目）: 左に写真、右に文字。見出しと写真の両方があるときだけ出す --}}
@if ($section['heading'] && $section['image'])
    <section aria-labelledby="{{ $section->id }}-title" class="fashion-seasonal" data-test="seasonal" data-section="{{ $section->id }}">
        <img src="{{ $section['image']->url('large') }}" width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}" loading="lazy" decoding="async">
        <div class="fashion-seasonal-copy">
            @include('theme-fashion::sections.partials.seasonal-copy')
        </div>
    </section>
@endif
