{{--
    付くポイントの一言（核の部品。ADR 0068）。テーマのカートの画面（合計の欄の中）と注文の確かめの画面（税率ごとの内訳の下）が置く。
    使い方（テーマ）: <x-storefront::points-note :points="$cart->points" />
    会員には「このご注文で 30 ポイント付きます（発送の時）」、ゲストには「会員なら 30 ポイント付きます」。付かなければ隠しておき、
    カートの画面で JavaScript が数を変えた時は [data-cart-points-note] を書き換えて hidden を外す（cart.js）
--}}
@props(['points'])
<p {{ $attributes->merge(['class' => 'sugar-points-note', 'data-cart-points-note' => '', 'data-test' => 'points-note', 'role' => 'status']) }} @if ($points->earnNote() === null) hidden @endif>{{ $points->earnNote() }}</p>
