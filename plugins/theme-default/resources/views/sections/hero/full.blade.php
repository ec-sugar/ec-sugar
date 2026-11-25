{{-- ヒーローとショップの説明「全面」。写真を区画いっぱいに敷き、左下に文を重ねる（読みやすいように下へ暗くする）。
     写真が無ければ帯の色の地に角砂糖の積み木を右に置く。説明の写真 1・2 は使わない --}}
<section aria-labelledby="hero-{{ $section->id }}" class="relative isolate flex min-h-110 items-end overflow-hidden bg-band sm:min-h-140" data-section="{{ $section->id }}" data-test="hero" data-pattern="full">
    @if ($section['image'])
        <img src="{{ $section['image']->url() }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 1200px) 1200px, 100vw"
             width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt=""
             fetchpriority="high" decoding="async" class="absolute inset-0 -z-10 size-full object-cover" data-test="hero-image">
        <div aria-hidden="true" class="absolute inset-0 -z-10 bg-linear-to-t from-ink/70 via-ink/25 to-ink/0"></div>
    @else
        <div aria-hidden="true" class="absolute inset-y-0 right-0 -z-10 hidden w-1/2 items-center justify-center p-10 sm:flex">@include('theme-default::partials.cube-pile')</div>
    @endif
    <div class="flex max-w-xl min-w-0 flex-col items-start gap-4.5 p-6 sm:p-12 {{ $section['image'] ? 'text-on-accent' : '' }}" data-motion-intro>
        @include('theme-default::partials.hero-text', ['headingId' => 'hero-'.$section->id, 'onPhoto' => $section['image'] !== null])
    </div>
</section>
