{{-- 季節の特集「写真に重ねる」。写真を箱いっぱいに敷き、右下に生成りの札で文を重ねる（スマホは写真の下に札を少し重ねる）。
     見出しと写真の両方があるときだけ出す --}}
@if ($section['heading'] && $section['image'])
    <div class="mx-auto w-full max-w-[1360px] px-5 first:mt-18 sm:px-8" data-section="{{ $section->id }}">
        <section aria-labelledby="seasonal-{{ $section->id }}" class="relative flex flex-col sm:min-h-120 sm:items-end sm:justify-end sm:p-10" data-test="seasonal" data-pattern="overlay">
            <img src="{{ $section['image']->url() }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 1360px) 1300px, 100vw"
                 width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}"
                 loading="lazy" decoding="async" class="aspect-[4/3] w-full object-cover sm:absolute sm:inset-0 sm:aspect-auto sm:size-full">
            <div class="relative mx-4 -mt-10 flex min-w-0 flex-col gap-3.5 bg-canvas/95 px-7 py-8 sm:mx-0 sm:mt-0 sm:max-w-md">
                @include('theme-kinari::partials.seasonal-text')
            </div>
        </section>
    </div>
@endif
