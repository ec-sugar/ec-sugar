{{-- 受付の後。$title・$message（ゲストには、送った・もう届けている・回数の上限のどれでも同じ文） --}}
@extends($theme->view('layout'))

@section('title', 'お知らせのメール — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="newsletter-done">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">{{ $title }}</h1>
                <p class="sugar-page__lead" data-test="newsletter-done-message">{{ $message }}</p>
            </div>
            <a href="{{ route('storefront.home') }}" class="sugar-account__secondary">ショップへ戻る</a>
        </div>
    </div>
@endsection
