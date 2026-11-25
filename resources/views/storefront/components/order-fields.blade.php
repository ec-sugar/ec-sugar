{{--
    注文の項目（ADR 0066）。核の部品で、テーマの注文の入力の画面が、フォームの中のお届け日時の欄の後に置く。
    店が管理画面の「設定 › 注文の項目」で足した欄（のしの有無・用途・ご要望など）を、並びの順に出す。欄が無い店では何も出さない。
    種類は 1 行の文・複数行の文・選ぶ・チェック（はい／いいえ）。値はサーバーが店の決まりで確かめる（必須・長さ・選択肢の中か）。
    名前・説明・選択肢は店の人が決めた文で、文字のまま出す（HTML として解釈しない）。贈り物として送るときも出す（買う人に聞く）
--}}
@props(['form'])
@if ($form->orderFields() !== [])
    <fieldset class="sugar-order-fields" data-order-fields data-test="order-fields">
        <legend class="sugar-order-fields__title">ご注文について</legend>
        @foreach ($form->orderFields() as $field)
            @if ($field['type'] === 'checkbox')
                <div class="sugar-order-fields__field" data-test="order-field">
                    <label class="sugar-order-fields__check" for="{{ $field['inputId'] }}">
                        <input type="hidden" name="{{ $field['inputName'] }}" value="0">
                        <input type="checkbox" id="{{ $field['inputId'] }}" name="{{ $field['inputName'] }}" value="1" @checked($field['value'] === '1')
                               @if ($field['required']) aria-required="true" @endif @if ($field['error']) aria-invalid="true" @endif
                               @if ($field['description'] !== null || $field['error']) aria-describedby="{{ $field['hintId'] }}" @endif data-test="order-field-input">
                        <span class="sugar-order-fields__label">{{ $field['name'] }}
                            @if ($field['required'])<span class="sugar-order-fields__required">必須</span>@endif
                        </span>
                    </label>
                    @if ($field['error'])
                        <span id="{{ $field['hintId'] }}" class="sugar-order-fields__error" role="alert" data-test="order-field-error">{{ $field['error'] }}</span>
                    @elseif ($field['description'] !== null)
                        <span id="{{ $field['hintId'] }}" class="sugar-order-fields__hint">{{ $field['description'] }}</span>
                    @endif
                </div>
            @else
                <label class="sugar-order-fields__field" for="{{ $field['inputId'] }}" data-test="order-field">
                    <span class="sugar-order-fields__label">{{ $field['name'] }}
                        @if ($field['required'])
                            <span class="sugar-order-fields__required">必須</span>
                        @else
                            <span class="sugar-order-fields__optional">任意</span>
                        @endif
                    </span>
                    @if ($field['type'] === 'select')
                        <select id="{{ $field['inputId'] }}" name="{{ $field['inputName'] }}" class="sugar-order-fields__input"
                                @if ($field['required']) aria-required="true" @endif @if ($field['error']) aria-invalid="true" @endif
                                @if ($field['description'] !== null || $field['error']) aria-describedby="{{ $field['hintId'] }}" @endif data-test="order-field-input">
                            <option value="">{{ $field['required'] ? '選んでください' : 'なし' }}</option>
                            @foreach ($field['options'] as $option)
                                <option value="{{ $option }}" @selected($field['value'] === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    @elseif ($field['type'] === 'textarea')
                        <textarea id="{{ $field['inputId'] }}" name="{{ $field['inputName'] }}" rows="4" maxlength="{{ $field['maxLength'] }}" class="sugar-order-fields__input sugar-order-fields__textarea"
                                  @if ($field['required']) aria-required="true" @endif @if ($field['error']) aria-invalid="true" @endif
                                  @if ($field['description'] !== null || $field['error']) aria-describedby="{{ $field['hintId'] }}" @endif data-test="order-field-input">{{ $field['value'] }}</textarea>
                    @else
                        <input id="{{ $field['inputId'] }}" name="{{ $field['inputName'] }}" type="text" value="{{ $field['value'] }}" maxlength="{{ $field['maxLength'] }}" autocomplete="off" class="sugar-order-fields__input"
                               @if ($field['required']) aria-required="true" @endif @if ($field['error']) aria-invalid="true" @endif
                               @if ($field['description'] !== null || $field['error']) aria-describedby="{{ $field['hintId'] }}" @endif data-test="order-field-input">
                    @endif
                    @if ($field['error'])
                        <span id="{{ $field['hintId'] }}" class="sugar-order-fields__error" role="alert" data-test="order-field-error">{{ $field['error'] }}</span>
                    @elseif ($field['description'] !== null)
                        <span id="{{ $field['hintId'] }}" class="sugar-order-fields__hint">{{ $field['description'] }}</span>
                    @endif
                </label>
            @endif
        @endforeach
    </fieldset>
@endif
