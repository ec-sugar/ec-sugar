{{--
    ギフトカードを買う（ADR 0076）。App の画面で、テーマの外枠の中に描く（App のタグは差し込まない）。会員でもゲストでも買える。
    額面を選び、買う人の名前とメール・贈る相手のメール（任意）とメッセージ（任意）を入れる。コードは払いが確かになってからメールで届く
--}}
@extends($theme->view('layout'))

@section('title', 'ギフトカード — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="gift-cards-form">
        <h1 class="sugar-page__title">ギフトカード</h1>
        <p class="sugar-page__lead">{{ $shop->name }} で使えるギフトカードです。お支払いが完了したら、コードをメールでお届けします（贈る相手のメールアドレスを入れると、相手にも届きます）。有効期限は発行から {{ $validDays }} 日です。</p>
        @if ($testMode)
            <p role="note" class="sugar-account__error" data-test="gift-cards-test-mode">いまはテストの支払いです。お店の動作確認用のため、本物のギフトカードは購入できません（テスト中に発行したカードは、本番のお支払いに切り替えると使えなくなります）。</p>
        @endif
        @if ($flash['error'])
            <p role="alert" class="sugar-account__error" data-test="gift-cards-error">{{ $flash['error'] }}</p>
        @endif
        @if ($errors->any())
            <p role="alert" class="sugar-account__error" data-test="gift-cards-error">{{ $errors->first() }}</p>
        @endif

        <form method="post" action="/p/gift-cards" class="sugar-account__section sugar-account__form">
            @csrf
            <fieldset class="sugar-account__field">
                <legend class="sugar-account__label">額面</legend>
                @foreach ($denominations as $i => $denomination)
                    <label class="sugar-page__choice" data-test="gift-cards-amount">
                        <input type="radio" name="amount" value="{{ $denomination['amount'] }}" @checked((int) ($values['amount'] ?? ($i === 0 ? $denomination['amount'] : 0)) === $denomination['amount']) required>
                        <span>{{ $denomination['label'] }}</span>
                    </label>
                @endforeach
            </fieldset>
            <div class="sugar-account__field">
                <label class="sugar-account__label" for="gift-cards-name">お名前</label>
                <input id="gift-cards-name" type="text" name="name" maxlength="100" required autocomplete="name" class="sugar-account__input" value="{{ $values['name'] ?? '' }}" data-test="gift-cards-name">
            </div>
            <div class="sugar-account__field">
                <label class="sugar-account__label" for="gift-cards-email">メールアドレス（コードをお届けします）</label>
                <input id="gift-cards-email" type="email" name="email" maxlength="254" required autocomplete="email" class="sugar-account__input" value="{{ $values['email'] ?? '' }}" data-test="gift-cards-email">
            </div>
            <div class="sugar-account__field">
                <label class="sugar-account__label" for="gift-cards-recipient">贈る相手のメールアドレス（任意）</label>
                <input id="gift-cards-recipient" type="email" name="recipient_email" maxlength="254" autocomplete="off" class="sugar-account__input" value="{{ $values['recipient_email'] ?? '' }}" data-test="gift-cards-recipient">
            </div>
            <div class="sugar-account__field">
                <label class="sugar-account__label" for="gift-cards-message">メッセージ（任意・200 文字まで。贈る相手へのメールに載せます）</label>
                <textarea id="gift-cards-message" name="message" maxlength="200" rows="3" class="sugar-account__input" data-test="gift-cards-message">{{ $values['message'] ?? '' }}</textarea>
            </div>
            <button type="submit" class="sugar-account__primary" data-test="gift-cards-submit">確認へ進む</button>
        </form>
    </div>
@endsection
