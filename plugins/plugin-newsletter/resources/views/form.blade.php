{{--
    お知らせのメールの受付（App「メルマガ・一斉メール」。ゲストの画面。会員はマイページの画面 account を出す）。JavaScript を使わないフォーム。
    $message（設定の「受付の画面に添える一言」。無ければ null）・$email（入れ直した値）・$hours（確かめの期限）
--}}
@extends($theme->view('layout'))

@section('title', 'お知らせのメール — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="newsletter-form">
        <h1 class="sugar-page__title">お知らせのメール</h1>
        <p class="sugar-page__lead">{{ $message ?? $shop->name.' から、新しい商品やセールなどのお知らせをメールでお送りします。' }}</p>
        @if ($flash['error'])
            <p role="alert" class="sugar-account__error" data-test="newsletter-error">{{ $flash['error'] }}</p>
        @endif
        @if ($errors->any())
            <p role="alert" class="sugar-account__error" data-test="newsletter-error">{{ $errors->first() }}</p>
        @endif

        <form method="post" action="/p/newsletter" class="sugar-account__section sugar-account__form">
            @csrf
            <div class="sugar-account__field">
                <label class="sugar-account__label" for="newsletter-email">メールアドレス</label>
                <input id="newsletter-email" type="email" name="email" value="{{ $email }}" maxlength="254" autocomplete="email" required class="sugar-account__input" data-test="newsletter-email">
                <p class="sugar-page__note">登録の確認メールをお送りします。{{ $hours }} 時間以内にメールのリンクを開いて登録を確定すると、お知らせが届くようになります。配信は、届いたメールのリンクからいつでも停止できます。</p>
            </div>
            <button type="submit" class="sugar-account__primary" data-test="newsletter-submit">登録する</button>
        </form>
        <p class="sugar-page__note"><a href="/account/login?return=%2Fp%2Fnewsletter%2Faccount">会員の方は、ログインするとマイページから登録できます</a></p>
    </div>
@endsection
