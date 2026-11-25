{{--
    友だち紹介を今は受け付けていない（App「友だち紹介」、ADR 0075 の 5。お店がポイントを使わない設定の時）
--}}
@extends($theme->view('layout'))

@section('title', '友だちを紹介する — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="referral-unavailable">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">今は友だちの紹介を受け付けていません</h1>
                <p class="sugar-page__lead">このお店では、現在ポイントを取り扱っていません。</p>
            </div>
            <a href="{{ route('storefront.account') }}" class="sugar-account__secondary">マイページへ戻る</a>
        </div>
    </div>
@endsection
