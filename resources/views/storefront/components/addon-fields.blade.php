{{--
    オプション（名入れ・ギフト包装など）を選ぶ部品（核の部品。テーマ API 2 の付け足し。ADR 0036）。
    使い方: 「カートに入れる」のフォームの中に <x-storefront::addon-fields :product="$product" class="…" />（オプションの無い商品では何も出さない）
    - 欄の名前は addons[オプションの id]。選ぶは select（値は「label:選択肢の名前」。番号は送らない）、入れる文字は input type=text、付けるはチェックボックス（値は 1）
    - JavaScript が無くても動く。額はいつもサーバーが定義と照らして決め直す（ここで見せる額は案内だけ）
    - 必須の選ぶは空の先頭の行（「選んでください」）から始め、required で選ばずに入れるのをブラウザが止める（サーバーも断る）。付けるは必須にしない
    - カートに入れるのを断られて戻ったときは、入れていた値を欄に戻す（$product->addonValue。次の 1 回の画面だけ）
    - JavaScript があれば、核の部品（resources/storefront/addons.js）が、選んだ種類の価格にオプションの額を足し、数を掛けた合計（「2 点で ¥5,400」）を [data-addon-total] に出す
    - 見た目はテーマが印（[data-addon-fields] など）から CSS で決める。部品は class を持たない。属性（class など）は外枠に付く
    - データは data-addons（JSON）で渡す。値は {{ }} でエスケープされ、部品は textContent だけに入れる
--}}
@props(['product'])
@if ($product->hasAddons())
    <div {{ $attributes->merge(['data-addon-fields' => '']) }} data-addons="{{ $product->addonData() }}">
        @foreach ($product->addons as $addon)
            <div data-addon-field data-addon-kind="{{ $addon->kind }}">
                @if ($addon->kind === 'choice')
                    <label for="addon-{{ $addon->id }}" data-addon-label>{{ $addon->name }}@if ($addon->required) <span data-addon-required>必須</span>@endif</label>
                    <select id="addon-{{ $addon->id }}" name="{{ $addon->fieldName() }}" data-addon-input @required($addon->required)>
                        <option value="">{{ $addon->required ? '選んでください' : 'なし' }}</option>
                        @foreach ($addon->choices as $choice)
                            <option value="{{ $addon::choiceValue($choice['label']) }}" @selected($product->addonValue($addon->id) === $addon::choiceValue($choice['label']))>{{ $choice['label'] }}@if ($choice['amount'] > 0)（{{ $addon->amountLabel($choice['amount']) }}）@endif</option>
                        @endforeach
                    </select>
                @elseif ($addon->kind === 'text')
                    <label for="addon-{{ $addon->id }}" data-addon-label>{{ $addon->name }}@if ($addon->required) <span data-addon-required>必須</span>@endif</label>
                    <input type="text" id="addon-{{ $addon->id }}" name="{{ $addon->fieldName() }}" maxlength="{{ $addon->maxLength }}" value="{{ $product->addonValue($addon->id) }}" autocomplete="off" aria-describedby="addon-{{ $addon->id }}-hint" data-addon-input @required($addon->required)>
                    <p id="addon-{{ $addon->id }}-hint" data-addon-hint>{{ $addon->maxLength }} 文字まで@if ($addon->amount > 0)・{{ $addon->amountLabel() }}@endif</p>
                @else
                    <label for="addon-{{ $addon->id }}" data-addon-toggle>
                        <input type="checkbox" id="addon-{{ $addon->id }}" name="{{ $addon->fieldName() }}" value="1" data-addon-input @checked($product->addonValue($addon->id) === '1')>
                        <span data-addon-label>{{ $addon->name }}@if ($addon->amount > 0)（{{ $addon->amountLabel() }}）@endif</span>
                    </label>
                @endif
            </div>
        @endforeach
        {{-- 合計は JavaScript があるときだけ出す（選び方と数に合わせて書き換えるので、JS が無いと古いまま残る） --}}
        <p data-addon-summary hidden>オプションを含めて <span data-addon-total>{{ $product->addonTotalLabel() }}</span></p>
    </div>
@endif
