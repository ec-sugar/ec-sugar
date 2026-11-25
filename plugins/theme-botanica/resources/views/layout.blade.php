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
<body class="botanica" data-theme="botanica">
    {{-- デモの店の帯（核の部品。デモの店でだけ描く。ADR 0086） --}}
    <x-storefront::demo-banner />
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:bg-canvas focus:px-3 focus:py-2">本文へ移動</a>

    @if (($announcement = $sections->get('announcement')) && $announcement['text'])
        <p class="botanica-announcement" data-test="announcement">{{ $announcement['text'] }}</p>
    @endif

    <header class="botanica-header">
        <p class="botanica-header-caption">Plants for everyday life.</p>
        <a href="{{ route('storefront.home') }}" class="botanica-logo" data-test="shop-logo">{{ $shop->name }}</a>
        <div class="botanica-tools">
            <a href="{{ route('storefront.products') }}" aria-label="商品を検索" class="botanica-icon-link">@include('theme-botanica::partials.icon', ['name' => 'search'])</a>
            {{-- 会員の入口（テーマ API 2 の $account。ログイン中はマイページ、ほかはログイン）。ご注文の確認は下の案内に --}}
            <a href="{{ $account->url }}" aria-label="{{ $account->label }}" class="botanica-icon-link" @if ($account->current) aria-current="page" @endif data-test="account-link">@include('theme-botanica::partials.icon', ['name' => 'account'])</a>
            <a href="{{ route('storefront.cart') }}" class="botanica-icon-link relative" aria-label="カート（{{ $cartCount }} 点）" data-test="cart-link" data-cart-link>
                @include('theme-botanica::partials.icon', ['name' => 'bag'])
                <span class="absolute top-0 right-0 min-w-4 rounded-full bg-accent px-1 text-center text-[10px] text-on-accent {{ $cartCount > 0 ? '' : 'hidden' }}" aria-hidden="true" data-test="cart-count" data-cart-count>{{ $cartCount }}</span>
            </a>
        </div>
    </header>
    <nav aria-label="店内の案内" class="botanica-nav">
        <a href="{{ route('storefront.home') }}">ホーム</a>
        <a href="{{ route('storefront.products') }}">すべての商品</a>
        <a href="{{ route('storefront.home') }}#new">新着</a>
        {{-- 店の人が作った自由なページのうち、メニューに載せるもの（テーマ API 2 の $navPages。ADR 0039 区切り 3）。狭い画面でも同じ並びが折り返す --}}
        @foreach ($navPages as $link)
            <a href="{{ $link->url }}" @if ($link->current) aria-current="page" @endif data-test="nav-page">{{ $link->title }}</a>
        @endforeach
        <a href="{{ route('storefront.orders.lookup') }}">ご注文の確認</a>
    </nav>

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

    <footer id="about" class="botanica-footer">
        <div class="botanica-footer-main">
            <div>
                <p class="botanica-logo">{{ $shop->name }}</p>
                <p class="mt-4 text-xs text-ink-soft">{{ $shop->name }}のオンラインストアです。</p>
            </div>
            <nav aria-label="商品" class="flex flex-col gap-3 text-xs">
                <span class="mb-2 tracking-widest">SHOP</span>
                <a href="{{ route('storefront.products') }}">すべての商品</a>
                <a href="{{ route('storefront.home') }}#new">新着商品</a>
                @foreach ($navPages as $link)
                    <a href="{{ $link->url }}" data-test="footer-page">{{ $link->title }}</a>
                @endforeach
            </nav>
            <nav aria-label="ご利用案内" class="flex flex-col gap-3 text-xs">
                <span class="mb-2 tracking-widest">INFORMATION</span>
                <a href="{{ route('storefront.orders.lookup') }}">ご注文の確認</a>
                <a href="{{ route('storefront.legal') }}">特定商取引法に基づく表記</a>
                {{-- プライバシーポリシー（ADR 0094）。本文がある店だけ。外部送信の公表のリンクの前に --}}
                @if ($shop->privacyUrl)
                    <a href="{{ $shop->privacyUrl }}" data-test="privacy-link">プライバシーポリシー</a>
                @endif
                <x-storefront.app-links :apps="$apps" />
            </nav>
        </div>
        <p class="mt-12 border-t border-line pt-5 text-[11px] text-ink-soft">© {{ $shop->name }}</p>
    </footer>
    {{-- App の差し込み口（核が描く。同意の帯と App のスクリプト。ADR 0034） --}}
    <x-storefront.apps :apps="$apps" />
</body>
</html>
