{{--
    次に届く自動の割引の一言（核の部品。ADR 0065）。テーマのカートの画面が合計の欄の中（送料の下あたり）に置く。
    「あと 1 点で「まとめ買い 10%」」「あと ¥1,200 で「秋のセール」」。足りないのが数か額だけで、届けば今より得になる割引のうち、いちばん近い 1 つだけ。
    会員だけの割引は、ログインした会員にだけ。無ければ隠しておき、JavaScript で数を変えた時は [data-cart-discount-hint] を書き換えて hidden を外す
--}}
@props(['cart'])
<p {{ $attributes->merge(['class' => 'sugar-discount-hint', 'data-cart-discount-hint' => '', 'data-test' => 'discount-hint', 'role' => 'status']) }} @if ($cart->discountHint === null) hidden @endif>{{ $cart->discountHint }}</p>
