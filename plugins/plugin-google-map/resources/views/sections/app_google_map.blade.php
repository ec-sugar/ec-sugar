{{--
    App「地図（Google マップ）」の区画（ADR 0040）。パターンは wide（横いっぱい）・split（地図が左、見出しと案内の文が右。狭い画面では縦に積む）。
    枠（iframe）は核の部品 <x-storefront.embed> が描く（ページを開いた時から。この Blade は並べるだけ）。
    見た目は核の CSS（sections.css の sugar-app-section・sugar-embed）
--}}
<section class="sugar-section sugar-app-section" data-section="{{ $section->id }}" data-section-type="app_google_map" data-pattern="{{ $section->pattern }}" data-layout="{{ $section->pattern === 'split' ? 'split' : 'wide' }}">
    @if ($section->pattern === 'split')
        <x-storefront.embed :section="$section" ratio="standard" />
        <div>
            @if ($section['heading'])
                <h2 class="sugar-section__heading">{{ $section['heading'] }}</h2>
            @endif
            @if ($section['body'])
                <p class="sugar-section__body">{{ $section['body'] }}</p>
            @endif
        </div>
    @else
        @if ($section['heading'])
            <h2 class="sugar-section__heading">{{ $section['heading'] }}</h2>
        @endif
        <x-storefront.embed :section="$section" ratio="panorama" />
        @if ($section['body'])
            <p class="sugar-app-section__caption">{{ $section['body'] }}</p>
        @endif
    @endif
</section>
