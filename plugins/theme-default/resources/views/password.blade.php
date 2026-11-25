{{--
    準備中のショップの「準備中です」とパスワードの欄。外枠（layout）を使わない 1 枚の画面（メニュー・カート・フッターを出さない）。
    渡すデータ: $flash（error: パスワードが違う・回数が多すぎる）。route('storefront.password.unlock') へ POST（@csrf、password）
--}}
<!DOCTYPE html>
<html lang="ja" data-sugar-font="{{ $themeStyle->font ?? '' }}" data-sugar-radius="{{ $themeStyle->radius ?? '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>準備中 — {{ $shop->name }}</title>
    @vite([$theme->stylesheet, $storefrontScript])
    {{-- 店が選んだ色（核の部品。テーマの CSS の後に読む。ADR 0097） --}}
    <x-storefront::theme-style />
</head>
<body>
    {{-- デモの店の帯（核の部品。デモの店でだけ描く。ADR 0086） --}}
    <x-storefront::demo-banner />
    <main class="flex min-h-dvh items-center justify-center bg-canvas px-4 py-12 text-ink" data-test="password-page">
        <div class="flex w-full max-w-sm flex-col items-center gap-6 text-center">
            <span class="flex items-center gap-3">
                @include('theme-default::partials.cube', ['tone' => 'mark', 'size' => 34])
                <span class="font-display text-2xl font-medium tracking-[0.16em]">{{ $shop->name }}</span>
            </span>
            <h1 class="text-xl font-semibold">準備中です</h1>
            <p class="text-sm text-ink-soft">ただいま、ショップの公開の準備をしています。公開まで、もうしばらくお待ちください。</p>
            @if ($flash['error'])
                <p role="alert" class="w-full rounded-xl bg-band px-5 py-3 text-sm text-sold" data-test="password-error">{{ $flash['error'] }}</p>
            @endif
            <form method="post" action="{{ route('storefront.password.unlock') }}" class="flex w-full flex-col gap-3 text-left">
                @csrf
                <label for="shop-password" class="text-sm font-medium">パスワードをお持ちの方</label>
                <input id="shop-password" type="password" name="password" maxlength="64" autocomplete="current-password" required class="h-11 w-full rounded-xl border border-line bg-canvas px-4" data-test="password-input">
                <button type="submit" class="inline-flex h-12 items-center justify-center rounded-xl bg-accent px-8 font-medium text-on-accent hover:bg-accent-hover" data-test="password-submit">入る</button>
            </form>
        </div>
    </main>
</body>
</html>
