{{--
    マイページの「LINE 連携」（会員だけ。ADR 0070）。つなぐ・外すは本人の再確認の後（押すと、まだなら再確認の画面へ）。
    $linked（つないであるか）・$friend（お店の公式アカウントと友だちか）・$ready（お店が LINE ログインの設定を済ませたか）
--}}
@extends($theme->view('layout'))

@section('title', 'LINE 連携 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="line-account">
        <h1 class="sugar-page__title">LINE 連携</h1>
        <p class="sugar-page__lead">LINE と連携すると、「LINE でログイン」でログインできます。お店の LINE 公式アカウントを友だちに追加していれば、ご注文の受付と発送のお知らせも LINE でお送りします（メールでも今までどおりお送りします）。</p>
        @if ($flash['message'])
            <p role="status" class="sugar-page__note" data-test="line-message">{{ $flash['message'] }}</p>
        @endif
        @if ($flash['error'])
            <p role="alert" class="sugar-account__error" data-test="line-error">{{ $flash['error'] }}</p>
        @endif

        <div class="sugar-account__section">
            <p class="sugar-page__note" data-test="line-state">
                @if ($linked)
                    LINE と連携しています。{{ $friend ? 'お知らせは LINE でもお送りします。' : 'お知らせを LINE で受け取るには、お店の LINE 公式アカウントを友だちに追加してください。' }}
                @else
                    いまは LINE と連携していません。
                @endif
            </p>
            @if ($linked)
                <form method="post" action="/p/line/disconnect" class="sugar-account__form">
                    @csrf
                    <button type="submit" class="sugar-account__secondary" data-test="line-disconnect">LINE 連携を解除する</button>
                </form>
            @elseif ($ready)
                <form method="post" action="/p/line/connect" class="sugar-account__form">
                    @csrf
                    <button type="submit" class="sugar-account__primary" data-test="line-connect">LINE と連携する</button>
                </form>
            @else
                <p class="sugar-page__note">いまは LINE と連携できません。</p>
            @endif
        </div>
        <p class="sugar-account__foot"><a href="/account">マイページに戻る</a></p>
    </div>
@endsection
