{{--
    お問い合わせのフォーム（App「お問い合わせ」）。App の画面で、テーマの外枠の中に描く（App のタグは差し込まない）。JavaScript を使わないフォーム。
    $action（送信先。注文を付けるときは署名つきのパス）・$listing（付けた商品か null）・$orderNumber（付けた注文の番号か null）・$kinds（種類の値 => 名前）・
    $memberEmail（ログインした会員のメール。ゲストは null）・$old（誤りで戻った時の値）・$notice（フォームの上の一言）・$stamp（書き始めの印）・$limits
    ボットよけの見えない欄（website）は hidden の囲みの中（人には見えず、読み上げにも出さない）
--}}
@extends($theme->view('layout'))

@section('title', 'お問い合わせ — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="contact-form">
        <h1 class="sugar-page__title">お問い合わせ</h1>
        <p class="sugar-page__lead">お店へのご質問・ご相談をお送りください。送信後に受付確認のメールをお送りし、後ほどお店からメールでお返事します。</p>
        @if ($notice)
            <p role="status" class="sugar-account__notice" data-test="contact-notice">{{ $notice }}</p>
        @endif
        @if ($flash['error'])
            <p role="alert" class="sugar-account__error" data-test="contact-error">{{ $flash['error'] }}</p>
        @endif
        @if ($errors->any())
            <p role="alert" class="sugar-account__error" data-test="contact-error">{{ $errors->first() }}</p>
        @endif

        <form method="post" action="{{ $action }}" class="sugar-account__section sugar-account__form">
            @csrf
            <input type="hidden" name="started" value="{{ $stamp }}">
            <div hidden aria-hidden="true">
                <label>空のままにしてください <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>
            </div>

            @if ($listing)
                <p class="sugar-page__note" data-test="contact-listing">商品: <a href="{{ route('storefront.product', ['listingId' => $listing->id]) }}">{{ $listing->title }}</a></p>
            @endif
            @if ($orderNumber)
                <p class="sugar-page__note" data-test="contact-order">ご注文番号: {{ $orderNumber }}</p>
            @endif

            <fieldset class="sugar-account__field">
                <legend class="sugar-account__label">お問い合わせの種類</legend>
                @foreach ($kinds as $value => $label)
                    <label class="sugar-page__choice" data-test="contact-kind">
                        <input type="radio" name="kind" value="{{ $value }}" @checked($old['kind'] === $value) required>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>
            <div class="sugar-account__field">
                <label class="sugar-account__label" for="contact-name">お名前</label>
                <input id="contact-name" type="text" name="name" value="{{ $old['name'] }}" maxlength="{{ $limits['name'] }}" autocomplete="name" required class="sugar-account__input" data-test="contact-name">
            </div>
            @if ($memberEmail !== null)
                <p class="sugar-page__note" data-test="contact-member">お返事は、ログイン中のメールアドレス（{{ $memberEmail }}）へお送りします。</p>
            @else
                <div class="sugar-account__field">
                    <label class="sugar-account__label" for="contact-email">メールアドレス</label>
                    <input id="contact-email" type="email" name="email" value="{{ $old['email'] }}" maxlength="{{ $limits['email'] }}" autocomplete="email" required class="sugar-account__input" data-test="contact-email">
                    <p class="sugar-page__note">受付確認のメールと、お店からのお返事をこのアドレスへお送りします。</p>
                </div>
            @endif
            <div class="sugar-account__field">
                <label class="sugar-account__label" for="contact-body">お問い合わせの内容</label>
                <textarea id="contact-body" name="body" rows="8" maxlength="{{ $limits['body'] }}" required class="sugar-account__input" data-test="contact-body">{{ $old['body'] }}</textarea>
                <p class="sugar-page__note">{{ number_format($limits['body']) }} 文字まで。クレジットカード番号やパスワードは書かないでください。</p>
            </div>
            <button type="submit" class="sugar-account__primary" data-test="contact-submit">送信する</button>
        </form>
    </div>
@endsection
