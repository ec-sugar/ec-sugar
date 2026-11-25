{{--
    LINE をつなぐ前のメールのコード（ADR 0070）。コードと回数は核のログインと同じ。$email（送った先）・$minutes（コードの期限）
--}}
@extends($theme->view('layout'))

@section('title', 'コードの入力 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="line-code">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">コードの入力</h1>
                <p class="sugar-page__lead"><strong class="sugar-account__email">{{ $email }}</strong> に<br>コードをお送りしました。<br>{{ $minutes }} 分以内に入力してください。</p>
            </div>
            @if ($flash['error'])
                <p role="alert" class="sugar-account__error" data-test="line-error">{{ $flash['error'] }}</p>
            @endif
            <form method="post" action="/p/line/code" class="sugar-account__form">
                @csrf
                <label class="sugar-account__label" for="line-code-input">ログインのコード（6 桁）</label>
                <input id="line-code-input" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9０-９ ]{6,8}" maxlength="8" required class="sugar-account__input sugar-account__input--code" data-test="line-code-input">
                <button type="submit" class="sugar-account__primary" data-test="line-code-verify">確認して LINE と連携する</button>
            </form>
        </div>
        <p class="sugar-account__foot">届かないときは、迷惑メールのフォルダもご覧ください。<br><a href="/p/line/email">メールアドレスを入れ直す</a></p>
    </div>
@endsection
