{{-- 年齢の門で「いいえ」と答えた人へ（ADR 0077）。$age。記録は残さない --}}
@extends($theme->view('layout'))

@section('title', '年齢の確認 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="age-gate-refused">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">お求めいただけません</h1>
                <p class="sugar-page__lead">この商品は、{{ $age }} 歳未満の方にはお売りできません。</p>
            </div>
            <a href="{{ route('storefront.home') }}" class="sugar-account__secondary">トップへ</a>
        </div>
    </div>
@endsection
