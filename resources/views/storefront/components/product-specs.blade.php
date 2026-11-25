{{--
    商品の仕様の表（核の部品。テーマ API 2 の付け足し。ADR 0032）。
    使い方: <x-storefront::product-specs :specs="$product->specs" class="…" />
    - $specs: list<{label, value}>。value は改行を含む（テーマが white-space: pre-line で出す）。どれも {{ }} で出す
    - 見た目はテーマが印（[data-specs]）から CSS で決める
--}}
@props(['specs' => []])
<dl {{ $attributes->merge(['data-specs' => '']) }}>
    @foreach ($specs as $spec)
        <div>
            <dt>{{ $spec['label'] }}</dt>
            <dd>{{ $spec['value'] }}</dd>
        </div>
    @endforeach
</dl>
