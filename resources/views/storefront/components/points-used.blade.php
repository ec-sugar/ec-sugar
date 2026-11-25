{{--
    使ったポイントの行（核の部品。ADR 0068）。テーマの注文の確かめの画面が、合計の欄（dl）の中の送料の下・合計の上に置く。
    使い方（テーマ）: <x-storefront::points-used :points="$order->cart->points" />
    使っていなければ何も出さない。見た目は核の CSS（resources/storefront/apps.css の .sugar-points-used）
--}}
@props(['points'])
@if ($points->usedLabel() !== null)
    <div {{ $attributes->merge(['class' => 'sugar-points-used', 'data-test' => 'points-used']) }}><dt>ポイントのご利用</dt><dd class="tabular">{{ $points->usedLabel() }}</dd></div>
@endif
