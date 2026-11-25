{{--
    お届け日時（ADR 0059）。核の部品で、テーマの注文の入力の画面が、フォームの中のお届け先の欄の後に置く。
    店が日時の指定を受けていなければ何も出さない（誤りだけは出す: 入力の後に店が受けるのをやめたとき）。
    選択肢はサーバーが今の時刻と店の決まりで作る（<select> だけ。JavaScript は使わない）。「贈り物として送る」を選ぶと隠す（届く日は受け取る人しだい。隠し方は核の CSS）
--}}
@props(['form'])
@if (($delivery = $form->delivery()) !== null)
    <fieldset class="sugar-delivery" data-delivery data-test="delivery-fields">
        <legend class="sugar-delivery__title">お届け日時</legend>
        <p class="sugar-delivery__note">
            @if ($delivery->earliestLabel() !== null)
                最短のお届け日は {{ $delivery->earliestLabel() }} です。
            @endif
            ご希望がなければ「指定なし」のままお進みください。
            @if ($form->giftAvailable())
                贈り物として送る場合は、お届け日時を指定できません。
            @endif
        </p>
        @if ($delivery->dates !== [])
            <label class="sugar-delivery__field" for="{{ $form->id('delivery.date') }}">
                <span class="sugar-delivery__label">お届け日 <span class="sugar-delivery__optional">任意</span></span>
                <select id="{{ $form->id('delivery.date') }}" name="{{ $form->name('delivery.date') }}" class="sugar-delivery__input"
                        @if ($form->error('delivery.date')) aria-invalid="true" @endif data-test="delivery-date">
                    <option value="">指定なし</option>
                    @foreach ($delivery->dates as $option)
                        <option value="{{ $option['value'] }}" @selected($form->value('delivery.date') === $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
                @if ($form->error('delivery.date'))
                    <span class="sugar-delivery__error" role="alert" data-test="delivery-date-error">{{ $form->error('delivery.date') }}</span>
                @endif
            </label>
        @elseif ($form->error('delivery.date'))
            <p class="sugar-delivery__error" role="alert" data-test="delivery-date-error">{{ $form->error('delivery.date') }}</p>
        @endif
        @if ($delivery->slots !== [])
            <label class="sugar-delivery__field" for="{{ $form->id('delivery.slot') }}">
                <span class="sugar-delivery__label">時間帯 <span class="sugar-delivery__optional">任意</span></span>
                <select id="{{ $form->id('delivery.slot') }}" name="{{ $form->name('delivery.slot') }}" class="sugar-delivery__input"
                        @if ($form->error('delivery.slot')) aria-invalid="true" @endif data-test="delivery-slot">
                    <option value="">指定なし</option>
                    @foreach ($delivery->slots as $option)
                        <option value="{{ $option['value'] }}" @selected($form->value('delivery.slot') === $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
                @if ($form->error('delivery.slot'))
                    <span class="sugar-delivery__error" role="alert" data-test="delivery-slot-error">{{ $form->error('delivery.slot') }}</span>
                @endif
            </label>
        @elseif ($form->error('delivery.slot'))
            <p class="sugar-delivery__error" role="alert" data-test="delivery-slot-error">{{ $form->error('delivery.slot') }}</p>
        @endif
    </fieldset>
@elseif ($form->error('delivery.date') || $form->error('delivery.slot'))
    <p class="sugar-delivery__error" role="alert" data-test="delivery-error">{{ $form->error('delivery.date') ?? $form->error('delivery.slot') }}</p>
@endif
