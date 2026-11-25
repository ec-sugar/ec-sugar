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

    {{-- 区画は theme.json の宣言どおりに $sections から読む（テーマ API 2。ADR 0028）。店の人が隠した区画は get が null --}}
    @if (($announcement = $sections->get('announcement')) && $announcement['text'])
        <p class="bg-ink px-4 py-2 text-center text-xs tracking-[0.06em] text-on-accent" data-test="announcement">{{ $announcement['text'] }}</p>
    @endif

    <header class="mx-auto flex max-w-[1200px] flex-col gap-3 px-4 pt-5 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <a href="{{ route('storefront.home') }}" class="flex items-center gap-3 text-ink hover:text-ink">
                @include('theme-default::partials.cube', ['tone' => 'mark', 'size' => 34])
                <span class="font-display text-xl font-medium tracking-[0.12em] sm:text-2xl sm:tracking-[0.16em]">{{ $shop->name }}</span>
            </a>
            {{-- スマホではロゴとカートを 1 行目、検索を 2 行目に --}}
            <form action="{{ route('storefront.products') }}" method="get" role="search" class="order-last flex h-11 basis-full items-center rounded-full border border-line pr-1 pl-4 sm:order-none sm:max-w-[260px] sm:flex-[1_1_200px]">
                <label for="header-search" class="sr-only">商品を探す</label>
                <input id="header-search" type="search" name="q" maxlength="100" placeholder="商品を探す" class="h-full min-w-0 flex-1 bg-transparent text-[13px] text-ink placeholder:text-ink-soft focus:outline-none">
                <button type="submit" class="flex size-9 items-center justify-center rounded-full text-ink" aria-label="探す">@include('theme-default::partials.icon', ['name' => 'search'])</button>
            </form>
            {{-- スマホでもロゴと同じ 1 行に収める（カートは絵柄と数だけ。読み上げの名前は aria-label に残す） --}}
            <div class="flex shrink-0 items-center justify-end gap-1 sm:flex-[1_1_200px]">
                {{-- 会員の入口（テーマ API 2 の $account。ログイン中はマイページ、ほかはログイン）。狭い画面では絵柄だけにし、文字は読み上げに残す --}}
                <a href="{{ $account->url }}" class="inline-flex h-11 min-w-11 items-center justify-center gap-2 rounded-full px-2.5 text-[13px] text-ink hover:text-ink sm:px-3.5" @if ($account->current) aria-current="page" @endif data-test="account-link">
                    @include('theme-default::partials.icon', ['name' => 'account'])
                    <span class="sr-only sm:not-sr-only">{{ $account->label }}</span>
                </a>
                <a href="{{ route('storefront.cart') }}" class="inline-flex h-11 items-center gap-2 rounded-full bg-photo px-3.5 text-[13px] text-ink hover:text-ink sm:px-4.5" aria-label="カート（{{ $cartCount }} 点）" data-test="cart-link" data-cart-link>
                    @include('theme-default::partials.icon', ['name' => 'bag'])
                    <span class="hidden sm:inline">カート</span>
                    <span class="tabular flex h-5 min-w-5 items-center justify-center rounded-full bg-ink px-1.5 text-[11px] text-on-accent {{ $cartCount > 0 ? '' : 'hidden' }}" aria-hidden="true" data-test="cart-count" data-cart-count>{{ $cartCount }}</span>
                </a>
            </div>
        </div>
        <nav aria-label="店内の案内" class="flex flex-wrap justify-center gap-x-7 text-[13px] font-medium">
            <a href="{{ route('storefront.products') }}" class="inline-flex min-h-11 items-center">すべての商品</a>
            {{-- 店の人が作った自由なページのうち、メニューに載せるもの（テーマ API 2 の $navPages。ADR 0039 区切り 3） --}}
            @foreach ($navPages as $link)
                <a href="{{ $link->url }}" class="inline-flex min-h-11 items-center" @if ($link->current) aria-current="page" @endif data-test="nav-page">{{ $link->title }}</a>
            @endforeach
            <a href="{{ route('storefront.orders.lookup') }}" class="inline-flex min-h-11 items-center">ご注文の確認</a>
        </nav>
    </header>

    <main id="main" class="mx-auto flex max-w-[1200px] flex-col px-4 pb-24 sm:px-6 @yield('main-class', 'gap-10 pt-10')">
        @yield('content')
    </main>

    {{-- 横から出るカート。中身は核の部品（resources/storefront/cart.js）が埋める --}}
    <dialog data-cart-drawer aria-labelledby="cart-drawer-title" class="group m-0 ml-auto h-dvh max-h-dvh w-full max-w-110 bg-canvas p-0 text-ink backdrop:bg-ink/40" data-test="cart-drawer">
        <div class="flex h-full flex-col">
            <div class="flex items-center justify-between border-b border-line px-6 py-4">
                <h2 id="cart-drawer-title" class="flex items-baseline gap-2.5">
                    <span class="font-display text-base font-medium tracking-[0.22em]" aria-hidden="true">CART</span>
                    <span class="text-xs font-medium text-ink-soft">カート</span>
                </h2>
                <button type="button" class="flex size-11 items-center justify-center text-2xl" aria-label="閉じる" data-cart-drawer-close>×</button>
            </div>
            <p class="flex items-center gap-2.5 bg-band px-6 py-3.5 text-[13px] empty:py-0 data-[error]:bg-canvas data-[error]:text-danger" role="status" data-cart-drawer-message data-test="drawer-message"></p>
            <p class="hidden px-6 py-10 text-center text-ink-soft group-data-[empty]:block">カートは空です。</p>
            <ul class="flex flex-1 flex-col divide-y divide-line overflow-y-auto px-6" data-cart-drawer-lines></ul>
            <template data-cart-line-template>
                <li class="flex gap-4 py-4.5" data-test="drawer-line">
                    <img data-field="photo" alt="" width="76" height="76" class="size-19 bg-photo object-cover">
                    <div class="flex flex-1 flex-col gap-0.5 text-[13px]">
                        <a data-field="link" class="text-sm font-semibold"><span data-field="title"></span></a>
                        <span class="text-ink-soft" data-field="quantity"></span>
                        <span class="tabular" data-field="total"></span>
                        <span class="text-xs text-sold" data-field="notice"></span>
                    </div>
                </li>
            </template>
            <div class="flex flex-col gap-3 border-t border-line px-6 pt-5 pb-6 group-data-[empty]:hidden">
                <div class="flex items-baseline justify-between"><span class="text-[13px]">小計</span><span class="tabular text-xl" data-cart-drawer-subtotal data-test="drawer-subtotal"></span></div>
                <p class="text-xs text-ink-soft" data-cart-drawer-note data-test="drawer-note"></p>
                <a href="{{ route('storefront.cart') }}" class="inline-flex h-13 items-center justify-center bg-accent text-[15px] font-semibold tracking-[0.08em] text-on-accent hover:bg-accent-hover hover:text-on-accent" data-test="drawer-to-cart">カートを見る</a>
                <button type="button" class="min-h-11 text-[13px] underline underline-offset-4" data-cart-drawer-close>買い物を続ける</button>
            </div>
        </div>
    </dialog>

    {{-- フッター（区画 footer。外枠が $sections から読むので sections/ に部品を置かない）。パターンは
         default 暗い地（真ん中に寄せる。区画にする前と同じ）・columns 多列（帯の色の地に、店の名前と案内の列）・line 1 行（白い地に細い線）。
         店の人が隠した・外したときは暗い地で描き、Powered by は出さない。App のリンクは外枠に 1 つだけ置く決まりなので、
         形は 1 つにして class だけを替える。
         区画の印（data-section）は付けない（どの画面にも出るので、自由なページのプレビューにトップの区画が混ざる。左の一覧から選ぶ） --}}
    <footer @class([
        'bg-ink text-on-accent' => ! in_array($sections->get('footer')?->pattern, ['columns', 'line'], true),
        'bg-band text-ink' => $sections->get('footer')?->pattern === 'columns',
        'border-t border-line bg-canvas text-ink' => $sections->get('footer')?->pattern === 'line',
    ]) data-test="footer" data-pattern="{{ $sections->get('footer')?->pattern ?? 'default' }}">
        <div @class([
            'mx-auto flex max-w-[1200px] px-4 sm:px-6',
            'flex-col items-center gap-7 pt-14 pb-7' => ! in_array($sections->get('footer')?->pattern, ['columns', 'line'], true),
            'flex-col gap-8 pt-14 pb-7 md:grid md:grid-cols-[1.4fr_2fr] md:gap-x-12' => $sections->get('footer')?->pattern === 'columns',
            'flex-col items-start gap-3 py-6 md:flex-row md:flex-wrap md:items-center md:justify-between md:gap-x-8' => $sections->get('footer')?->pattern === 'line',
        ])>
            <nav aria-label="店の案内" @class([
                'flex text-[13px]',
                'flex-wrap justify-center gap-x-7' => ! in_array($sections->get('footer')?->pattern, ['columns', 'line'], true),
                'flex-col gap-0 sm:grid sm:grid-cols-2 sm:gap-x-8 md:col-start-2 md:row-span-2 md:row-start-1 lg:grid-cols-3' => $sections->get('footer')?->pattern === 'columns',
                'order-2 flex-wrap gap-x-6' => $sections->get('footer')?->pattern === 'line',
            ])>
                <a href="{{ route('storefront.products') }}" @class(['inline-flex min-h-11 items-center', 'text-line hover:text-on-accent' => ! in_array($sections->get('footer')?->pattern, ['columns', 'line'], true)])>すべての商品</a>
                {{-- 自由なページのうちメニューに載せるもの（暗い地は区画にする前と同じく出さない） --}}
                @if (in_array($sections->get('footer')?->pattern, ['columns', 'line'], true))
                    @foreach ($navPages as $link)
                        <a href="{{ $link->url }}" class="inline-flex min-h-11 items-center" @if ($link->current) aria-current="page" @endif data-test="footer-nav-page">{{ $link->title }}</a>
                    @endforeach
                @endif
                <a href="{{ route('storefront.orders.lookup') }}" @class(['inline-flex min-h-11 items-center', 'text-line hover:text-on-accent' => ! in_array($sections->get('footer')?->pattern, ['columns', 'line'], true)])>ご注文の確認</a>
                <a href="{{ route('storefront.legal') }}" @class(['inline-flex min-h-11 items-center', 'text-line hover:text-on-accent' => ! in_array($sections->get('footer')?->pattern, ['columns', 'line'], true)])>特定商取引法に基づく表記</a>
                {{-- プライバシーポリシー（ADR 0094）。本文がある店だけ。外部送信の公表のリンクの前に --}}
                @if ($shop->privacyUrl)
                    <a href="{{ $shop->privacyUrl }}" @class(['inline-flex min-h-11 items-center', 'text-line hover:text-on-accent' => ! in_array($sections->get('footer')?->pattern, ['columns', 'line'], true)]) data-test="privacy-link">プライバシーポリシー</a>
                @endif
                <x-storefront.app-links :apps="$apps" class="inline-flex min-h-11 items-center {{ in_array($sections->get('footer')?->pattern, ['columns', 'line'], true) ? '' : 'text-line hover:text-on-accent' }}" />
            </nav>
            <span @class(['flex items-center gap-3', 'md:col-start-1 md:row-start-1' => $sections->get('footer')?->pattern === 'columns', 'order-1' => $sections->get('footer')?->pattern === 'line'])>
                @include('theme-default::partials.cube', ['tone' => in_array($sections->get('footer')?->pattern, ['columns', 'line'], true) ? 'mark' : 'inverse', 'size' => $sections->get('footer')?->pattern === 'line' ? 22 : 26])
                <span @class(['font-display font-medium tracking-[0.16em] [overflow-wrap:anywhere]', 'text-xl' => $sections->get('footer')?->pattern !== 'line', 'text-base' => $sections->get('footer')?->pattern === 'line'])>{{ $shop->name }}</span>
            </span>
            <span @class([
                'flex flex-wrap gap-x-4 text-[11px]',
                'justify-center text-ink-faint' => ! in_array($sections->get('footer')?->pattern, ['columns', 'line'], true),
                'text-ink-soft md:col-start-1 md:row-start-2 md:self-end' => $sections->get('footer')?->pattern === 'columns',
                'order-3 text-ink-soft' => $sections->get('footer')?->pattern === 'line',
            ])>
                <span>© {{ $shop->name }}</span>
                {{-- 店の人が「出さない」を選んだときだけ外す（フッターの区画を隠しても出さない） --}}
                @if (($footer = $sections->get('footer')) && $footer['powered'] !== 'hide')
                    <span data-test="powered-by">Powered by EC-SUGAR</span>
                @endif
            </span>
        </div>
    </footer>
    {{-- App の差し込み口（核が描く。同意の帯と App のスクリプト。ADR 0034） --}}
    <x-storefront.apps :apps="$apps" />
</body>
</html>
