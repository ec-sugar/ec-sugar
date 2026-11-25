{{-- ヒーロー「写真に重ねる」（既定のパターン。区画にする前のトップと同じ見た目）。写真を画面の幅いっぱいに敷き、左から生成りで薄めて文を重ねる。
     スマホでは写真の全体を薄める --}}
<section class="relative overflow-hidden bg-band-strong" data-section="{{ $section->id }}" data-test="hero" data-pattern="default">
    @if ($section['image'])
        {{-- ヒーローは最初に見える大きな写真なので、遅らせて読まない --}}
        <img src="{{ $section['image']->url() }}" srcset="{{ $section['image']->srcset() }}" sizes="100vw"
             width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt=""
             fetchpriority="high" decoding="async" class="absolute inset-0 size-full object-cover object-[70%_40%]" data-test="hero-image">
        <div aria-hidden="true" class="absolute inset-0 bg-canvas/80 sm:bg-transparent sm:bg-linear-to-r sm:from-canvas/95 sm:from-0% sm:via-canvas/80 sm:via-35% sm:to-canvas/0 sm:to-65%"></div>
    @endif
    {{-- ページを開いた時に少し浮かぶ（核の動き。強さは theme.json の motion） --}}
    <div class="relative mx-auto flex max-w-[1360px] flex-col gap-5 px-5 py-20 sm:px-8 sm:py-28" data-motion-intro>
        @include('theme-kinari::partials.hero-text')
    </div>
</section>
