{{--
    注文のポイント（核の部品。ADR 0068）。テーマのご注文の状況の画面が、税率ごとの内訳の下に置く。
    使い方（テーマ）: <x-storefront::order-points :points="$order->points" />
    付く予定（払ってから発送まで）・付いた・返金で取り消した を一言で（OrderPointsView::note()）。使ったポイントの額は値引きの並び（$order->discounts）に入っている。
    会員でない注文・言うことの無い注文は何も出さない
--}}
@props(['points' => null])
@if ($points?->note() !== null)
    <p {{ $attributes->merge(['class' => 'sugar-order-points', 'data-test' => 'order-points']) }}>{{ $points->note() }}</p>
@endif
