{{--
    お気に入りのお知らせの配信の停止の画面。開いただけでは何も変えず、ボタン（POST）で止める。$message・$path（停止のボタンの送信先。押せない時は null）
--}}
@extends($theme->view('layout'))

@section('title', 'お気に入りのお知らせの停止 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="favorites-token">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">お気に入りのお知らせの停止</h1>
                <p class="sugar-page__lead" data-test="favorites-token-message">{{ $message }}</p>
            </div>
            @if ($path !== null)
                <form method="post" action="{{ $path }}">
                    @csrf
                    <button type="submit" class="sugar-account__primary" data-test="favorites-unsubscribe">お知らせを停止する</button>
                </form>
            @endif
            <a href="{{ route('storefront.home') }}" class="sugar-account__secondary">ショップへ戻る</a>
        </div>
    </div>
@endsection
