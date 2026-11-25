{{--
    App「動画」の区画（ADR 0040）。パターンは wide（横いっぱい）・narrow（文の幅）。
    枠（iframe）は核の部品 <x-storefront.embed> が描く（ページを開いた時から。この Blade は並べるだけ）。
    自動の再生・音を消す・操作の表示・繰り返しは、係（VideoSections）が枠の URL に決まった値で入れる。見た目は核の CSS（sections.css の sugar-app-section・sugar-embed）
--}}
<section class="sugar-section sugar-app-section" data-section="{{ $section->id }}" data-section-type="app_video" data-pattern="{{ $section->pattern }}" data-layout="{{ $section->pattern === 'narrow' ? 'narrow' : 'wide' }}">
    @if ($section['heading'])
        <h2 class="sugar-section__heading">{{ $section['heading'] }}</h2>
    @endif
    <x-storefront.embed :section="$section" ratio="wide" />
    @if ($section['caption'])
        <p class="sugar-app-section__caption">{{ $section['caption'] }}</p>
    @endif
</section>
