{{--
    マイページの「お知らせのメール」（会員だけ）。受け取る・止めるの切り替え（ログインのメールへ届く。確かめのメールは要らない）。
    $email（会員のメール）・$active（届けているか）・$message（設定の一言。無ければ null）
--}}
@extends($theme->view('layout'))

@section('title', 'お知らせのメール — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="newsletter-account">
        <h1 class="sugar-page__title">お知らせのメール</h1>
        <p class="sugar-page__lead">{{ $message ?? $shop->name.' から、新しい商品やセールなどのお知らせをメールでお送りします。' }}</p>
        @if ($flash['message'])
            <p role="status" class="sugar-page__note" data-test="newsletter-message">{{ $flash['message'] }}</p>
        @endif
        @if ($flash['error'])
            <p role="alert" class="sugar-account__error" data-test="newsletter-error">{{ $flash['error'] }}</p>
        @endif

        <div class="sugar-account__section">
            <p class="sugar-page__note" data-test="newsletter-state">
                @if ($active)
                    いま、お知らせのメールを {{ $email }} へお送りしています。
                @else
                    いまは、お知らせのメールをお送りしていません。
                @endif
            </p>
            <form method="post" action="/p/newsletter/account" class="sugar-account__form">
                @csrf
                <input type="hidden" name="action" value="{{ $active ? 'unsubscribe' : 'subscribe' }}">
                <button type="submit" class="{{ $active ? 'sugar-account__secondary' : 'sugar-account__primary' }}" data-test="newsletter-toggle">{{ $active ? '配信を停止する' : 'お知らせのメールを受け取る' }}</button>
            </form>
        </div>
        <p class="sugar-account__foot"><a href="/account">マイページに戻る</a></p>
    </div>
@endsection
