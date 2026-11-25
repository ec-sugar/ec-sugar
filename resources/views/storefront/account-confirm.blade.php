{{--
    会員の本人の再確認（ADR 0044 の 3）。核が描く。大事な操作（退会など）の前に、その場でメールに新しいコードを送って入れてもらう。
    送るのはボタンを押した時だけ（画面を開いただけでは送らない）
--}}
@extends($theme->view('layout'))

@section('title', 'ご本人の確認 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="account-confirm">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">ご本人の確認</h1>
                <p class="sugar-page__lead">大切な操作の前に、ご本人であることを確認します。</p>
            </div>
            @if ($flash['error'])
                <p role="alert" class="sugar-account__error" data-test="account-error">{{ $flash['error'] }}</p>
            @endif
            @if ($hasPasskeys)
                <x-storefront::passkey-form mode="get" :action="route('storefront.account.confirm.passkey')" :options="route('storefront.account.confirm.passkey.options')" :next="$next" label="パスキーで確認する" test="passkey-confirm" :primary="true" />
            @endif
            @if ($sent)
                <form method="post" action="{{ route('storefront.account.confirm.verify') }}" class="sugar-account__form">
                    @csrf
                    <input type="hidden" name="next" value="{{ $next }}">
                    <label class="sugar-account__label" for="confirm-code"><span class="sugar-account__email">{{ $email }}</span> に届いた確認コード（{{ $minutes }} 分以内に入力）</label>
                    <input id="confirm-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required class="sugar-account__input sugar-account__input--code" data-test="confirm-code">
                    <button type="submit" class="sugar-account__primary" data-test="confirm-verify">確認する</button>
                </form>
            @endif
            <form method="post" action="{{ route('storefront.account.confirm.send') }}" class="sugar-account__form">
                @csrf
                <input type="hidden" name="next" value="{{ $next }}">
                <button type="submit" class="{{ $sent || $hasPasskeys ? 'sugar-account__secondary' : 'sugar-account__primary' }}" data-test="confirm-send">{{ $sent ? '確認コードを再送する' : 'メールで確認コードを受け取る' }}</button>
            </form>
        </div>
        <p class="sugar-account__foot"><a href="{{ route('storefront.account') }}">マイページへ戻る</a></p>
    </div>
@endsection
