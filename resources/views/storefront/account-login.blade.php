{{--
    会員のログイン（ADR 0044 の 3）。核が描く。メールを入れると 6 桁のコードを送る（会員の登録の画面は別に無い。コードで一度入れば会員）。
    形は真ん中のカード（運営の判断、2026-11-01 の A）。テーマの外枠の中に描き、App のスクリプトは差し込まない（個人情報の画面）
--}}
@extends($theme->view('layout'))

@section('title', 'ログイン — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="account-login">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">ログイン</h1>
                <p class="sugar-page__lead">メールアドレスに 6 桁の確認コードをお送りします。<br>パスワードは不要です。</p>
            </div>
            @if ($flash['error'])
                <p role="alert" class="sugar-account__error" data-test="account-error">{{ $flash['error'] }}</p>
            @endif
            {{-- デモの店（ADR 0086）: このブラウザだけの使い捨ての会員で入る（メールのコードは「送るはずだったメール」に出る） --}}
            @if (\App\Core\Sample\Demo\DemoShop::active())
                <form method="post" action="{{ route('storefront.demo.member') }}" class="sugar-account__form">
                    @csrf
                    <button type="submit" class="sugar-account__primary" data-test="demo-member-login">デモの会員でログイン</button>
                    <p class="sugar-page__note">このブラウザ専用のデモの会員を作ってログインします。ほかの方とは共有されません。記録は毎晩消去されます。</p>
                </form>
                <p class="sugar-account__or" aria-hidden="true">または</p>
            @endif
            <form method="post" action="{{ route('storefront.account.send') }}" class="sugar-account__form">
                @csrf
                <label class="sugar-account__label" for="account-email">メールアドレス</label>
                <input id="account-email" type="email" name="email" value="{{ $email }}" maxlength="254" autocomplete="email" placeholder="you@example.com" required class="sugar-account__input" data-test="account-email">
                <button type="submit" class="sugar-account__primary" data-test="account-send">確認コードを送る</button>
            </form>
            <x-storefront::passkey-form mode="get" :action="route('storefront.account.login.passkey')" :options="route('storefront.account.login.passkey.options')" label="パスキーでログイン" test="passkey-login" :or="true" />
            {{-- App のログインの入口（「LINE でログイン」など。ADR 0070）。行き先はその App の /p/<名前>/… だけ（核が形を確かめた値） --}}
            @foreach ($loginEntries ?? [] as $entry)
                @if ($loop->first)
                    <p class="sugar-account__or" aria-hidden="true">または</p>
                @endif
                <a href="{{ $entry->path }}" class="sugar-account__secondary" data-test="account-app-login" data-plugin="{{ $entry->plugin }}">{{ $entry->label }}</a>
            @endforeach
        </div>
        <p class="sugar-account__foot">はじめての方も、このままお進みください。確認コードでログインすると、会員登録が完了します。<br>会員登録をしなくてもお買い物はできます。ご注文の状況は<a href="{{ route('storefront.orders.lookup') }}">ご注文番号から確認</a>できます。</p>
    </div>
@endsection
