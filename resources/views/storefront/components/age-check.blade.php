{{--
    年齢の確かめ（ADR 0077、App「年齢確認」）。核の部品で、テーマの注文の入力の画面がフォームの中の注文の項目の後に置く
    （App の申し込みの画面〔定期便など〕も同じ部品を置く）。$offer は AgeOffer（年齢の要る品が無ければ null で、何も出さない）。
    生年月日（会員が前にこの年齢以上を確かめていれば聞かない）と「◯ 歳以上です」の同意。贈り物を選ぶと、受け取る方の同意の欄も出す
    （隠し方は核の CSS。JavaScript は使わない）。生年月日はサーバーが満年齢を数えたら捨てる（入れ直しの時も欄に戻さない）
--}}
@props(['offer' => null])
@if ($offer instanceof \App\Storefront\Checkout\AgeOffer)
    <fieldset class="sugar-order-fields sugar-age-check" data-test="age-check">
        <legend class="sugar-order-fields__title">年齢の確認</legend>
        <p class="sugar-order-fields__hint" data-test="age-check-notice">{{ $offer->notice() }}生年月日は年齢の確認にだけ使い、ショップには保存しません。</p>
        @if ($offer->needsBirthdate)
            <label class="sugar-order-fields__field" for="age-birthdate">
                <span class="sugar-order-fields__label">生年月日 <span class="sugar-order-fields__required">必須</span></span>
                <input id="age-birthdate" name="age[birthdate]" type="date" value="" min="{{ $offer->minDate() }}" max="{{ $offer->maxDate() }}" autocomplete="bday" class="sugar-order-fields__input"
                       aria-required="true" @if ($offer->error('birthdate')) aria-invalid="true" aria-describedby="age-birthdate-error" @endif data-test="age-birthdate">
                @if ($offer->error('birthdate'))
                    <span id="age-birthdate-error" class="sugar-order-fields__error" role="alert" data-test="age-error">{{ $offer->error('birthdate') }}</span>
                @endif
            </label>
        @else
            <p class="sugar-order-fields__hint" data-test="age-remembered">年齢の確認は済んでいます（会員情報か、先ほどのご入力で確認しました）。</p>
        @endif
        <div class="sugar-order-fields__field">
            <label class="sugar-order-fields__check" for="age-consent">
                <input type="hidden" name="age[consent]" value="0">
                <input type="checkbox" id="age-consent" name="age[consent]" value="1" @checked($offer->consent) aria-required="true"
                       @if ($offer->error('consent')) aria-invalid="true" aria-describedby="age-consent-error" @endif data-test="age-consent">
                <span class="sugar-order-fields__label">{{ $offer->consentText() }} <span class="sugar-order-fields__required">必須</span></span>
            </label>
            @if ($offer->error('consent'))
                <span id="age-consent-error" class="sugar-order-fields__error" role="alert" data-test="age-error">{{ $offer->error('consent') }}</span>
            @endif
        </div>
        @if ($offer->giftPossible)
            <div class="sugar-order-fields__field sugar-age-check__recipient" data-age-recipient>
                <label class="sugar-order-fields__check" for="age-recipient">
                    <input type="hidden" name="age[recipient]" value="0">
                    <input type="checkbox" id="age-recipient" name="age[recipient]" value="1" @checked($offer->recipient)
                           @if ($offer->error('recipient')) aria-invalid="true" aria-describedby="age-recipient-error" @endif data-test="age-recipient">
                    <span class="sugar-order-fields__label">{{ $offer->recipientText() }} <span class="sugar-order-fields__required">贈り物の場合は必須</span></span>
                </label>
                @if ($offer->error('recipient'))
                    <span id="age-recipient-error" class="sugar-order-fields__error" role="alert" data-test="age-error">{{ $offer->error('recipient') }}</span>
                @endif
            </div>
        @endif
    </fieldset>
@endif
