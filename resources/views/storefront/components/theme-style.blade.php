{{--
    店が選んだ見た目（色の組と主な色。ADR 0097。テーマ API 2 の付け足し）。テーマは layout（と password）の <head> の、テーマの CSS の @vite の後に 1 行置くだけ:
        <x-storefront::theme-style />
    中身は核が決める: 公開した値の小さな CSS（/theme/style-{印}.css。:root{--color-…} だけ）を <link> で読む。テーマの CSS のままでよい値
    （最初の組で主な色も組のまま）と、見た目を宣言しないテーマでは何も出さない。インラインの style は使わない（ショップの CSP は style-src 'self'）。
    下書きのプレビュー（編集の画面の真ん中）では下書きの値の CSS（署名つき）を必ず置き、編集の部品（editor.js）が data-sugar-theme-style の印で見つけて読み直す。
    字の組と角の丸みは、テーマが外枠の <html> に data-sugar-font="{{ $themeStyle->font }}" data-sugar-radius="{{ $themeStyle->radius }}" と書いて受ける
--}}
@if (($themeStyleView = \App\Storefront\View\ThemeStyleView::current()) !== null && $themeStyleView->href !== null)
    <link rel="stylesheet" href="{{ $themeStyleView->href }}" data-sugar-theme-style @if ($themeStyleView->preview) data-sugar-theme-style-preview @endif>
@endif
