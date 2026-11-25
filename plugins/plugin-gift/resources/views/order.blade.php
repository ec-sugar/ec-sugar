{{--
    買う人の贈り物の画面（ADR 0055 の 3）。受け取りのリンクを写す・LINE で送る・作り直す・受け取られる前なら取り消す。開けるのは買う人だけ（受け口が確かめる）。
    $gift は核の GiftShare（受け取りのリンクも入る）。「写す」は核の JavaScript（data-gift-share の印）
--}}
@extends($theme->view('layout'))

@section('title', '贈り物 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="gift-order">
        <h1 class="sugar-page__title">贈り物</h1>
        <p class="sugar-page__note">ご注文番号 {{ $number }}</p>
        @if ($notice)
            <p role="status" class="sugar-account__notice" data-test="gift-notice">{{ $notice }}</p>
        @endif
        @if ($error)
            <p role="alert" class="sugar-account__error" data-test="gift-error">{{ $error }}</p>
        @endif

        <section class="sugar-gift-share" data-gift-share aria-labelledby="gift-order-status">
            <h2 id="gift-order-status" class="sugar-gift-share__title">受け取りのリンク</h2>
            <p class="sugar-gift-share__status" data-test="gift-status" data-gift-status="{{ $gift->status }}">{{ $gift->statusLabel }}</p>
            @if ($gift->waiting() && $gift->expiresOn)
                <p class="sugar-gift-share__note" data-test="gift-expires">受け取りの期限: {{ $gift->expiresOn }}（期限までにお相手がお届け先を入力しないときは、ご注文を取り消して全額を返金します）</p>
            @endif
            @if ($gift->url)
                <label class="sugar-gift-share__label" for="gift-link">贈りたい方へ、このリンクを送ってください</label>
                <div class="sugar-gift-share__row">
                    <input id="gift-link" type="text" readonly value="{{ $gift->url }}" class="sugar-gift-share__link" data-gift-link data-test="gift-link">
                    <button type="button" class="sugar-gift-share__copy" data-gift-copy hidden data-test="gift-copy">コピー</button>
                </div>
                <p class="sugar-gift-share__copied" data-gift-copied aria-live="polite"></p>
                <a href="{{ $gift->lineUrl() }}" class="sugar-gift-share__line" target="_blank" rel="noopener noreferrer" data-test="gift-line">LINE で送る</a>
                <p class="sugar-gift-share__note">このリンクを知っている方なら、誰でも受け取れます。送り間違えたときは、下の「リンクを作り直す」で今のリンクを使えなくしてください。</p>
            @endif
        </section>

        <section class="sugar-account__section" aria-labelledby="gift-order-note">
            <h2 id="gift-order-note" class="sugar-page__heading">お相手に表示される内容</h2>
            <p class="sugar-page__note">差出人のお名前: <span data-test="gift-sender">{{ $sender }}</span></p>
            <p class="sugar-gift-summary__message" data-test="gift-message">{{ $message === '' ? 'メッセージ: なし' : $message }}</p>
            <p class="sugar-page__note">金額はお相手に表示されません。</p>
        </section>

        @if ($renewable)
            <section class="sugar-account__section" aria-labelledby="gift-order-actions">
                <h2 id="gift-order-actions" class="sugar-page__heading">リンクを作り直す</h2>
                <p class="sugar-page__note">新しいリンクを作ると、今のリンクは使えなくなります。</p>
                <form method="post" action="/p/gift/orders/{{ $orderId }}/renew" class="sugar-account__actions">
                    @csrf
                    <button type="submit" class="sugar-account__secondary" data-test="gift-renew">リンクを作り直す</button>
                </form>
            </section>
        @endif

        @if ($cancellable)
            <section class="sugar-account__section" aria-labelledby="gift-order-cancel">
                <h2 id="gift-order-cancel" class="sugar-page__heading">取り消す</h2>
                <p class="sugar-page__note">お相手が受け取る前なら、取り消して全額を返金できます。</p>
                <p class="sugar-account__actions"><a href="/p/gift/orders/{{ $orderId }}/cancel" class="sugar-account__secondary" data-test="gift-cancel-link">取り消して返金する</a></p>
            </section>
        @endif
    </div>
@endsection
