{{-- ヒーローとショップの説明「格子」。左に文の箱、右にヒーローの写真（横長）と説明の写真 1・2（正方形）を格子に並べる。
     スマホでは文 → 写真の順に 2 列で積む。写真が 1 枚も無ければ角砂糖の積み木 --}}
<section aria-labelledby="hero-{{ $section->id }}" class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4" data-section="{{ $section->id }}" data-test="hero" data-pattern="grid">
    <div class="col-span-2 flex min-w-0 flex-col items-start justify-center gap-4.5 bg-band p-6 sm:p-10 md:row-span-2" data-motion-intro>
        @include('theme-default::partials.hero-text', ['headingId' => 'hero-'.$section->id])
    </div>
    @forelse (array_values(array_filter([$section['image'], $section['photo_a'], $section['photo_b']])) as $photo)
        <div class="{{ $loop->first ? 'col-span-2 aspect-[2/1]' : 'aspect-square' }} overflow-hidden bg-photo">
            <img src="{{ $photo->url($loop->first ? 'large' : 'medium') }}" srcset="{{ $photo->srcset() }}" sizes="{{ $loop->first ? '(min-width: 768px) 600px, 100vw' : '(min-width: 768px) 300px, 50vw' }}"
                 width="{{ $photo->width }}" height="{{ $photo->height }}" alt="{{ $loop->first ? '' : ($photo->alt ?? '') }}"
                 @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async" class="size-full object-cover" @if ($loop->first) data-test="hero-image" @endif>
        </div>
    @empty
        <div class="col-span-2 flex items-center justify-center bg-photo px-8 py-6 md:row-span-2">@include('theme-default::partials.cube-pile')</div>
    @endforelse
</section>
