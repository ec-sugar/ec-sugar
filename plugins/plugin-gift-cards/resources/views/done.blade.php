{{--
    ギフトカードのお支払いの後（ADR 0076）。戻ってきた URL は払いの証拠にしない（払いが確かになるのは決済代行の知らせで）。コードはこの画面には出さず、メールで届く
--}}
@extends($theme->view('layout'))

@section('title', 'ギフトカードのご注文 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="gift-cards-done">
        <h1 class="sugar-page__title">ご注文ありがとうございます</h1>
        <p class="sugar-page__lead">お支払いが完了したら、ギフトカードのコードをメールでお届けします。数分たっても届かないときは、迷惑メールのフォルダもご確認ください。</p>
        <p class="sugar-account__foot"><a href="/">ショップのトップへ</a></p>
    </div>
@endsection
