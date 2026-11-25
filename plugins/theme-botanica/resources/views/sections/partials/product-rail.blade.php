{{-- 商品の並び（新着の商品・おすすめの商品の部品が使う）。$listings（ListingCard の並び）・$pattern（grid・large・scroll）。
     grid は 4 列（狭い画面は 2 列）、large は 2 列の大きな札（狭い画面は 1 列）、scroll は横に流れる（広い画面で 5 枚見える。並びの箱の中だけが横に動く） --}}
<div class="{{ ['grid' => 'botanica-product-grid', 'large' => 'botanica-product-large', 'scroll' => 'botanica-product-scroll'][$pattern] ?? 'botanica-product-grid' }}" data-test="product-rail" data-pattern="{{ $pattern }}">
    @foreach ($listings as $listing)
        @include('theme-botanica::partials.product-card', ['listing' => $listing])
    @endforeach
</div>
