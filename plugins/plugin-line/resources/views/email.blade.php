{{--
    LINE でログインした後、まだ会員とつないでいない時（ADR 0070）。メールを入れてもらい、核のログインと同じ 6 桁のコードを送る。
    LINE から来た値で会員に自動でつながない（メールの持ち主を確かめてからつなぐ）。$email（入れ直しの時の値）
--}}
@extends($theme->view('layout'))

@section('title', 'LINE と連携する — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="line-email">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">メールアドレスの確認</h1>
                <p class="sugar-page__lead">LINE での確認が済みました。<br>続けて、メールアドレスを入力してください。<br>6 桁のコードをお送りします。コードを入力すると、LINE との連携が完了します。</p>
            </div>
            @if ($flash['error'])
                <p role="alert" class="sugar-account__error" data-test="line-error">{{ $flash['error'] }}</p>
            @endif
            <form method="post" action="/p/line/email" class="sugar-account__form">
                @csrf
                <label class="sugar-account__label" for="line-email">メールアドレス</label>
                <input id="line-email" type="email" name="email" value="{{ $email }}" maxlength="254" autocomplete="email" placeholder="you@example.com" required class="sugar-account__input" data-test="line-email-input">
                <button type="submit" class="sugar-account__primary" data-test="line-email-send">コードを送る</button>
            </form>
        </div>
        <p class="sugar-account__foot">会員の方は、登録済みのメールアドレスを入力すると、その会員アカウントと LINE を連携します。</p>
    </div>
@endsection
