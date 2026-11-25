{{--
    会員のパスキー（ADR 0044 の 3）。核が描く。本人の再確認の後だけ開ける。任意で足せて、次からメールのコードの代わりに使える
--}}
@extends($theme->view('layout'))

@section('title', 'パスキー — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="account-passkeys">
        <p class="sugar-account__back"><a href="{{ route('storefront.account') }}">← マイページ</a></p>
        <h1 class="sugar-page__title">パスキー</h1>
        <p class="sugar-page__lead">パスキーを追加すると、メールの確認コードの代わりに、この端末の指紋・顔認証・暗証番号でログインできます。追加しなくても、これまでどおりメールの確認コードでログインできます。</p>
        @if ($flash['message'])
            <p role="status" class="sugar-account__notice" data-test="account-message">{{ $flash['message'] }}</p>
        @endif
        @if ($flash['error'])
            <p role="alert" class="sugar-account__error" data-test="account-error">{{ $flash['error'] }}</p>
        @endif

        <section class="sugar-account__section" aria-label="登録したパスキー">
            @if ($passkeys === [])
                <p class="sugar-page__empty" data-test="passkeys-empty">パスキーはまだ登録されていません。</p>
            @else
                <ul class="sugar-account__list">
                    @foreach ($passkeys as $passkey)
                        <li class="sugar-account__order" data-test="passkey-item">
                            <p class="sugar-account__order-head"><strong>{{ $passkey['name'] }}</strong></p>
                            <p class="sugar-page__note">追加した日 {{ $passkey['created'] }}@if ($passkey['used'])　最後に使った日 {{ $passkey['used'] }}@endif</p>
                            <form method="post" action="{{ route('storefront.account.passkey.delete', ['passkeyId' => $passkey['id']]) }}" class="sugar-account__actions">
                                @csrf
                                <button type="submit" class="sugar-account__secondary" data-test="passkey-delete">削除</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if (count($passkeys) < $limit)
            <section class="sugar-account__section" aria-labelledby="passkey-add">
                <h2 id="passkey-add" class="sugar-page__heading">この端末にパスキーを追加</h2>
                <x-storefront::passkey-form mode="create" :action="route('storefront.account.passkeys.store')" :options="route('storefront.account.passkeys.options')" label="パスキーを追加" test="passkey-add" :primary="true">
                    <label class="sugar-account__label" for="passkey-name">パスキーの名前（端末を見分けるための名前）</label>
                    <input id="passkey-name" type="text" name="name" maxlength="60" required value="この端末" class="sugar-account__input" data-test="passkey-name">
                </x-storefront::passkey-form>
                <p class="sugar-page__note" data-passkey-unsupported>このブラウザではパスキーを追加できません。</p>
            </section>
        @endif
    </div>
@endsection
