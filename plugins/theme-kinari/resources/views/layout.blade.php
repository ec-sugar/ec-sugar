<!DOCTYPE html>
<html lang="ja" data-motion="{{ $theme->motion }}" data-sugar-font="{{ $themeStyle->font ?? '' }}" data-sugar-radius="{{ $themeStyle->radius ?? '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- 題・説明・canonical・OGP・構造化データは核の部品が出す（ADR 0061）。中はテーマの題で、検索に載せない画面（カート・注文など）で使う --}}
    <x-storefront::seo :seo="$seo">@yield('title', $shop->name)</x-storefront::seo>
    @yield('head')
    @vite([$theme->stylesheet, $storefrontScript])
    {{-- 店が選んだ色（核の部品。テーマの CSS の後に読む。ADR 0097） --}}
    <x-storefront::theme-style />
</head>
<body>
    {{-- デモの店の帯（核の部品。デモの店でだけ描く。ADR 0086） --}}
    <x-storefront::demo-banner />
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:bg-canvas focus:px-3 focus:py-2">本文へ移動</a>

    @if (($announcement = $sections->get('announcement')) && $announcement['text'])
        <p class="bg-band-strong px-4 py-2 text-center text-xs text-ink-muted" data-test="announcement">{{ $announcement['text'] }}</p>
    @endif

    <header class="relative border-b border-line">
        {{-- 狭い画面では左右の列を中身（メニュー・会員とカートの入口）の幅にし、店の名前は真ん中で折り返す（長い名前が入口に重ならない） --}}
        <div class="mx-auto grid min-h-19 max-w-[1360px] grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 px-5 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:gap-4 sm:px-8">
            <div class="flex items-center gap-7">
                {{-- メニュー（スマホでは店内の案内がここだけになる）。<details> なので JS が無くても開け閉めでき、開け閉めの動きは核が付ける --}}
                <details class="-ml-1" data-motion-menu data-test="menu">
                    <summary class="flex size-11 cursor-pointer list-none items-center justify-center [&::-webkit-details-marker]:hidden" aria-label="メニュー">@include('theme-kinari::partials.icon', ['name' => 'menu', 'size' => 22])</summary>
                    <nav aria-label="メニュー" class="absolute inset-x-0 top-full z-40 flex flex-col border-b border-line bg-canvas px-5 py-3 text-sm sm:px-8" data-motion-menu-panel>
                        <a href="{{ route('storefront.products') }}" class="inline-flex min-h-11 items-center">すべての商品</a>
                        <a href="{{ route('storefront.home') }}#category" class="inline-flex min-h-11 items-center">カテゴリ</a>
                        <a href="{{ route('storefront.home') }}#looks" class="inline-flex min-h-11 items-center">コーディネート</a>
                        {{-- 店の人が作った自由なページのうち、メニューに載せるもの（テーマ API 2 の $navPages。ADR 0039 区切り 3） --}}
                        @foreach ($navPages as $link)
                            <a href="{{ $link->url }}" class="inline-flex min-h-11 items-center" @if ($link->current) aria-current="page" @endif data-test="menu-nav-page">{{ $link->title }}</a>
                        @endforeach
                        <a href="{{ route('storefront.orders.lookup') }}" class="inline-flex min-h-11 items-center">ご注文の確認</a>
                        <a href="#about" class="inline-flex min-h-11 items-center">お店について</a>
                    </nav>
                </details>
                {{-- 検索・お気に入りは、機能ができるまで見た目だけ。会員の入口は本物 --}}
                <nav aria-label="店内の案内" class="hidden flex-wrap items-center gap-7 text-[13px] md:flex">
                    <a href="{{ route('storefront.products') }}" class="inline-flex min-h-11 items-center">すべての商品</a>
                    <a href="{{ route('storefront.home') }}#category" class="inline-flex min-h-11 items-center">カテゴリ</a>
                    <a href="{{ route('storefront.home') }}#looks" class="inline-flex min-h-11 items-center">コーディネート</a>
                    @foreach ($navPages as $link)
                        <a href="{{ $link->url }}" class="inline-flex min-h-11 items-center" @if ($link->current) aria-current="page" @endif data-test="nav-page">{{ $link->title }}</a>
                    @endforeach
                    <a href="#about" class="inline-flex min-h-11 items-center">お店について</a>
                </nav>
            </div>
            <a href="{{ route('storefront.home') }}" class="font-display min-w-0 text-center text-[22px] leading-tight tracking-[0.18em] [overflow-wrap:anywhere] sm:text-[26px]" data-test="shop-logo">{{ $shop->name }}</a>
            <div class="flex items-center justify-end gap-1">
                <span class="hidden size-10 items-center justify-center sm:flex" aria-hidden="true">@include('theme-kinari::partials.icon', ['name' => 'search'])</span>
                {{-- 会員の入口（テーマ API 2 の $account。ログイン中はマイページ、ほかはログイン）。絵柄だけなので文字は読み上げに --}}
                <a href="{{ $account->url }}" class="flex size-11 items-center justify-center" aria-label="{{ $account->label }}" @if ($account->current) aria-current="page" @endif data-test="account-link">@include('theme-kinari::partials.icon', ['name' => 'account'])</a>
                <span class="hidden size-10 items-center justify-center sm:flex" aria-hidden="true">@include('theme-kinari::partials.icon', ['name' => 'heart'])</span>
                <a href="{{ route('storefront.cart') }}" class="relative flex size-11 items-center justify-center" aria-label="カート（{{ $cartCount }} 点）" data-test="cart-link" data-cart-link>
                    @include('theme-kinari::partials.icon', ['name' => 'bag'])
                    <span class="absolute top-1.5 right-1 flex min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] leading-4 text-on-accent {{ $cartCount > 0 ? '' : 'hidden' }}" aria-hidden="true" data-test="cart-count" data-cart-count>{{ $cartCount }}</span>
                </a>
            </div>
        </div>
    </header>

    <main id="main">
        @yield('content')
    </main>

    {{-- 横から出るカート。中身は核の部品（resources/storefront/cart.js）が埋める --}}
    <dialog data-cart-drawer aria-labelledby="cart-drawer-title" class="group m-0 ml-auto h-dvh max-h-dvh w-full max-w-md bg-canvas p-0 text-ink backdrop:bg-ink/40" data-test="cart-drawer">
        <div class="flex h-full flex-col">
            <div class="flex items-center justify-between border-b border-line px-6 py-4">
                <h2 id="cart-drawer-title" class="font-mincho text-lg font-medium tracking-[0.06em]">カート</h2>
                <button type="button" class="flex size-11 items-center justify-center text-xl" aria-label="閉じる" data-cart-drawer-close>×</button>
            </div>
            <p class="px-6 pt-4 text-[13px] data-[error]:text-sold" role="status" data-cart-drawer-message data-test="drawer-message"></p>
            <p class="hidden px-6 py-10 text-center text-ink-soft group-data-[empty]:block">カートは空です。</p>
            <ul class="flex flex-1 flex-col divide-y divide-line overflow-y-auto px-6" data-cart-drawer-lines></ul>
            <template data-cart-line-template>
                <li class="flex gap-4 py-4" data-test="drawer-line">
                    <img data-field="photo" alt="" width="72" height="90" class="h-[90px] w-[72px] bg-photo-bg object-cover">
                    <div class="flex flex-1 flex-col gap-1 text-[13px]">
                        <a data-field="link" class="text-sm"><span data-field="title"></span></a>
                        <span class="text-ink-soft" data-field="quantity"></span>
                        <span class="tabular" data-field="total"></span>
                        <span class="bg-band px-2 py-1 text-xs text-sold" data-field="notice"></span>
                    </div>
                </li>
            </template>
            <div class="flex flex-col gap-3 border-t border-line px-6 py-5 group-data-[empty]:hidden">
                <div class="flex items-baseline justify-between"><span class="text-[13px]">小計</span><span class="tabular text-lg" data-cart-drawer-subtotal data-test="drawer-subtotal"></span></div>
                <p class="text-xs text-accent-hover" data-cart-drawer-note data-test="drawer-note"></p>
                <a href="{{ route('storefront.cart') }}" class="inline-flex h-12 items-center justify-center bg-accent px-6 text-sm tracking-[0.08em] text-on-accent hover:bg-accent-hover hover:text-on-accent" data-test="drawer-to-cart">カートを見る</a>
                <button type="button" class="text-xs underline underline-offset-4" data-cart-drawer-close>買い物を続ける</button>
            </div>
        </div>
    </dialog>

    {{-- フッター（区画 footer。外枠が $sections から読むので sections/ に部品を置かない）。パターンは
         default 多列（区画にする前と同じ）・line 1 行（店の名前と案内を 1 行に）・cta 暗い地に呼びかけ（呼びかけの文と商品へのボタン）。
         店の人が隠した・外したときは多列で描く。App のリンクは外枠に 1 つだけ置く決まりなので、ご利用案内の並びは 1 つにして class だけを替える。
         区画の印（data-section）は付けない（どの画面にも出るので、自由なページのプレビューにトップの区画が混ざる。左の一覧から選ぶ） --}}
    <footer id="about" @class([
        'bg-band' => ! in_array($sections->get('footer')?->pattern, ['line', 'cta'], true),
        'border-t border-line bg-canvas' => $sections->get('footer')?->pattern === 'line',
        'bg-ink text-canvas' => $sections->get('footer')?->pattern === 'cta',
    ]) data-test="footer" data-pattern="{{ $sections->get('footer')?->pattern ?? 'default' }}">
        <div @class([
            'mx-auto flex max-w-[1360px] flex-col px-5 sm:px-8',
            'gap-10 pt-14 pb-7' => ! in_array($sections->get('footer')?->pattern, ['line', 'cta'], true),
            'gap-4 py-7' => $sections->get('footer')?->pattern === 'line',
            'gap-10 pt-16 pb-7' => $sections->get('footer')?->pattern === 'cta',
        ])>
            @if ($sections->get('footer')?->pattern === 'cta')
                <div class="flex flex-col items-start gap-6 md:flex-row md:items-end md:justify-between" data-test="footer-call">
                    <div class="flex min-w-0 flex-col gap-3">
                        <span class="font-display text-[15px] tracking-[0.18em] text-line" aria-hidden="true">{{ $shop->name }}</span>
                        <p class="font-mincho max-w-2xl text-[26px] leading-normal tracking-[0.06em] whitespace-pre-line [overflow-wrap:anywhere] sm:text-[32px]">{{ $sections->get('footer')['message'] ?? '季節の新作をご覧ください。' }}</p>
                    </div>
                    <a href="{{ route('storefront.products') }}" class="inline-flex h-12 shrink-0 items-center gap-2.5 bg-canvas px-8 text-sm tracking-[0.08em] text-ink hover:bg-band hover:text-ink">商品を見る @include('theme-kinari::partials.icon', ['name' => 'arrow', 'size' => 16])</a>
                </div>
            @endif
            <div @class([
                'grid grid-cols-2 gap-8 md:grid-cols-[1.4fr_1fr_1fr_1fr_1.6fr]' => ! in_array($sections->get('footer')?->pattern, ['line', 'cta'], true),
                'flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center md:justify-between' => $sections->get('footer')?->pattern === 'line',
                'border-t border-ink-muted pt-6' => $sections->get('footer')?->pattern === 'cta',
            ])>
                @if (! in_array($sections->get('footer')?->pattern, ['line', 'cta'], true))
                    <div class="col-span-2 flex flex-col gap-3 md:col-span-1">
                        <span class="font-display text-xl tracking-[0.18em] [overflow-wrap:anywhere]">{{ $shop->name }}</span>
                        <p class="text-xs text-ink-muted">{{ $shop->name }}のオンラインストアです。</p>
                    </div>
                    <nav aria-label="商品" class="flex flex-col gap-1.5 text-xs">
                        <span class="mb-1 text-[13px] font-semibold">商品</span>
                        <a href="{{ route('storefront.products') }}">すべての商品</a>
                        <a href="{{ route('storefront.home') }}#new">新着</a>
                        <a href="{{ route('storefront.home') }}#category">カテゴリから探す</a>
                        <a href="{{ route('storefront.home') }}#looks">コーディネート</a>
                    </nav>
                    <div class="flex flex-col gap-1.5 text-xs">
                        <span class="mb-1 text-[13px] font-semibold">お店について</span>
                        {{-- 店の人が作った自由なページのうち、メニューに載せるもの。無ければ見た目だけの項目 --}}
                        @forelse ($navPages as $link)
                            <a href="{{ $link->url }}" @if ($link->current) aria-current="page" @endif data-test="footer-nav-page">{{ $link->title }}</a>
                        @empty
                            <span>ブランドについて</span>
                            <span>素材と作り</span>
                        @endforelse
                    </div>
                @elseif ($sections->get('footer')?->pattern === 'line')
                    <span class="font-display text-lg tracking-[0.18em] [overflow-wrap:anywhere]">{{ $shop->name }}</span>
                @endif
                <nav aria-label="ご利用案内" @class([
                    'flex text-xs',
                    'flex-col gap-1.5' => ! in_array($sections->get('footer')?->pattern, ['line', 'cta'], true),
                    'flex-wrap items-center gap-x-6' => in_array($sections->get('footer')?->pattern, ['line', 'cta'], true),
                    '[&_a]:text-line [&_a:hover]:text-canvas' => $sections->get('footer')?->pattern === 'cta',
                ])>
                    @if (! in_array($sections->get('footer')?->pattern, ['line', 'cta'], true))
                        <span class="mb-1 text-[13px] font-semibold">ご利用案内</span>
                        <span>配送と送料</span>
                        <span>返品・交換</span>
                    @else
                        <a href="{{ route('storefront.products') }}" class="inline-flex min-h-11 items-center">すべての商品</a>
                        @foreach ($navPages as $link)
                            <a href="{{ $link->url }}" class="inline-flex min-h-11 items-center" @if ($link->current) aria-current="page" @endif data-test="footer-nav-page">{{ $link->title }}</a>
                        @endforeach
                    @endif
                    <a href="{{ route('storefront.orders.lookup') }}" @class(['inline-flex min-h-11 items-center' => in_array($sections->get('footer')?->pattern, ['line', 'cta'], true)])>ご注文の確認</a>
                    <a href="{{ route('storefront.legal') }}" @class(['inline-flex min-h-11 items-center' => in_array($sections->get('footer')?->pattern, ['line', 'cta'], true)])>特定商取引法に基づく表記</a>
                    {{-- プライバシーポリシー（ADR 0094）。本文がある店だけ。外部送信の公表のリンクの前に --}}
                    @if ($shop->privacyUrl)
                        <a href="{{ $shop->privacyUrl }}" @class(['inline-flex min-h-11 items-center' => in_array($sections->get('footer')?->pattern, ['line', 'cta'], true)]) data-test="privacy-link">プライバシーポリシー</a>
                    @endif
                    <x-storefront.app-links :apps="$apps" class="{{ in_array($sections->get('footer')?->pattern, ['line', 'cta'], true) ? 'inline-flex min-h-11 items-center' : '' }}" />
                </nav>
                @if (! in_array($sections->get('footer')?->pattern, ['line', 'cta'], true))
                    <div class="col-span-2 flex flex-col gap-2.5 md:col-span-1">
                        <span class="text-[13px] font-semibold">お知らせを受け取る</span>
                        <span class="text-xs text-ink-muted">新着とセールのお知らせをお送りします（準備中）。</span>
                        <span class="flex h-11 items-center justify-between border border-line-strong bg-canvas px-3 text-[13px] text-ink-soft" aria-hidden="true">メールアドレス @include('theme-kinari::partials.icon', ['name' => 'arrow', 'size' => 16])</span>
                    </div>
                @endif
            </div>
            <div @class([
                'flex flex-wrap justify-between gap-4 text-[11px]',
                'border-t border-line pt-5 text-ink-muted' => ! in_array($sections->get('footer')?->pattern, ['line', 'cta'], true),
                'text-ink-muted' => $sections->get('footer')?->pattern === 'line',
                'text-line' => $sections->get('footer')?->pattern === 'cta',
            ])>
                <span>© {{ $shop->name }}</span>
                @if (! in_array($sections->get('footer')?->pattern, ['line', 'cta'], true))
                    <a href="{{ route('storefront.legal') }}">特定商取引法に基づく表記</a>
                @endif
            </div>
        </div>
    </footer>
    {{-- App の差し込み口（核が描く。同意の帯と App のスクリプト。ADR 0034） --}}
    <x-storefront.apps :apps="$apps" />
</body>
</html>
