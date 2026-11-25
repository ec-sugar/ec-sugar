{{--
    ギフトカードを使う欄（核の部品。ADR 0076）。テーマの注文の入力の画面が、フォームの中のポイントの欄の後に置く。
    使い方（テーマ）: <x-storefront::gift-card-fields :form="$form" />
    店がギフトカードを 1 枚でも発行していれば出す（会員でなくても使える）。コードを入れると、確かめの画面で値引き → ポイントの後の合計に充てる。
    使っているカードは末尾 4 文字と残高だけを出す（コードそのものは画面に出さない・入れ直した値にも戻さない）。誤り（見つからない・使えない・回数を超えた）は欄の下に
--}}
@props(['form'])
@if ($form->giftCardOffered())
    <fieldset class="sugar-gift-card-fields" data-test="gift-card-fields">
        <legend class="sugar-gift-card-fields__legend">ギフトカード</legend>
        @if ($form->giftCardInUse() !== null)
            <p class="sugar-gift-card-fields__current" data-test="gift-card-current">
                末尾 <strong>{{ $form->giftCardInUse()['last4'] }}</strong> のギフトカードを使用します（残高 <span class="tabular">{{ \App\Core\Money\Money::format($form->giftCardInUse()['balance']) }}</span>・有効期限 {{ $form->giftCardInUse()['expiresAt'] }}）
            </p>
            <label class="sugar-gift-card-fields__remove"><input type="checkbox" name="gift_card[remove]" value="1" data-test="gift-card-remove"> このギフトカードを使わない</label>
        @endif
        <label class="sugar-gift-card-fields__label" for="gift-card-code">{{ $form->giftCardInUse() !== null ? 'ほかのギフトカードのコード' : 'ギフトカードのコード' }}</label>
        <input id="gift-card-code" type="text" name="gift_card[code]" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="64" placeholder="XXXX-XXXX-XXXX-XXXX"
            class="sugar-gift-card-fields__code" @if ($form->giftCardError()) aria-invalid="true" aria-describedby="gift-card-code-error" @endif data-test="gift-card-code">
        @if ($form->giftCardError())
            <p id="gift-card-code-error" class="sugar-gift-card-fields__error" role="alert" data-test="gift-card-error">{{ $form->giftCardError() }}</p>
        @endif
        <p class="sugar-gift-card-fields__note">ご注文の合計（割引・ポイント利用後、送料込み）までお使いいただけます。不足分は次の画面でお支払いください。</p>
    </fieldset>
@endif
