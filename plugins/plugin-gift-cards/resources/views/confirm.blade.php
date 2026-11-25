{{--
    ギフトカードのご注文の最後の確認（ADR 0076）。特定商取引法の最終確認の表示: 分量（額面と枚数）・支払総額・支払いの方法と時期・引き渡しの時期・申込みの撤回の条件、と有効期限
--}}
@extends($theme->view('layout'))

@section('title', 'ギフトカードのご注文の確認 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="gift-cards-confirm">
        <h1 class="sugar-page__title">ご注文の確認</h1>
        @if ($testMode)
            <p role="note" class="sugar-account__error" data-test="gift-cards-test-mode">いまはテストの支払いです。お店の動作確認用のため、本物のギフトカードは購入できません（テスト中に発行したカードは、本番のお支払いに切り替えると使えなくなります）。</p>
        @endif
        @if ($flash['error'])
            <p role="alert" class="sugar-account__error" data-test="gift-cards-error">{{ $flash['error'] }}</p>
        @endif

        <section class="sugar-account__section" aria-labelledby="gift-cards-what">
            <h2 id="gift-cards-what" class="sugar-page__heading">ご注文の内容</h2>
            <dl class="sugar-page__table" data-test="gift-cards-terms">
                <div><dt>商品</dt><dd>ギフトカード {{ $amountLabel }} × 1</dd></div>
                <div><dt>お支払いの合計</dt><dd><strong data-test="gift-cards-total">{{ $amountLabel }}</strong>（ギフトカードの発行には消費税がかかりません。送料はかかりません）</dd></div>
                <div><dt>お支払いの方法と時期</dt><dd>クレジットカード（ご注文の確定後に表示されるお支払いの画面で、すぐにお支払いいただきます）</dd></div>
                <div><dt>お届け</dt><dd>お支払いが完了したら、すぐにコードをメールでお届けします（{{ $request['email'] }}@if ($request['recipient_email']) と、贈る相手の {{ $request['recipient_email'] }}@endif）</dd></div>
                <div><dt>有効期限</dt><dd>発行から {{ $validDays }} 日。期限を過ぎた残高は使えなくなります</dd></div>
                <div><dt>お名前</dt><dd>{{ $request['name'] }}</dd></div>
                @if ($request['message'])
                    <div><dt>メッセージ</dt><dd>{{ $request['message'] }}</dd></div>
                @endif
            </dl>
        </section>

        <section class="sugar-account__section" aria-labelledby="gift-cards-cancel">
            <h2 id="gift-cards-cancel" class="sugar-page__heading">取り消し・払い戻しについて</h2>
            <p class="sugar-page__note" data-test="gift-cards-cancel-terms">お支払いの後のご注文の取り消しと、ギフトカードの払い戻し（現金への交換）はできません。コードをなくしたときや、ほかの人に知られたときは、すぐにお店にご連絡ください（まだ使われていない残高を使えないようにできます）。</p>
        </section>

        <form method="post" action="/p/gift-cards/place" class="sugar-account__form">
            @csrf
            <button type="submit" class="sugar-account__primary" data-test="gift-cards-place">注文を確定して、お支払いへ進む</button>
        </form>
        <p class="sugar-account__foot"><a href="/p/gift-cards">内容を修正する</a></p>
    </div>
@endsection
