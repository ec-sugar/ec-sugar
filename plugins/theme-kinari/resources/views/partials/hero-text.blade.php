{{-- ヒーローの文（見出し・文・ボタンとカテゴリへのリンク）。$section（ヒーローの区画）。
     ボタンの文字と行き先は店の人が変えられ、空なら「新着を見る」で同じページの新着へ。行き先は核が保存の時に店の中の道筋か https の URL だけを通す --}}
<h1 class="font-display max-w-3xl text-4xl leading-tight font-medium tracking-[0.04em] whitespace-pre-line [overflow-wrap:anywhere] sm:text-[52px]" data-test="hero-heading">{{ $section['heading'] ?? $shop->name }}</h1>
<p class="max-w-md text-[15px] whitespace-pre-line text-ink-muted [overflow-wrap:anywhere]" data-test="hero-body">{{ $section['body'] ?? 'オンラインストアの商品です。価格はすべて税込みです。' }}</p>
<div class="mt-2 flex flex-wrap items-center gap-x-7 gap-y-3">
    <a href="{{ $section['button_link'] ?? '#new' }}" class="inline-flex h-12 items-center bg-accent px-8 text-sm tracking-[0.08em] text-on-accent hover:bg-accent-hover hover:text-on-accent" data-test="hero-button">{{ $section['button_label'] ?? '新着を見る' }}</a>
    @if ($sections->get('categories')?->offsetGet('list'))
        <a href="#category" class="inline-flex min-h-11 items-center gap-2.5 text-sm">カテゴリから探す @include('theme-kinari::partials.icon', ['name' => 'arrow', 'size' => 18])</a>
    @endif
</div>
