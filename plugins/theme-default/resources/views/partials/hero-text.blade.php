{{-- ヒーローの文（英字の一言・見出し・文・ボタン）。$section（ヒーローの区画）・$headingId（見出しの id）。
     $onPhoto（任意）が真なら写真の上に白い文字で出す。ボタンの文字と行き先は店の人が変えられ、空なら「商品一覧を見る」で商品一覧へ。
     行き先は核が保存の時に店の中の道筋か https の URL だけを通す --}}
@if ($section['eyebrow'])
    <p class="font-display text-[13px] font-medium tracking-[0.24em] {{ ($onPhoto ?? false) ? 'text-on-accent' : 'text-ink-soft' }}" data-test="intro-eyebrow">{{ $section['eyebrow'] }}</p>
@endif
<h1 id="{{ $headingId }}" class="text-[28px] leading-normal font-semibold tracking-[0.04em] whitespace-pre-line [overflow-wrap:anywhere]" data-test="hero-heading">{{ $section['heading'] ?? $shop->name }}</h1>
<p class="leading-loose whitespace-pre-line [overflow-wrap:anywhere] {{ ($onPhoto ?? false) ? 'text-on-accent' : 'text-ink-soft' }}" data-test="hero-body">{{ $section['body'] ?? 'オンラインストアの商品です。価格はすべて税込みです。' }}</p>
<a href="{{ $section['button_link'] ?? route('storefront.products') }}" class="mt-2 inline-flex h-12 items-center px-11 text-sm font-semibold tracking-[0.08em] {{ ($onPhoto ?? false) ? 'bg-canvas text-ink hover:bg-band hover:text-ink' : 'bg-accent text-on-accent hover:bg-accent-hover hover:text-on-accent' }}" data-test="hero-button">{{ $section['button_label'] ?? '商品一覧を見る' }}</a>
