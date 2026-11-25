{{--
    1 回のご注文の下限に届いていない時の一言（核の部品。ADR 0109）。テーマのカートの画面が合計の欄の中に置く。
    「ご注文は商品の合計 ¥3,000 から承ります（あと ¥1,200）。」。店が App で下限を決めていて、今買える商品の合計が届かない時だけ出る。
    無ければ隠しておき、JavaScript で数を変えた時は [data-cart-minimum-notice] を書き換えて hidden を外す。届くまで注文には進めない（サーバーが断る）
--}}
@props(['cart'])
<p {{ $attributes->merge(['class' => 'sugar-minimum-notice', 'data-cart-minimum-notice' => '', 'data-test' => 'minimum-notice', 'role' => 'status']) }} @if ($cart->minimumNotice() === null) hidden @endif>{{ $cart->minimumNotice() }}</p>
