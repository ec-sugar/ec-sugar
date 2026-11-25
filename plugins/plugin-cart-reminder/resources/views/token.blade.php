{{--
    メールのリンクの画面（配信の停止・使えない「カートに戻す」のリンク）。開いただけでは何も変えず、ボタン（POST）で止める。
    $title・$message・$path（停止のボタンの送信先。押せない時は null）
--}}
@extends($theme->view('layout'))

@section('title', $title.' — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="cart-reminder-token">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">{{ $title }}</h1>
                <p class="sugar-page__lead" data-test="cart-reminder-message">{{ $message }}</p>
            </div>
            @if ($path !== null)
                <form method="post" action="{{ $path }}">
                    @csrf
                    <button type="submit" class="sugar-account__primary" data-test="cart-reminder-unsubscribe">配信を停止する</button>
                </form>
            @endif
            <a href="{{ route('storefront.home') }}" class="sugar-account__secondary">ショップへ戻る</a>
        </div>
    </div>
@endsection
