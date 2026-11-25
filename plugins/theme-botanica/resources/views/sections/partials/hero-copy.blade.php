{{-- ヒーローの文字の面の中身（英字の一言・見出し・文・ボタン）。$section（ヒーロー）。
     自由なページでは題が h1 なので、見出しは h2 にする（トップでは h1）。
     ボタンの文字と行き先は店の人が変えられ、空なら「植物を探す」で商品一覧へ。行き先は核が保存の時に店の中の道筋か https の URL だけを通す --}}
<p class="botanica-kicker">{{ $section['kicker'] ?? 'A little more green.' }}</p>
@if ($page->title === null)
    <h1 class="whitespace-pre-line" data-test="hero-heading">{{ $section['heading'] ?? $shop->name }}</h1>
@else
    <h2 class="whitespace-pre-line" data-test="hero-heading">{{ $section['heading'] ?? $shop->name }}</h2>
@endif
<div class="botanica-hero-row">
    @if ($section['body'])
        <p class="max-w-xl text-sm whitespace-pre-line" data-test="hero-body">{{ $section['body'] }}</p>
    @endif
    <a href="{{ $section['button_link'] ?? route('storefront.products') }}" class="botanica-hero-link" data-test="hero-button">{{ $section['button_label'] ?? '植物を探す' }}</a>
</div>
