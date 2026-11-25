{{-- 季節の特集「文と写真」（既定のパターン）。ベージュの箱の左に文、右に写真（スマホは文の下に写真）。
     区画にする前はよく選ばれている商品と横に 2 つ並んでいた（区画は前後を前提にしないので、今は幅いっぱいの 1 つの箱）。
     見出しと写真の両方があるときだけ出す --}}
@if ($section['heading'] && $section['image'])
    <div class="mx-auto w-full max-w-[1360px] px-5 first:mt-18 sm:px-8" data-section="{{ $section->id }}">
        <section aria-labelledby="seasonal-{{ $section->id }}" class="grid grid-cols-1 bg-season-band sm:grid-cols-2" data-test="seasonal" data-pattern="default">
            <div class="flex min-w-0 flex-col justify-center gap-3.5 px-8 py-10">
                @include('theme-kinari::partials.seasonal-text')
            </div>
            <img src="{{ $section['image']->url('medium') }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 640px) 50vw, 100vw"
                 width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}"
                 loading="lazy" decoding="async" class="size-full min-h-90 object-cover">
        </section>
    </div>
@endif
