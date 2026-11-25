{{-- 季節の特集「回り込み」。写真を右に浮かせ、文が写真のまわりに回り込む（スマホは写真を上に、文を下に）。
     見出しと写真の両方があるときだけ出す --}}
@if ($section['heading'] && $section['image'])
    <div class="mx-auto w-full max-w-[1360px] px-5 first:mt-18 sm:px-8" data-section="{{ $section->id }}">
        <section aria-labelledby="seasonal-{{ $section->id }}" class="flow-root border-y border-line py-10" data-test="seasonal" data-pattern="wrap">
            <img src="{{ $section['image']->url('medium') }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 640px) 45vw, 100vw"
                 width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}"
                 loading="lazy" decoding="async" class="mb-6 aspect-[4/3] w-full object-cover sm:float-right sm:mb-4 sm:ml-10 sm:w-[45%]">
            <div class="flex min-w-0 flex-col gap-3.5 sm:block sm:[&>*+*]:mt-3.5">
                @include('theme-kinari::partials.seasonal-text')
            </div>
        </section>
    </div>
@endif
