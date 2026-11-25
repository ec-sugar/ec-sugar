{{--
    メールのリンクの画面（受け取りを確かめる・配信を止める）。開いただけでは何も変えず、ボタン（POST）で変える。
    $title・$action（confirm | unsubscribe | null）・$message・$path（ボタンの送信先）
--}}
@extends($theme->view('layout'))

@section('title', $title.' — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="newsletter-token">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">{{ $title }}</h1>
                <p class="sugar-page__lead" data-test="newsletter-token-message">{{ $message }}</p>
            </div>
            @if ($action !== null)
                <form method="post" action="{{ $path }}">
                    @csrf
                    <button type="submit" class="sugar-account__primary" data-test="newsletter-{{ $action }}">{{ $action === 'confirm' ? '配信の登録を確定する' : '配信を停止する' }}</button>
                </form>
            @endif
            <a href="{{ route('storefront.home') }}" class="sugar-account__secondary">ショップへ戻る</a>
        </div>
    </div>
@endsection
