{{--
    マイページの「ダウンロード」（App「ダウンロード販売」）。会員の自分の注文のうち、ダウンロードのある新しい 30 件。
    出すのは注文番号と日付だけ。開くと注文のダウンロードの画面へ
--}}
@extends($theme->view('layout'))

@section('title', 'ダウンロード — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="downloads-account">
        <p class="sugar-account__back"><a href="{{ route('storefront.account') }}">← マイページ</a></p>
        <h1 class="sugar-page__title">ダウンロード</h1>
        <p class="sugar-page__lead">ご購入いただいたファイルをダウンロードできます。ダウンロードの期限と回数は、ご注文ごとに表示します。</p>

        @if ($orders === [])
            <p class="sugar-page__empty" data-test="downloads-none">ダウンロードできるご注文はありません。</p>
        @else
            <ul class="sugar-account__list">
                @foreach ($orders as $order)
                    <li class="sugar-account__order" data-test="downloads-account-order">
                        <p class="sugar-account__order-head">
                            <span>{{ $order['placedAt'] }}</span>
                            <span class="sugar-page__note">ご注文番号 {{ $order['number'] }}</span>
                        </p>
                        <a href="{{ $order['url'] }}" data-test="downloads-open">ダウンロードの画面へ</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
