{{-- ヒーローとショップの説明「左右」。左に写真（無ければ角砂糖の積み木）、右に文。スマホでは写真の下に文 --}}
<section aria-labelledby="hero-{{ $section->id }}" class="grid items-center gap-8 md:grid-cols-2 md:gap-14" data-section="{{ $section->id }}" data-test="hero" data-pattern="split">
    <div class="relative flex aspect-[4/3] items-center justify-center overflow-hidden bg-band md:aspect-[4/5]">
        @if ($section['image'])
            <img src="{{ $section['image']->url() }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 768px) 600px, 100vw"
                 width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt=""
                 fetchpriority="high" decoding="async" class="absolute inset-0 size-full object-cover" data-test="hero-image">
        @else
            <div class="w-full px-8">@include('theme-default::partials.cube-pile')</div>
        @endif
    </div>
    <div class="flex min-w-0 flex-col items-start gap-4.5" data-motion-intro>
        @include('theme-default::partials.hero-text', ['headingId' => 'hero-'.$section->id])
    </div>
</section>
