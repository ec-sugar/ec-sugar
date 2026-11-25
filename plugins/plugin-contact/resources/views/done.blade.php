{{-- お問い合わせを送った後（ボットよけで落としたものにも同じ画面。受付番号は受け付けのメールにだけ載せる） --}}
@extends($theme->view('layout'))

@section('title', 'お問い合わせ — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="contact-done">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">お問い合わせを受け付けました</h1>
                <p class="sugar-page__lead" data-test="contact-done-message">受付確認のメールをお送りしました（受付番号を記載しています）。お店からのお返事まで、しばらくお待ちください。メールが届かないときは、迷惑メールのフォルダもご確認ください。</p>
            </div>
            <a href="{{ route('storefront.home') }}" class="sugar-account__secondary">ショップのトップへ</a>
        </div>
    </div>
@endsection
