{{--
    準備中のショップの「準備中です」とパスワードの欄。核の既定の画面で、テーマに password の画面が無いときに使う。
    テーマの外枠（メニュー・カート・フッター）は使わない（準備中の店の中身を見せない）。検索に載せない。App のスクリプトは差し込まない
--}}
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>準備中 — {{ $shop->name }}</title>
    @vite([$theme->stylesheet, $storefrontScript])
</head>
<body>
    <x-storefront::demo-banner />
    <main class="sugar-password" data-test="password-page">
        <div class="sugar-password__inner">
            <p class="sugar-password__shop">{{ $shop->name }}</p>
            <h1 class="sugar-page__title">準備中です</h1>
            <p class="sugar-page__lead">ただいま、ショップの公開の準備をしています。公開まで、もうしばらくお待ちください。</p>
            @if ($flash['error'])
                <p role="alert" class="sugar-password__error" data-test="password-error">{{ $flash['error'] }}</p>
            @endif
            <form method="post" action="{{ route('storefront.password.unlock') }}" class="sugar-password__form">
                @csrf
                <label class="sugar-password__label" for="shop-password">パスワードをお持ちの方</label>
                <input id="shop-password" type="password" name="password" maxlength="64" autocomplete="current-password" required class="sugar-password__input" data-test="password-input">
                <button type="submit" class="sugar-consent__button" data-test="password-submit">ショップに入る</button>
            </form>
        </div>
    </main>
</body>
</html>
