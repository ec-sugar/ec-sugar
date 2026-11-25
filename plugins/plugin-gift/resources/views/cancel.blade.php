{{--
    贈り物の取り消し（ADR 0055 の 3）。受け取られる前だけ。確かめは 1 回だけ。全額を返金し、受け取りのリンクは使えなくなる
--}}
@extends($theme->view('layout'))

@section('title', '贈り物の取り消し — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="gift-cancel">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">贈り物を取り消す</h1>
                <p class="sugar-page__lead">ご注文番号 {{ $number }} の贈り物を取り消し、全額を返金します。受け取りのリンクは使えなくなります。元に戻せません。</p>
            </div>
            <form method="post" action="/p/gift/orders/{{ $orderId }}/cancel" class="sugar-account__form">
                @csrf
                <button type="submit" class="sugar-account__primary" data-test="gift-cancel-submit">取り消して返金する</button>
            </form>
        </div>
        <p class="sugar-account__foot"><a href="/p/gift/orders/{{ $orderId }}">取り消さずに戻る</a></p>
    </div>
@endsection
