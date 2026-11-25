{{--
    会員のメールアドレスを変える（ADR 0044 の 3）。核が描く。本人の再確認の後だけ開ける。
    新しいアドレスへコードを送り、合えば変える。前のアドレスへ知らせる
--}}
@extends($theme->view('layout'))

@section('title', 'メールアドレスの変更 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="account-email-page">
        <p class="sugar-account__back"><a href="{{ route('storefront.account') }}">← マイページ</a></p>
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">メールアドレスの変更</h1>
                <p class="sugar-page__lead">現在のメールアドレス<br><strong class="sugar-account__email">{{ $current }}</strong></p>
            </div>
            @if ($flash['error'])
                <p role="alert" class="sugar-account__error" data-test="account-error">{{ $flash['error'] }}</p>
            @endif
            @if ($pending)
                <form method="post" action="{{ route('storefront.account.email.verify') }}" class="sugar-account__form">
                    @csrf
                    <label class="sugar-account__label" for="email-code"><span class="sugar-account__email">{{ $pending }}</span> に届いた確認コード（{{ $minutes }} 分以内に入力）</label>
                    <input id="email-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required class="sugar-account__input sugar-account__input--code" data-test="email-code">
                    <button type="submit" class="sugar-account__primary" data-test="email-verify">変更する</button>
                </form>
                <p class="sugar-account__or" aria-hidden="true">または</p>
            @endif
            <form method="post" action="{{ route('storefront.account.email.send') }}" class="sugar-account__form">
                @csrf
                <label class="sugar-account__label" for="new-email">新しいメールアドレス</label>
                <input id="new-email" type="email" name="email" value="{{ $email }}" maxlength="254" autocomplete="email" required class="sugar-account__input" data-test="new-email">
                <button type="submit" class="{{ $pending ? 'sugar-account__secondary' : 'sugar-account__primary' }}" data-test="email-send">{{ $pending ? '確認コードを再送する' : '新しいアドレスに確認コードを送る' }}</button>
            </form>
        </div>
        <p class="sugar-account__foot">変更すると、ほかの端末ではログアウトされます。変更前のアドレスにもお知らせのメールを送ります。</p>
    </div>
@endsection
