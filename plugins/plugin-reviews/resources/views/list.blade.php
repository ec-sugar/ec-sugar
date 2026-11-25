{{--
    レビューを書ける商品の一覧（App「商品レビュー」）。会員のマイページからは書ける注文のすべて、ご注文の確認からはその注文だけ。
    App の画面で、テーマの外枠の中に描く（App のタグは差し込まない）。出すのは注文番号・日付・商品の名前・書いたかどうかだけ
--}}
@extends($theme->view('layout'))

@section('title', 'レビュー — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="reviews-list">
        @if ($member)
            <p class="sugar-account__back"><a href="{{ route('storefront.account') }}">← マイページ</a></p>
        @endif
        <h1 class="sugar-page__title">レビューを書く</h1>
        <p class="sugar-page__lead">お買い上げの商品の感想をお聞かせください。発送後に書けます。1 回のご注文につき、商品ごとに 1 件書けます。</p>
        @if ($message)
            <p role="status" class="sugar-account__notice" data-test="reviews-message">{{ $message }}</p>
        @endif

        @if ($orders === [])
            <p class="sugar-page__empty" data-test="reviews-empty">いまレビューを書けるご注文はありません（発送後に書けます）。</p>
        @else
            <ul class="sugar-account__list">
                @foreach ($orders as $order)
                    <li class="sugar-account__order" data-test="reviews-order">
                        <p class="sugar-account__order-head">
                            <span>{{ $order['placedAt'] }}</span>
                            <span class="sugar-page__note">ご注文番号 {{ $order['number'] }}</span>
                        </p>
                        <ul class="sugar-account__lines">
                            @foreach ($order['items'] as $item)
                                <li data-test="reviews-item">
                                    <span>{{ $item['title'] }}</span>
                                    @if ($item['url'])
                                        <a href="{{ $item['url'] }}" data-test="reviews-write">レビューを書く</a>
                                    @else
                                        <span class="sugar-page__note" data-test="reviews-state">{{ $item['stateLabel'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
