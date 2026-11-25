{{-- ヒーローとショップの説明「写真の帯と説明」（既定のパターン。区画にする前のトップと同じ見た目）。
     上に写真だけの帯（無ければ角砂糖の積み木）、下にショップの説明。説明の写真があれば文の横に重ね、無ければ文を真ん中に寄せる --}}
<div class="flex flex-col gap-18" data-section="{{ $section->id }}">
    <section aria-label="ヒーロー" class="relative flex min-h-80 items-center justify-center overflow-hidden bg-band sm:min-h-110" data-test="hero" data-pattern="default">
        @if ($section['image'])
            {{-- ヒーローは最初に見える大きな写真なので、遅らせて読まない --}}
            <img src="{{ $section['image']->url() }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 1200px) 1200px, 100vw"
                 width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt=""
                 fetchpriority="high" decoding="async" class="absolute inset-0 size-full object-cover" data-test="hero-image">
        @else
            <div class="flex w-full justify-center px-8 py-10">@include('theme-default::partials.cube-pile')</div>
        @endif
    </section>

    <section aria-labelledby="intro-{{ $section->id }}" class="grid items-center gap-12 {{ $section['photo_a'] || $section['photo_b'] ? 'md:grid-cols-2' : 'mx-auto max-w-2xl' }}" data-test="intro">
        {{-- ページを開いた時に少し浮かぶ（核の動き。強さは theme.json の motion） --}}
        <div class="flex min-w-0 flex-col gap-4.5 {{ $section['photo_a'] || $section['photo_b'] ? 'items-start' : 'items-center text-center' }}" data-motion-intro>
            @include('theme-default::partials.hero-text',['headingId' => 'intro-'.$section->id])
        </div>
        @if ($section['photo_a'] || $section['photo_b'])
            <div class="relative mx-auto w-full max-w-md pb-16 sm:pb-20" data-test="intro-photos">
                @foreach (array_values(array_filter([$section['photo_a'], $section['photo_b']])) as $photo)
                    <img src="{{ $photo->url('medium') }}" width="{{ $photo->width }}" height="{{ $photo->height }}" alt="{{ $photo->alt ?? '' }}" loading="lazy" decoding="async"
                         class="{{ $loop->first ? 'aspect-[4/3] w-4/5' : 'absolute right-0 bottom-0 aspect-square w-1/2 border-8 border-canvas' }} bg-photo object-cover">
                @endforeach
            </div>
        @endif
    </section>
</div>
