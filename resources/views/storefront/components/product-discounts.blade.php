{{--
    商品の画面の、その商品が対象で今使える自動の割引の名前（核の部品。ADR 0065）。テーマの商品の画面が価格の近くに置く。
    使い方（テーマ）: <x-storefront::product-discounts :names="$productDiscounts ?? []" />
    - $names: 割引の名前の並び（作った順。店の人が決めた 40 文字までの文。会員だけの割引はログインした会員にだけ入る）。空なら何も描かない
    - 見た目は核の CSS（resources/storefront/apps.css の .sugar-product-discounts）が付け、テーマは同じ印で上書きできる
--}}
@props(['names' => []])
@if ($names !== [])
    <ul {{ $attributes->merge(['class' => 'sugar-product-discounts', 'data-test' => 'product-discounts', 'aria-label' => 'この商品に使える割引']) }}>
        @foreach ($names as $name)
            <li class="sugar-product-discounts__item" data-test="product-discount">{{ $name }}</li>
        @endforeach
    </ul>
@endif
