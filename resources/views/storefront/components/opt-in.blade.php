{{--
    お知らせのメールを受け取る（App「メルマガ・一斉メール」）。核の部品で、テーマの注文の入力の画面が、フォームの中のお届け先の欄の後に置く。
    App を使っていない店では何も出さない。切り替えはいつも選んでいない状態から（同意は本人が選ぶ。特定電子メール法のオプトイン）。
    選んだ人の注文が確定した後にだけ、App がメールを受け取る（会員でないお客さんには確かめのメールが届く）
--}}
@props(['form'])
@if ($form->optInOffer() !== null)
    <div class="sugar-opt-in" data-test="opt-in">
        <label class="sugar-opt-in__toggle">
            <input type="hidden" name="opt_in" value="0">
            <input type="checkbox" name="opt_in" value="1" @checked($form->optIn()) data-test="opt-in-toggle">
            <span>{{ $form->optInOffer()['label'] }}</span>
        </label>
        @if ($form->optInOffer()['note'] !== '')
            <p class="sugar-opt-in__note">{{ $form->optInOffer()['note'] }}</p>
        @endif
    </div>
@endif
