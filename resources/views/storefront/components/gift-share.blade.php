{{--
    贈り物の様子と受け取りのリンク（ADR 0055）。核の部品で、テーマの注文を受け付けた画面（checkout-complete）と
    ご注文の状況（order）が置く。$gift は GiftShare か null（贈り物でない注文では何も出さない）。
    受け取りのリンク（url）は、App のタグを差し込まない画面にだけ入る（注文を受け付けた画面では、買う人だけが開ける画面への入口だけ）。
    「写す」は JavaScript があるときだけ出す（無ければ欄の文字を選んで写す）。「LINE で送る」はふつうのリンク
--}}
@props(['gift'])
@if ($gift !== null)
    <section class="sugar-gift-share" data-gift-share data-test="gift-share" aria-labelledby="sugar-gift-share-title">
        <h2 id="sugar-gift-share-title" class="sugar-gift-share__title">贈り物（お届け先は受け取る方が入力します）</h2>
        <p class="sugar-gift-share__status" data-test="gift-status" data-gift-status="{{ $gift->status }}">{{ $gift->statusLabel }}</p>
        @if ($gift->waiting() && $gift->expiresOn)
            <p class="sugar-gift-share__note" data-test="gift-expires">受け取りの期限: {{ $gift->expiresOn }}（期限までにお届け先の入力がないときは、ご注文を取り消して全額を返金します）</p>
        @endif
        @if ($gift->url)
            <label class="sugar-gift-share__label" for="sugar-gift-link">受け取り用のリンク（贈りたい方へ送ってください）</label>
            <div class="sugar-gift-share__row">
                <input id="sugar-gift-link" type="text" readonly value="{{ $gift->url }}" class="sugar-gift-share__link" data-gift-link data-test="gift-link">
                <button type="button" class="sugar-gift-share__copy" data-gift-copy hidden data-test="gift-copy">コピー</button>
            </div>
            <p class="sugar-gift-share__copied" data-gift-copied aria-live="polite"></p>
            <a href="{{ $gift->lineUrl() }}" class="sugar-gift-share__line" target="_blank" rel="noopener noreferrer" data-test="gift-line">LINE で送る</a>
            <p class="sugar-gift-share__note">このリンクを知っている方は、どなたでも受け取れます。別の方に送ってしまったときは、リンクを作り直してください（前のリンクは使えなくなります）。</p>
        @endif
        @if ($gift->manageUrl)
            <a href="{{ $gift->manageUrl }}" class="sugar-gift-share__manage" data-test="gift-manage">{{ ! $gift->waiting() ? '贈り物の状況を見る' : ($gift->url === null ? '受け取り用のリンクを表示する（コピー・LINE で送る）' : 'リンクの作り直し・贈り物の取り消し') }}</a>
        @endif
    </section>
@endif
