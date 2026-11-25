{{-- コーディネート「2 列」。見出しと文を上に、写真を大きく 2 列で並べる（スマホも 2 列）。題は写真の下に明朝で。
     見出しと、写真のある組が 1 つ以上あるときだけ出す --}}
@if ($section['heading'] && ($looks = array_filter($section->items, static fn (array $look): bool => $look['image'] !== null)) !== [])
    <section id="looks" aria-labelledby="looks-{{ $section->id }}" class="mx-auto flex w-full max-w-[1360px] flex-col gap-8 px-5 first:mt-18 sm:px-8" data-section="{{ $section->id }}" data-test="looks" data-pattern="two">
        <div class="flex max-w-xl flex-col gap-3">
            <span class="font-display text-[15px] tracking-[0.18em] text-ink-soft" aria-hidden="true">CURATED LOOKS</span>
            <h2 id="looks-{{ $section->id }}" class="font-mincho text-[28px] leading-normal font-medium tracking-[0.06em] whitespace-pre-line [overflow-wrap:anywhere]">{{ $section['heading'] }}</h2>
            @if ($section['body'])
                <p class="text-[13px] whitespace-pre-line text-ink-muted">{{ $section['body'] }}</p>
            @endif
        </div>
        <ul class="grid grid-cols-2 gap-4 sm:gap-6">
            @foreach ($looks as $look)
                <li class="min-w-0">
                    <figure class="flex flex-col gap-3">
                        <img src="{{ $look['image']->url() }}" srcset="{{ $look['image']->srcset() }}" sizes="(min-width: 1360px) 660px, 50vw"
                             width="{{ $look['image']->width }}" height="{{ $look['image']->height }}" alt="{{ $look['image']->alt ?? '' }}"
                             loading="lazy" decoding="async" class="aspect-[4/5] w-full bg-photo-bg object-cover">
                        @if ($look['title'])
                            <figcaption class="font-mincho text-sm tracking-[0.06em] [overflow-wrap:anywhere] sm:text-base">{{ $look['title'] }}</figcaption>
                        @endif
                    </figure>
                </li>
            @endforeach
        </ul>
    </section>
@endif
