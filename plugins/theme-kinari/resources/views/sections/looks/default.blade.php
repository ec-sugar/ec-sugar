{{-- コーディネート「帯に文と写真」（既定のパターン。区画にする前のトップと同じ見た目）。セージの帯の左に文、右に縦長の写真の並び。
     見出しと、写真のある組が 1 つ以上あるときだけ出す。飛び先の id（#looks）はメニューのリンクが使う --}}
@if ($section['heading'] && ($looks = array_filter($section->items, static fn (array $look): bool => $look['image'] !== null)) !== [])
    <div class="mx-auto w-full max-w-[1360px] px-5 first:mt-18 sm:px-8" data-section="{{ $section->id }}">
        <section id="looks" aria-labelledby="looks-{{ $section->id }}" class="grid grid-cols-2 gap-4 bg-sage-band p-5 sm:p-10 md:grid-cols-5 md:gap-5" data-test="looks" data-pattern="default">
            <div class="col-span-2 flex flex-col justify-between gap-6 p-2 md:col-span-1">
                <div class="flex flex-col gap-3.5">
                    <span class="font-display text-[15px] tracking-[0.18em] text-ink-muted" aria-hidden="true">CURATED LOOKS</span>
                    <h2 id="looks-{{ $section->id }}" class="font-mincho text-[28px] leading-normal font-medium tracking-[0.06em] whitespace-pre-line [overflow-wrap:anywhere]">{{ $section['heading'] }}</h2>
                    @if ($section['body'])
                        <p class="text-[13px] whitespace-pre-line text-ink-muted">{{ $section['body'] }}</p>
                    @endif
                </div>
            </div>
            @foreach ($looks as $look)
                <figure class="flex min-w-0 flex-col bg-canvas">
                    <img src="{{ $look['image']->url('medium') }}" width="{{ $look['image']->width }}" height="{{ $look['image']->height }}" alt="{{ $look['image']->alt ?? '' }}"
                         loading="lazy" decoding="async" class="aspect-[4/5] w-full object-cover">
                    <figcaption class="px-3 pt-3.5 pb-4 text-center text-sm tracking-[0.04em] [overflow-wrap:anywhere]">{{ $look['title'] }}</figcaption>
                </figure>
            @endforeach
        </section>
    </div>
@endif
