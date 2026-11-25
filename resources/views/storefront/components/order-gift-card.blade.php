{{--
    注文のギフトカード（核の部品。ADR 0076）。テーマのご注文の状況の画面が、税率ごとの内訳の下に置く。
    使い方（テーマ）: <x-storefront::order-gift-card :gift-card="$order->giftCard" />
    使った額と、使った後のカードの残り（OrderGiftCardView::note()）。取消・返金でカードへ戻した注文は、その一言。使っていない注文は何も出さない
--}}
@props(['giftCard' => null])
@if ($giftCard !== null)
    <p {{ $attributes->merge(['class' => 'sugar-order-gift-card', 'data-test' => 'order-gift-card']) }}>{{ $giftCard->note() }}</p>
@endif
