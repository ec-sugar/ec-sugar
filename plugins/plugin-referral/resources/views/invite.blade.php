{{--
    マイページの「友だちを紹介する」（App「友だち紹介」、ADR 0075 の 1）。自分の紹介のリンクを写す・LINE で送る・作り直す。
    「写す」は核の JavaScript（data-gift-share の印。贈り物の受け取りのリンクと同じ部品）で、JavaScript が無ければ欄の文字を選んで写す
--}}
@extends($theme->view('layout'))

@section('title', '友だちを紹介する — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="referral-invite">
        <h1 class="sugar-page__title">友だちを紹介する</h1>
        @if ($message)
            <p role="status" class="sugar-account__notice" data-test="referral-message">{{ $message }}</p>
        @endif

        <section class="sugar-gift-share" data-gift-share aria-labelledby="referral-link-title">
            <h2 id="referral-link-title" class="sugar-gift-share__title">あなたの紹介のリンク</h2>
            <p class="sugar-gift-share__note" data-test="referral-points">
                このリンクから会員になった方の最初のご注文が発送されると、
                @if ($referrerPoints > 0 && $friendPoints > 0)
                    あなたに {{ number_format($referrerPoints) }} ポイント、お友だちに {{ number_format($friendPoints) }} ポイントが付きます。
                @elseif ($referrerPoints > 0)
                    あなたに {{ number_format($referrerPoints) }} ポイントが付きます。
                @elseif ($friendPoints > 0)
                    お友だちに {{ number_format($friendPoints) }} ポイントが付きます。
                @else
                    お知らせします（今はポイントは付きません）。
                @endif
            </p>
            <label class="sugar-gift-share__label" for="referral-link">紹介したい方へ、このリンクを送ってください</label>
            <div class="sugar-gift-share__row">
                <input id="referral-link" type="text" readonly value="{{ $url }}" class="sugar-gift-share__link" data-gift-link data-test="referral-link">
                <button type="button" class="sugar-gift-share__copy" data-gift-copy hidden data-test="referral-copy">コピー</button>
            </div>
            <p class="sugar-gift-share__copied" data-gift-copied aria-live="polite"></p>
            <a href="{{ $lineUrl }}" class="sugar-gift-share__line" target="_blank" rel="noopener noreferrer" data-test="referral-line">LINE で送る</a>
        </section>

        <section class="sugar-account__section" aria-labelledby="referral-rules">
            <h2 id="referral-rules" class="sugar-page__heading">ポイントが付く時</h2>
            <ul class="sugar-page__note">
                <li>リンクを開いてから 30 日のうちに、新しく会員になった方が対象です（すでに会員の方・会員になる前にご注文のあった方は対象外です）。</li>
                <li>お友だちの最初のご注文が発送された時に付きます。最初のご注文が取り消された時は付きません。</li>
                <li>ご自身や、同じメール・電話番号・お届け先の方の紹介には付きません。</li>
                <li>あなたが紹介でポイントを受け取れるのは、1 か月に {{ $monthlyLimit }} 人までです（今月は {{ $rewardedThisMonth }} 人）。</li>
                <li>お店が内容を確認してから付ける場合があります。</li>
            </ul>
        </section>

        <section class="sugar-account__section" aria-labelledby="referral-renew">
            <h2 id="referral-renew" class="sugar-page__heading">リンクを作り直す</h2>
            <p class="sugar-page__note">新しいリンクを作ると、今のリンクは使えなくなります（リンクが知らない方に広まってしまった時などにお使いください）。</p>
            <form method="post" action="/p/referral/invite/renew" class="sugar-account__actions">
                @csrf
                <button type="submit" class="sugar-account__secondary" data-test="referral-renew">リンクを作り直す</button>
            </form>
        </section>

        <p class="sugar-account__actions"><a href="{{ route('storefront.account') }}" class="sugar-account__secondary">マイページへ戻る</a></p>
    </div>
@endsection
