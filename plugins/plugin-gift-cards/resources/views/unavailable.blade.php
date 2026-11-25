{{--
    ギフトカードを今は買えない（お支払いの準備ができていない店。ADR 0076）
--}}
@extends($theme->view('layout'))

@section('title', 'ギフトカード — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="gift-cards-unavailable">
        <h1 class="sugar-page__title">ギフトカード</h1>
        <p class="sugar-page__lead">今はギフトカードをお求めいただけません。お店にお問い合わせください。</p>
    </div>
@endsection
