{{-- ヒーロー「全面」（既定。今までのトップの見た目）: 写真を画面いっぱいに敷き、見出しを下寄せの真ん中に。最初の 3 つのカテゴリも添える --}}
<section class="fashion-hero {{ $section['image'] ? 'fashion-hero-photo' : '' }}" data-test="fashion-hero" data-section="{{ $section->id }}">
    @if ($heroImage = $section['image'])
        <img src="{{ $heroImage->url() }}" srcset="{{ $heroImage->srcset() }}" sizes="100vw" width="{{ $heroImage->width }}" height="{{ $heroImage->height }}" alt="" fetchpriority="high" decoding="async" class="fashion-hero-image" data-test="hero-image" data-motion-zoom>
        <div class="fashion-hero-shade" aria-hidden="true"></div>
    @endif
    {{-- ページを開いた時: 写真がゆっくり引き、見出しが浮かぶ（核の動き。強さは theme.json の motion。見出しの塊はページに 1 か所だけ） --}}
    <div class="fashion-hero-copy" @once('motion-intro') data-motion-intro @endonce>
        @include('theme-fashion::sections.partials.hero-copy')
        @if ($categories = $sections->get('categories')?->offsetGet('list'))
            <nav aria-label="注目のカテゴリ" class="mt-5 flex flex-wrap justify-center gap-x-8 gap-y-3 text-xs">
                @foreach (array_slice($categories, 0, 3) as $category)
                    <a href="{{ route('storefront.category', ['slug' => $category['slug']]) }}">{{ $category['label'] }}</a>
                @endforeach
            </nav>
        @endif
    </div>
</section>
