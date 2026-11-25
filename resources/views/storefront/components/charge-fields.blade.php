{{--
    App の料金の欄（ラッピング・のしなど。ADR 0078）。核の部品で、テーマの注文の入力の画面が、フォームの中の注文の項目（order-fields）の後に置く。
    使い方（テーマ）: <x-storefront::charge-fields :form="$form" />
    料金の口を持つ App を使う店で、カートに選べる商品がある時だけ出す（ほかは何も出さない）。贈り物として送るときも出す。
    欄の種類は「選ぶ」と「1 行の文」。名前・説明・選択肢（値段つき）は App が店の設定から作った文で、文字のまま出す（HTML として解釈しない）。
    値段はサーバーが決め直す（ここの文は見せるだけ）。見た目は注文の項目と同じ核の CSS（.sugar-order-fields）
--}}
@props(['form'])
@if ($form->charges() !== null)
    <fieldset class="sugar-order-fields" data-charge-fields data-test="charge-fields">
        <legend class="sugar-order-fields__title">{{ $form->charges()['title'] }}</legend>
        @if ($form->charges()['note'] !== null)
            <p class="sugar-order-fields__hint" data-test="charge-fields-note">{{ $form->charges()['note'] }}</p>
        @endif
        @if ($form->charges()['error'])
            <p class="sugar-order-fields__error" role="alert" data-test="charge-fields-error">{{ $form->charges()['error'] }}</p>
        @endif
        @foreach ($form->charges()['fields'] as $field)
            <label class="sugar-order-fields__field" for="{{ $field['inputId'] }}" data-test="charge-field">
                <span class="sugar-order-fields__label">{{ $field['label'] }} <span class="sugar-order-fields__optional">任意</span></span>
                @if ($field['type'] === 'select')
                    <select id="{{ $field['inputId'] }}" name="{{ $field['inputName'] }}" class="sugar-order-fields__input"
                            @if ($field['error']) aria-invalid="true" @endif
                            @if ($field['help'] !== null || $field['error']) aria-describedby="{{ $field['hintId'] }}" @endif data-test="charge-field-{{ $field['key'] }}">
                        @foreach ($field['options'] as $value => $label)
                            <option value="{{ $value }}" @selected($field['value'] === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                @else
                    <input id="{{ $field['inputId'] }}" name="{{ $field['inputName'] }}" type="text" value="{{ $field['value'] }}" maxlength="{{ $field['maxLength'] }}" autocomplete="off" class="sugar-order-fields__input"
                           @if ($field['error']) aria-invalid="true" @endif
                           @if ($field['help'] !== null || $field['error']) aria-describedby="{{ $field['hintId'] }}" @endif data-test="charge-field-{{ $field['key'] }}">
                @endif
                @if ($field['error'])
                    <span id="{{ $field['hintId'] }}" class="sugar-order-fields__error" role="alert" data-test="charge-field-error">{{ $field['error'] }}</span>
                @elseif ($field['help'] !== null)
                    <span id="{{ $field['hintId'] }}" class="sugar-order-fields__hint">{{ $field['help'] }}</span>
                @endif
            </label>
        @endforeach
    </fieldset>
@endif
