{{--
    会員の届け先の入力欄（ADR 0044 の 3）。核の部品。名前は address[…]、決まりは注文の入力のお届け先と同じ（CheckoutInput::address）。
    $form は ['values' => [...], 'errors' => [...]]。贈り物の受け取りの画面（ADR 0055）でも使う。
    郵便番号を入れると、核の JavaScript が住所を入れる（data-postal-* の印。囲みは並びに影響しない display: contents）
--}}
@props(['form'])
<div class="sugar-address-fields" data-postal-group>
    @foreach (\App\Storefront\Members\AddressView::FIELDS as $key => [$label, $type, $autocomplete, $max, $required])
        <div class="sugar-account__field">
            <label class="sugar-account__label" for="address-{{ $key }}">{{ $label }}</label>
            @if ($type === 'select')
                <select id="address-{{ $key }}" name="address[{{ $key }}]" autocomplete="{{ $autocomplete }}" required class="sugar-account__input" data-postal-prefecture data-test="address-{{ $key }}">
                    <option value="">選んでください</option>
                    @foreach (\App\Storefront\Postal\Prefectures::ALL as $prefecture)
                        <option value="{{ $prefecture }}" @selected(($form['values'][$key] ?? '') === $prefecture)>{{ $prefecture }}</option>
                    @endforeach
                </select>
            @else
                <input id="address-{{ $key }}" type="{{ $type }}" name="address[{{ $key }}]" value="{{ $form['values'][$key] ?? '' }}" maxlength="{{ $max }}" autocomplete="{{ $autocomplete }}" @required($required) class="sugar-account__input"
                       @if ($key === 'postal') inputmode="numeric" data-postal-code @elseif ($key === 'city') data-postal-city @elseif ($key === 'line1') data-postal-line1 @endif data-test="address-{{ $key }}">
            @endif
            @if (isset($form['errors'][$key]))
                <p class="sugar-account__field-error" data-test="address-error-{{ $key }}">{{ $form['errors'][$key] }}</p>
            @endif
        </div>
        @if ($key === 'postal')
            <p class="sugar-account__hint" data-postal-status aria-live="polite"></p>
        @endif
    @endforeach
</div>
