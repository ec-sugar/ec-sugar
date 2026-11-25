{{-- 商品の並び（新着の商品・おすすめの商品の部品が使う）。$listings（ListingCard の並び）・$pattern（grid・large・scroll）。
     grid は 6 列（狭い画面は 3 列・2 列）、large は 2 列の大きな札（狭い画面は 1 列）、scroll は横に流れる（広い画面で 5 枚見える。並びの箱の中だけが横に動く） --}}
<div class="{{ ['grid' => 'fashion-product-grid', 'large' => 'fashion-product-large', 'scroll' => 'fashion-product-scroll'][$pattern] ?? 'fashion-product-grid' }}" data-test="product-rail" data-pattern="{{ $pattern }}">
    @foreach ($listings as $listing)
        @include('theme-fashion::partials.product-card', ['listing' => $listing])
    @endforeach
</div>
