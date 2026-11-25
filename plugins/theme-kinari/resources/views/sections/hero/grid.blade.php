{{-- ヒーロー「格子」。幅の箱の中で、左に文、右に写真（大きい 1 枚と小さい 2 枚の格子）。スマホでは文 → 写真の順に積む。
     写真が 1 枚も無ければ文だけ --}}
<section class="mx-auto grid w-full max-w-[1360px] gap-4 px-5 pt-10 sm:px-8 md:grid-cols-[5fr_7fr] md:items-center md:gap-10 md:pt-14" data-section="{{ $section->id }}" data-test="hero" data-pattern="grid">
    <div class="flex min-w-0 flex-col gap-5 py-6" data-motion-intro>
        @include('theme-kinari::partials.hero-text')
    </div>
    @if ($photos = array_values(array_filter([$section['image'], $section['photo_a'], $section['photo_b']])))
        <div class="grid grid-cols-3 grid-rows-2 gap-3 sm:gap-4">
            @foreach ($photos as $photo)
                <div class="{{ $loop->first ? (count($photos) === 1 ? 'col-span-3 row-span-2 aspect-[3/2]' : 'col-span-2 row-span-2 aspect-[4/5]') : (count($photos) === 2 ? 'row-span-2' : '') }} relative min-h-0 overflow-hidden bg-photo-bg">
                    <img src="{{ $photo->url($loop->first ? 'large' : 'medium') }}" srcset="{{ $photo->srcset() }}" sizes="{{ $loop->first ? '(min-width: 768px) 40vw, 66vw' : '(min-width: 768px) 20vw, 33vw' }}"
                         width="{{ $photo->width }}" height="{{ $photo->height }}" alt="{{ $loop->first ? '' : ($photo->alt ?? '') }}"
                         @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async" class="absolute inset-0 size-full object-cover" @if ($loop->first) data-test="hero-image" @endif>
                </div>
            @endforeach
        </div>
    @endif
</section>
