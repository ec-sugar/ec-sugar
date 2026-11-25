{{--
    種類（色・サイズ）を選ぶ部品（核の部品。テーマ API 2 の付け足し。ADR 0036）。
    使い方: 「カートに入れる」のフォームの中に <x-storefront::variant-picker :product="$product" class="…" />（種類の無い商品では何も出さない）
    - JavaScript が無くても選べる: 販売中の種類を並べた select（name="variant_id"）。売り切れは「（売り切れ）」と書いて選べなくする
    - JavaScript があれば、核の部品（resources/storefront/variants.js）が軸ごとのボタン（外枠に data-variant-style="select" なら select）に作り替え、
      選んだ種類の価格（[data-variant-price]）・在庫の知らせ（[data-variant-availability]）・写真（img[data-variant-photo]）・数の上限・「カートに入れる」を切り替える
    - 見た目はテーマが印（[data-variant-picker] など）から CSS で決める。部品は class を持たない。属性（class など）は外枠に付く
    - 種類のデータは data-variants（JSON）で渡す。値は {{ }} でエスケープされ、部品は textContent と決まった属性だけに入れる
--}}
@props(['product'])
@if ($product->hasVariants())
    <div {{ $attributes->merge(['data-variant-picker' => '']) }} data-variants="{{ $product->variantData() }}">
        <div data-variant-fallback>
            <label for="variant-{{ $product->card->id }}" data-variant-label>{{ implode(' / ', array_column($product->options, 'name')) }}</label>
            <select id="variant-{{ $product->card->id }}" name="variant_id" data-variant-select>
                @foreach ($product->variants as $variant)
                    <option value="{{ $variant->id }}" @selected($variant->id === $product->selectedVariant()?->id) @disabled($variant->soldOut())>{{ $variant->label }}@if ($product->pricesVary()) — {{ $variant->priceLabel() }}@endif{{ $variant->soldOut() ? '（売り切れ）' : '' }}</option>
                @endforeach
            </select>
        </div>
    </div>
@endif
