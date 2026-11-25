{{-- ヒーロー「写真と面」（既定。今までのトップの見た目）: 上に写真、下に濃いセージの文字の面（文字を写真に重ねない。C 案）。
     すぐ後に右の特集（stack）があれば、CSS が左右に並べる --}}
<section class="botanica-hero botanica-mosaic-hero {{ $section['image'] ? 'botanica-hero-photo' : '' }}" data-test="botanica-hero" data-section="{{ $section->id }}">
    @if ($heroImage = $section['image'])
        <div class="botanica-hero-media">
            <img src="{{ $heroImage->url() }}" srcset="{{ $heroImage->srcset() }}" sizes="(min-width: 768px) 50vw, 100vw" width="{{ $heroImage->width }}" height="{{ $heroImage->height }}" alt="" fetchpriority="high" decoding="async" class="botanica-hero-image" data-test="hero-image" data-motion-zoom>
        </div>
    @endif
    {{-- ページを開いた時: 写真がゆっくり引き、見出しが浮かぶ（核の動き。強さは theme.json の motion。見出しの塊はページに 1 か所だけ） --}}
    <div class="botanica-hero-panel" @once('motion-intro') data-motion-intro @endonce>
        @include('theme-botanica::sections.partials.hero-copy')
    </div>
</section>
