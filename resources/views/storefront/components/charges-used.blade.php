{{--
    App の料金の行（ラッピング・のしなど。核の部品。ADR 0078）。テーマの注文の確かめの画面が、合計の欄（dl）の中の使ったポイントの下・代引き手数料の上に置く。
    使い方（テーマ）: <x-storefront::charges-used :cart="$order->cart" />
    選んでいなければ何も出さない。行の名前は App が店の設定から作った文（個人情報は入らない）で、文字のまま出す。0 円は「無料」。
    見た目は核の CSS（resources/storefront/apps.css の .sugar-charges-used）
--}}
@props(['cart'])
@foreach ($cart->chargeLines() as $charge)
    <div {{ $attributes->merge(['class' => 'sugar-charges-used', 'data-test' => 'charges-used']) }}><dt>{{ $charge['title'] }}</dt><dd class="tabular">{{ $charge['amountLabel'] }}</dd></div>
@endforeach
