{{--
    パスキーを使うフォーム（ADR 0044 の 3）。核の部品。パスキーを使えるブラウザでだけ出す（passkeys.js が hidden を外す）。
    押すと passkeys.js がオプションをもらってブラウザのパスキーを呼び、答えを credential に入れてこのフォームで送る。JS が無ければ出ない（メールのコードで入れる）。
    個人情報の画面（navigation-only の印の経路。ADR 0088）では、オプションを読むための画面の鍵（PageKey）を data-sugar-page-key に書く（ほかの画面では空）
--}}
@props(['mode', 'action', 'options', 'label', 'next' => null, 'test', 'or' => false, 'primary' => false])
<form method="post" action="{{ $action }}" class="sugar-account__form" data-passkey="{{ $mode }}" data-passkey-options="{{ $options }}" data-sugar-page-key="{{ \App\Storefront\Access\PageKey::forPage(request()) }}" hidden data-test="{{ $test }}">
    @if ($or)
        <p class="sugar-account__or" aria-hidden="true">または</p>
    @endif
    @csrf
    <input type="hidden" name="credential" value="">
    @if ($next !== null)
        <input type="hidden" name="next" value="{{ $next }}">
    @endif
    {{ $slot }}
    <button type="submit" class="{{ $primary ? 'sugar-account__primary' : 'sugar-account__secondary' }}" data-test="{{ $test }}-button">{{ $label }}</button>
    <p role="alert" class="sugar-account__error" data-passkey-error hidden></p>
</form>
