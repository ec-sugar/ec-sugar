{{-- ヒーロー「左右」。左に文（帯の色の地）、右に縦長の写真。スマホでは写真の下に文。写真が無ければ文だけを帯に --}}
<section class="grid bg-band-strong md:grid-cols-2" data-section="{{ $section->id }}" data-test="hero" data-pattern="split">
    <div class="order-2 flex min-w-0 flex-col justify-center gap-5 px-5 py-14 sm:px-12 md:order-1 md:py-20 {{ $section['image'] ? '' : 'md:col-span-2 md:mx-auto md:max-w-[1360px] md:w-full' }}" data-motion-intro>
        @include('theme-kinari::partials.hero-text')
    </div>
    @if ($section['image'])
        <div class="order-1 aspect-[4/3] overflow-hidden md:order-2 md:aspect-auto md:min-h-140">
            <img src="{{ $section['image']->url() }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 768px) 50vw, 100vw"
                 width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt=""
                 fetchpriority="high" decoding="async" class="size-full object-cover" data-test="hero-image">
        </div>
    @endif
</section>
