{{--
    マイページの贈り物（ADR 0055 の 3）。会員が贈り物として買った注文の一覧。開くと、受け取りのリンク・作り直す・取り消すの画面へ
--}}
@extends($theme->view('layout'))

@section('title', '贈り物 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="gift-account">
        <h1 class="sugar-page__title">贈り物</h1>
        @if ($items === [])
            <p class="sugar-page__empty" data-test="gift-empty">贈り物のご注文はありません。</p>
        @else
            <ul class="sugar-account__list">
                @foreach ($items as $item)
                    <li class="sugar-account__order" data-test="gift-account-item">
                        <p class="sugar-account__order-head">
                            <a href="/p/gift/orders/{{ $item['orderId'] }}">ご注文番号 {{ $item['number'] }}</a>
                            <span class="sugar-page__note">{{ $item['placedAt'] }}</span>
                        </p>
                        <p class="sugar-page__note">{{ $item['statusLabel'] }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="sugar-account__foot"><a href="/account">マイページに戻る</a></p>
    </div>
@endsection
