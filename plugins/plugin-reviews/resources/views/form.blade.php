{{--
    レビューを書く（App「商品レビュー」）。星・題・本文・表示名。JavaScript を使わない（星はラジオボタン）。
    フォームの送信先（$action）は、ゲストなら署名と期限つきのパス。名前やメールアドレスを書かないよう案内する
--}}
@extends($theme->view('layout'))

@section('title', 'レビューを書く — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="reviews-form">
        <p class="sugar-account__back"><a href="{{ $back }}">← 戻る</a></p>
        <h1 class="sugar-page__title">レビューを書く</h1>
        <p class="sugar-page__lead" data-test="reviews-listing">{{ $listingTitle }}</p>
        @if ($errors->any())
            <p role="alert" class="sugar-account__error" data-test="reviews-error">{{ $errors->first() }}</p>
        @endif

        <form method="post" action="{{ $action }}" class="sugar-account__section sugar-account__form">
            @csrf
            <fieldset class="sugar-account__field">
                <legend class="sugar-account__label">星（5 段階）</legend>
                @for ($n = 5; $n >= 1; $n--)
                    <label class="sugar-page__choice" data-test="reviews-rating">
                        <input type="radio" name="rating" value="{{ $n }}" @checked((string) old('rating') === (string) $n) required>
                        <span><span aria-hidden="true">{{ str_repeat('★', $n) }}{{ str_repeat('☆', 5 - $n) }}</span> {{ $n }}</span>
                    </label>
                @endfor
            </fieldset>
            <div class="sugar-account__field">
                <label class="sugar-account__label" for="reviews-title">タイトル</label>
                <input id="reviews-title" name="title" type="text" class="sugar-account__input" maxlength="{{ $limits['title'] }}" value="{{ old('title') }}" required data-test="reviews-title">
            </div>
            <div class="sugar-account__field">
                <label class="sugar-account__label" for="reviews-body">本文</label>
                <textarea id="reviews-body" name="body" rows="6" class="sugar-account__input" maxlength="{{ $limits['body'] }}" required data-test="reviews-body">{{ old('body') }}</textarea>
                <p class="sugar-page__note">{{ $limits['body'] }} 文字まで。お名前・メールアドレス・お届け先は書かないでください。</p>
            </div>
            <div class="sugar-account__field">
                <label class="sugar-account__label" for="reviews-name">表示名</label>
                <input id="reviews-name" name="display_name" type="text" class="sugar-account__input" maxlength="{{ $limits['name'] }}" value="{{ old('display_name', $defaultName) }}" data-test="reviews-name">
                <p class="sugar-page__note">レビューといっしょに表示される名前です（{{ $limits['name'] }} 文字まで。空なら「{{ $defaultName }}」）。</p>
            </div>
            <p class="sugar-page__note" data-test="reviews-publish-note">{{ $publishesAtOnce ? '投稿すると、すぐに商品ページに掲載されます。' : 'ショップが内容を確認してから、商品ページに掲載されます。' }}</p>
            <button type="submit" class="sugar-account__primary" data-test="reviews-submit">投稿する</button>
        </form>
    </div>
@endsection
