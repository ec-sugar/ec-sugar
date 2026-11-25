{{--
    メールのリンクの画面（登録を確かめる・取り消す）。開いただけでは何も変えず、ボタン（POST）で変える。
    $listing（使えるリンクなら商品、使えなければ null）・$variant（種類の名前）・$action（confirm | cancel | null）・$message・$path（ボタンの送信先）・$cancelPath（取り消しの画面。無ければ null）
--}}
@extends($theme->view('layout'))

@section('title', '入荷のお知らせ — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="restock-token">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">入荷のお知らせ</h1>
                @if ($listing !== null)
                    <p class="sugar-page__note" data-test="restock-token-item">{{ $listing->title }}@if ($variant !== '')（{{ $variant }}）@endif</p>
                @endif
                <p class="sugar-page__lead" data-test="restock-token-message">{{ $message }}</p>
            </div>
            @if ($action !== null)
                <form method="post" action="{{ $path }}">
                    @csrf
                    <button type="submit" class="sugar-account__primary" data-test="restock-{{ $action }}">{{ $action === 'confirm' ? '登録を確定する' : '登録を取り消す' }}</button>
                </form>
            @endif
            @if ($cancelPath !== null)
                <a href="{{ $cancelPath }}" class="sugar-account__secondary" data-test="restock-cancel-link">登録を取り消す</a>
            @endif
            @if ($listing !== null)
                <a href="{{ route('storefront.product', ['listingId' => $listing->id]) }}" class="sugar-account__secondary">商品ページへ</a>
            @endif
        </div>
    </div>
@endsection
