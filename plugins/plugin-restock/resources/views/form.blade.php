{{--
    入荷したら知らせる（App「再入荷のお知らせ」）。App の画面で、テーマの外枠の中に描く（App のタグは差し込まない）。JavaScript を使わないフォーム。
    $listing（商品）・$variants（売り切れの種類。ID => 名前。軸の無い商品は名前が空）・$memberEmail（ログインした会員のメール。ゲストは null）・$old・$hours
--}}
@extends($theme->view('layout'))

@section('title', '入荷のお知らせ — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="restock-form">
        <p class="sugar-account__back"><a href="{{ route('storefront.product', ['listingId' => $listing->id]) }}">← {{ $listing->title }}</a></p>
        <h1 class="sugar-page__title">入荷のお知らせを受け取る</h1>
        <p class="sugar-page__lead">{{ $listing->title }} が入荷したら、メールで 1 回だけお知らせします。お取り置きはしていないため、ご注文の時に売り切れていることがあります。</p>
        @if ($flash['error'])
            <p role="alert" class="sugar-account__error" data-test="restock-error">{{ $flash['error'] }}</p>
        @endif
        @if ($errors->any())
            <p role="alert" class="sugar-account__error" data-test="restock-error">{{ $errors->first() }}</p>
        @endif

        <form method="post" action="/p/restock/{{ $listing->id }}" class="sugar-account__section sugar-account__form">
            @csrf
            @if (count($variants) === 1)
                {{-- 売り切れの種類が 1 つなら選ばせない --}}
                <input type="hidden" name="variant_id" value="{{ array_key_first($variants) }}">
                @if (reset($variants) !== '')
                    <p class="sugar-page__note" data-test="restock-variant-label">種類: {{ reset($variants) }}</p>
                @endif
            @else
                <fieldset class="sugar-account__field">
                    <legend class="sugar-account__label">売り切れの種類</legend>
                    @foreach ($variants as $id => $label)
                        <label class="sugar-page__choice" data-test="restock-variant">
                            <input type="radio" name="variant_id" value="{{ $id }}" @checked(($old['variant_id'] ?? null) === $id || ($old['variant_id'] === null && $loop->first)) required>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </fieldset>
            @endif
            @if ($memberEmail !== null)
                <p class="sugar-page__note" data-test="restock-member">ログイン中のメールアドレス（{{ $memberEmail }}）へお知らせします。</p>
            @else
                <div class="sugar-account__field">
                    <label class="sugar-account__label" for="restock-email">メールアドレス</label>
                    <input id="restock-email" type="email" name="email" value="{{ $old['email'] }}" maxlength="254" autocomplete="email" required class="sugar-account__input" data-test="restock-email">
                    <p class="sugar-page__note">確認メールをお送りします。{{ $hours }} 時間以内にメールのリンクを開いて登録を確定すると、登録が完了します。</p>
                </div>
            @endif
            <button type="submit" class="sugar-account__primary" data-test="restock-submit">入荷のお知らせを受け取る</button>
        </form>
    </div>
@endsection
