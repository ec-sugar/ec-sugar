{{-- 入荷のお知らせを受けられない商品（ショップに出ていない・売り切れの種類が無い）。$listing（ショップに出ていれば商品、無ければ null） --}}
@extends($theme->view('layout'))

@section('title', '入荷のお知らせ — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="restock-unavailable">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                @if ($listing !== null)
                    <h1 class="sugar-page__title">この商品はいま買えます</h1>
                    <p class="sugar-page__lead">売り切れの種類がありません。商品ページからカートに入れてください。</p>
                @else
                    <h1 class="sugar-page__title">この商品は入荷のお知らせを受け付けていません</h1>
                    <p class="sugar-page__lead">販売を終了したか、見つからない商品です。</p>
                @endif
            </div>
            @if ($listing !== null)
                <a href="{{ route('storefront.product', ['listingId' => $listing->id]) }}" class="sugar-account__primary">商品ページへ</a>
            @else
                <a href="{{ route('storefront.products') }}" class="sugar-account__secondary">商品の一覧へ</a>
            @endif
        </div>
    </div>
@endsection
