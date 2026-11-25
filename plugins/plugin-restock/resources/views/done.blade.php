{{-- 入荷のお知らせを頼んだ後。$listing・$title・$message（ゲストには、送った・もう登録がある・回数の上限のどれでも同じ文） --}}
@extends($theme->view('layout'))

@section('title', '入荷のお知らせ — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="restock-done">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">{{ $title }}</h1>
                <p class="sugar-page__lead" data-test="restock-done-message">{{ $message }}</p>
            </div>
            <a href="{{ route('storefront.product', ['listingId' => $listing->id]) }}" class="sugar-account__secondary">{{ $listing->title }} の商品ページへ戻る</a>
        </div>
    </div>
@endsection
