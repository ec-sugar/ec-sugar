{{-- テスト用のテーマ（今の書き方: pages を書かない決まった並び。自由なページの雛形も page の画面も持たない）。外枠だけの最小の形 --}}
<!DOCTYPE html>
<html lang="ja">
<head><meta charset="utf-8"><title>@yield('title', $shop->name)</title>@vite([$storefrontScript])</head>
<body>
    <main id="main">@yield('content')</main>
    <x-storefront.apps :apps="$apps" />
</body>
</html>
