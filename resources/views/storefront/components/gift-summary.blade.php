{{--
    注文の最終確認の画面の「お届け先」の代わり（贈り物。ADR 0055）。核の部品で、テーマの確認の画面がお届け先の所に置く。
    $gift は確かめた後の差出人とメッセージ（GiftNote）。文はすべてエスケープして出す（メッセージの改行はそのまま）
--}}
@props(['gift'])
<div class="sugar-gift-summary" data-test="confirm-gift">
    <p class="sugar-gift-summary__lead">贈り物（お届け先は受け取る方が入力します）</p>
    <p class="sugar-gift-summary__note">ご注文の後に、受け取り用のリンクをお渡しします。受け取る方に金額は表示されません。</p>
    <dl class="sugar-gift-summary__list">
        <dt>差出人のお名前</dt>
        <dd data-test="confirm-gift-sender">{{ $gift->sender }}</dd>
        <dt>メッセージ</dt>
        <dd class="sugar-gift-summary__message" data-test="confirm-gift-message">{{ $gift->message === '' ? '（なし）' : $gift->message }}</dd>
    </dl>
</div>
