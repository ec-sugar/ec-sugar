{{--
    会員のログインのコードを入れる（ADR 0044 の 3）。核が描く。どのメールに送ったかは、このブラウザのセッションに置いてある。
    メールの有る無しで画面を変えない（いつも「送りました」）
--}}
@extends($theme->view('layout'))

@section('title', '確認コードの入力 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="account-code">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">確認コードの入力</h1>
                <p class="sugar-page__lead"><strong class="sugar-account__email">{{ $email }}</strong> に<br>確認コードをお送りしました。<br>{{ $minutes }} 分以内に入力してください。</p>
            </div>
            @if ($flash['error'])
                <p role="alert" class="sugar-account__error" data-test="account-error">{{ $flash['error'] }}</p>
            @endif
            <form method="post" action="{{ route('storefront.account.verify') }}" class="sugar-account__form">
                @csrf
                <label class="sugar-account__label" for="account-code-input">確認コード（6 桁）</label>
                <input id="account-code-input" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9０-９ ]{6,8}" maxlength="8" required class="sugar-account__input sugar-account__input--code" data-test="account-code-input">
                <button type="submit" class="sugar-account__primary" data-test="account-verify">ログインする</button>
            </form>
        </div>
        <p class="sugar-account__foot">メールが届かないときは、迷惑メールのフォルダもご確認ください。<br><a href="{{ route('storefront.account.login') }}">メールアドレスを入力し直す</a></p>
    </div>
@endsection
