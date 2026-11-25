{{--
    退会（ADR 0044 の 3。2026-11-01 に運営が決めた形）。核が描く。本人の再確認の後だけ開ける。
    会員のログインと届け先を消す。ご注文の記録は残る（帳簿として。ご注文番号での確認もそのまま）
--}}
@extends($theme->view('layout'))

@section('title', '退会 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="account-leave">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">退会</h1>
                <p class="sugar-page__lead">退会すると、このショップの会員アカウント・登録したお届け先・パスキーが削除されます。ほかの端末でもログアウトされます。</p>
            </div>
            <p class="sugar-account__notice">これまでのご注文の記録は、ショップの取引の記録として残ります（ご注文番号での確認もできます）。個人情報の削除をご希望の場合は、ショップへお問い合わせください。</p>
            @if ($appLinks !== [])
                <p class="sugar-account__notice" data-test="leave-apps">{{ collect($appLinks)->pluck('label')->join('・') }}のお申し込みも、あわせて解約します（お支払い済み・お支払い手続き中の回は、お届けします）。</p>
            @endif
            <form method="post" action="{{ route('storefront.account.leave.submit') }}" class="sugar-account__form">
                @csrf
                <button type="submit" class="sugar-account__primary" data-test="leave-submit">退会する</button>
            </form>
        </div>
        <p class="sugar-account__foot"><a href="{{ route('storefront.account') }}">退会せずにマイページへ戻る</a></p>
    </div>
@endsection
