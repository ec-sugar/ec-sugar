{{-- テスト用のテーマ（テーマ API 2）。区画の中身の受け渡しを確かめるだけの最小の形 --}}
<!DOCTYPE html>
<html lang="ja" data-sugar-font="{{ $themeStyle->font ?? '' }}" data-sugar-radius="{{ $themeStyle->radius ?? '' }}">
<head><meta charset="utf-8"><title>{{ $shop->name }}</title>@vite([$storefrontScript])<x-storefront::theme-style /></head>
<body>
    <x-storefront::demo-banner />
    @if ($announcement = $sections->get('announcement'))
        @if ($announcement['text'])
            <p data-test="announcement">{{ $announcement['text'] }}</p>
        @endif
    @endif
    @if ($navPages !== [])
        <nav data-test="nav-pages">
            @foreach ($navPages as $link)
                <a href="{{ $link->url }}" @if ($link->current) aria-current="page" @endif>{{ $link->title }}</a>
            @endforeach
        </nav>
    @endif
    <main id="main">@yield('content')</main>
    <x-storefront.apps :apps="$apps" />
</body>
</html>
