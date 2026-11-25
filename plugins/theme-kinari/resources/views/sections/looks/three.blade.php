{{-- コーディネート「3 つ」。見出しを真ん中に、写真を 3 つまで横に並べる（スマホは縦に積む）。題は写真の下の帯に。
     見出しと、写真のある組が 1 つ以上あるときだけ出す（4 つ目の組は出さない） --}}
@if ($section['heading'] && ($looks = array_slice(array_filter($section->items, static fn (array $look): bool => $look['image'] !== null), 0, 3)) !== [])
    <section id="looks" aria-labelledby="looks-{{ $section->id }}" class="mx-auto flex w-full max-w-[1360px] flex-col items-center gap-8 px-5 first:mt-18 sm:px-8" data-section="{{ $section->id }}" data-test="looks" data-pattern="three">
        <div class="flex max-w-xl flex-col items-center gap-3 text-center">
            <span class="font-display text-[15px] tracking-[0.18em] text-ink-soft" aria-hidden="true">CURATED LOOKS</span>
            <h2 id="looks-{{ $section->id }}" class="font-mincho text-[28px] leading-normal font-medium tracking-[0.06em] whitespace-pre-line [overflow-wrap:anywhere]">{{ $section['heading'] }}</h2>
            @if ($section['body'])
                <p class="text-[13px] whitespace-pre-line text-ink-muted">{{ $section['body'] }}</p>
            @endif
        </div>
        <ul class="grid w-full grid-cols-1 gap-5 sm:grid-cols-3">
            @foreach ($looks as $look)
                <li class="min-w-0">
                    <figure class="flex flex-col bg-band">
                        <img src="{{ $look['image']->url('medium') }}" srcset="{{ $look['image']->srcset() }}" sizes="(min-width: 640px) 33vw, 100vw"
                             width="{{ $look['image']->width }}" height="{{ $look['image']->height }}" alt="{{ $look['image']->alt ?? '' }}"
                             loading="lazy" decoding="async" class="aspect-[4/5] w-full object-cover">
                        <figcaption class="px-4 py-4 text-center text-sm tracking-[0.04em] [overflow-wrap:anywhere]">{{ $look['title'] }}</figcaption>
                    </figure>
                </li>
            @endforeach
        </ul>
    </section>
@endif
