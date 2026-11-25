{{--
    ポイントを使う欄（核の部品。ADR 0068）。テーマの注文の入力の画面が、フォームの中のお届け先の欄の後に置く。
    使い方（テーマ）: <x-storefront::points-fields :form="$form" />
    ポイントを使える会員で残高がある時だけ出す（ゲスト・使わない店・残高 0 では何も出さない）。使わない・全部使う・数を入れて使う の 3 つから選ぶ。
    使う数は値引きの後の合計（送料込み）までで、確かめの画面に出る。残高を超える数は入力の画面で断る（理由は欄の下に）
--}}
@props(['form'])
@if ($form->pointsOffer() !== null)
    <fieldset class="sugar-points-fields" data-test="points-fields">
        <legend class="sugar-points-fields__legend">ポイントを使う</legend>
        <p class="sugar-points-fields__balance" data-test="points-balance">
            お持ちのポイント <strong class="tabular">{{ number_format($form->pointsOffer()['balance']) }}</strong> ポイント（1 ポイント = 1 円）@if ($form->pointsOffer()['expiresAt'])<span class="sugar-points-fields__expiry">　有効期限 {{ $form->pointsOffer()['expiresAt'] }}</span>@endif
        </p>
        <label class="sugar-points-fields__choice"><input type="radio" name="points[mode]" value="none" @checked($form->pointsMode() === 'none') data-test="points-none"> 使わない</label>
        <label class="sugar-points-fields__choice"><input type="radio" name="points[mode]" value="all" @checked($form->pointsMode() === 'all') data-test="points-all"> 全部使う</label>
        <label class="sugar-points-fields__choice">
            <input type="radio" name="points[mode]" value="some" @checked($form->pointsMode() === 'some') data-test="points-some"> ポイント数を指定して使う
            <input type="number" name="points[amount]" inputmode="numeric" min="1" max="{{ $form->pointsOffer()['balance'] }}" step="1" value="{{ $form->value('points.amount') }}"
                class="sugar-points-fields__amount" aria-label="使うポイント" @if ($form->pointsError()) aria-invalid="true" aria-describedby="points-amount-error" @endif data-test="points-amount"> ポイント
        </label>
        @if ($form->pointsError())
            <p id="points-amount-error" class="sugar-points-fields__error" role="alert" data-test="points-error">{{ $form->pointsError() }}</p>
        @endif
        <p class="sugar-points-fields__note">ご注文の合計（送料込み）までお使いいただけます。ポイントでお支払いいただいた金額には、ポイントは付きません。</p>
    </fieldset>
@endif
