{{--
    贈り物として送る（ADR 0055）。核の部品で、テーマの注文の入力の画面が、フォームの中のお届け先の欄の前に置く。
    贈り物の App を使っていない店では何も出さない。選ぶとお届け先の欄（テーマが data-ship-to の印を付けたもの）を隠し、
    差出人の表示名とメッセージを出す（JavaScript は使わない。隠し方は核の CSS。隠せないブラウザでも、贈り物ならお届け先の欄は使わない）
--}}
@props(['form'])
@if ($form->giftAvailable())
    <fieldset class="sugar-gift" data-gift data-test="gift-fields">
        <legend class="sugar-gift__title">贈り物</legend>
        <label class="sugar-gift__toggle">
            <input type="hidden" name="gift[on]" value="0">
            <input type="checkbox" name="gift[on]" value="1" @checked($form->gift()) data-gift-toggle data-test="gift-toggle">
            <span>贈り物として送る（お届け先は受け取る方が入力します）</span>
        </label>
        <p class="sugar-gift__note">ご注文の後に、受け取り用のリンクをお渡しします。そのリンクを贈りたい方へ送ってください。リンクを開いた方がお届け先を入力すると、商品をお届けします。受け取る方に金額は表示されません。期限までにお届け先の入力がないときは、ご注文を取り消して全額を返金します。</p>
        @if ($form->error('gift.on'))
            <p class="sugar-gift__error" role="alert" data-test="gift-error">{{ $form->error('gift.on') }}</p>
        @endif
        <div class="sugar-gift__fields" data-gift-fields>
            <label class="sugar-gift__field" for="{{ $form->id('gift.sender') }}">
                <span class="sugar-gift__label">差出人のお名前 <span class="sugar-gift__optional">任意</span></span>
                <input id="{{ $form->id('gift.sender') }}" name="{{ $form->name('gift.sender') }}" type="text" value="{{ $form->value('gift.sender') }}" maxlength="{{ \App\Core\Plugin\Gift\GiftNote::SENDER_MAX }}" autocomplete="off"
                       @if ($form->error('gift.sender')) aria-invalid="true" @endif class="sugar-gift__input" data-test="gift-sender">
                @if ($form->error('gift.sender'))
                    <span class="sugar-gift__error" role="alert">{{ $form->error('gift.sender') }}</span>
                @else
                    <span class="sugar-gift__hint">受け取る方に表示するお名前です。空欄の場合は、ご依頼主のお名前を表示します（ニックネームでもかまいません）。</span>
                @endif
            </label>
            <label class="sugar-gift__field" for="{{ $form->id('gift.message') }}">
                <span class="sugar-gift__label">メッセージ <span class="sugar-gift__optional">任意</span></span>
                <textarea id="{{ $form->id('gift.message') }}" name="{{ $form->name('gift.message') }}" rows="4" maxlength="{{ \App\Core\Plugin\Gift\GiftNote::MESSAGE_MAX }}"
                          @if ($form->error('gift.message')) aria-invalid="true" @endif class="sugar-gift__input sugar-gift__textarea" data-test="gift-message">{{ $form->value('gift.message') }}</textarea>
                @if ($form->error('gift.message'))
                    <span class="sugar-gift__error" role="alert">{{ $form->error('gift.message') }}</span>
                @else
                    <span class="sugar-gift__hint">受け取る方の画面に表示します（{{ \App\Core\Plugin\Gift\GiftNote::MESSAGE_MAX }} 文字まで。改行できます）。</span>
                @endif
            </label>
        </div>
    </fieldset>
@endif
