{{--
    デモの店の「送るはずだったメール」（ADR 0086）。核が描く。この来訪者（このブラウザのセッション）の受け箱のメールだけを並べる。
    本文は文字のまま {{ }} で出す（HTML として描かない）。テーマの外枠の中に描き、App のスクリプトは差し込まない
--}}
@extends($theme->view('layout'))

@section('title', '送るはずだったメール — '.$shop->name)

@section('content')
    <div class="sugar-page" data-test="demo-mails">
        <h1 class="sugar-page__title">送るはずだったメール</h1>
        <p class="sugar-page__lead">
            デモのお店なので、メールは送りません。このブラウザで試した注文・会員・申し込みのメールを、ここに表示します（ほかの方には見えません）。
            デモでは、入力したメールアドレスはこのブラウザ専用のアドレス（{{ $address }}）に置き換わります。ショップのデータは毎晩、最初の状態に戻ります。
        </p>

        @if ($mails === [])
            <p class="sugar-page__empty" data-test="demo-mails-empty">まだメールはありません。注文やログインを試すと、ここに表示されます。</p>
        @else
            <div class="sugar-page__list">
                @foreach ($mails as $mail)
                    <section class="sugar-page__item sugar-demo-mail" data-test="demo-mail">
                        <h2 class="sugar-page__heading">{{ $mail['subject'] }}</h2>
                        <p class="sugar-page__note"><time datetime="{{ $mail['at']->toIso8601String() }}">{{ $mail['at']->timezone('Asia/Tokyo')->format('Y年n月j日 H:i') }}</time></p>
                        <pre class="sugar-demo-mail__body">{{ $mail['body'] }}</pre>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
@endsection
