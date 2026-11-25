{{--
    商品の画面の付くポイント（核の部品。ADR 0068）。テーマの商品の画面が、値段の近く（割引の名前の下）に置く。
    使い方（テーマ）: <x-storefront::product-points :points="$productPoints ?? null" />
    会員には「30 ポイント付きます」、ゲストには「会員なら 30 ポイント付きます」（今の値段 × 店の率の切り捨て。発送の時に付く）。使わない店・0 ポイントは何も出さない
--}}
@props(['points' => null])
@if (is_array($points) && ($points['points'] ?? 0) > 0)
    <p {{ $attributes->merge(['class' => 'sugar-product-points', 'data-test' => 'product-points']) }}>{{ $points['member'] ? '' : '会員の方は ' }}{{ number_format($points['points']) }} ポイントが付きます</p>
@endif
