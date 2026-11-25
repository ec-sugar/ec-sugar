{{--
    マイページのお気に入り（App「お気に入り」。ADR 0071）。App の画面で、テーマの外枠の中に描く（App のタグは差し込まない）。
    $entries（新しい順。card は ショップに出ている商品の札〔ListingCard〕、出ていなければ null で title だけ）・$max・
    $settings（price_drop・restock）・$consented（お知らせのメールの受け取りを選んでいるか）・$consentAvailable（受け取りを選べる App を店が使っているか）。
    札の見た目は核の区画の「商品の並び」と同じ（sections.css の sugar-products）。ハートは核の部品（card-options）
--}}
@extends($theme->view('layout'))

@section('title', 'お気に入り — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="favorites-list">
        <p class="sugar-account__back"><a href="{{ route('storefront.account') }}">← マイページ</a></p>
        <h1 class="sugar-page__title">お気に入り</h1>
        @if ($flash['message'])
            <p role="status" class="sugar-account__notice" data-test="favorites-message">{{ $flash['message'] }}</p>
        @endif
        @if ($flash['error'])
            <p role="alert" class="sugar-account__error" data-test="favorites-error">{{ $flash['error'] }}</p>
        @endif

        @if ($entries === [])
            <p class="sugar-page__lead" data-test="favorites-empty">まだお気に入りがありません。商品の画面や一覧のハートを押すと、ここに並びます。</p>
        @else
            <p class="sugar-page__note">{{ count($entries) }} 件（{{ $max }} 件まで）</p>
            <ul class="sugar-products__grid">
                @foreach ($entries as $entry)
                    <li data-test="favorites-item" data-listing="{{ $entry['id'] }}">
                        @if ($entry['card'])
                            <div class="sugar-card">
                                <a class="sugar-products__card" href="{{ $entry['card']->url() }}">
                                    @if ($entry['card']->photo)
                                        <img src="{{ $entry['card']->photo->url('medium') }}" srcset="{{ $entry['card']->photo->srcset() }}" sizes="(min-width: 768px) 25vw, 50vw"
                                             width="{{ $entry['card']->photo->width }}" height="{{ $entry['card']->photo->height }}" alt="{{ $entry['card']->photo->alt }}" loading="lazy">
                                    @else
                                        <span class="sugar-section__placeholder" aria-hidden="true"></span>
                                    @endif
                                    <span class="sugar-products__title">{{ $entry['card']->title }}</span>
                                    <span class="sugar-products__price">{{ $entry['card']->priceLabel() }}</span>
                                    @if ($entry['card']->availability === 'sold_out')
                                        <span class="sugar-page__note">売り切れ</span>
                                    @endif
                                </a>
                                <x-storefront::card-options :listing="$entry['id']" />
                            </div>
                        @else
                            {{-- 公開を終えた商品: 名前だけ（外せる） --}}
                            <div class="sugar-products__card" data-test="favorites-gone">
                                <span class="sugar-section__placeholder" aria-hidden="true"></span>
                                <span class="sugar-products__title">{{ $entry['title'] !== '' ? $entry['title'] : '商品' }}</span>
                                <span class="sugar-page__note">現在は販売していません</span>
                                <form method="post" action="/p/favorites/{{ $entry['id'] }}">
                                    @csrf
                                    <input type="hidden" name="pressed" value="0">
                                    <input type="hidden" name="back" value="/p/favorites/list">
                                    <button type="submit" class="sugar-account__secondary" data-test="favorites-remove">お気に入りから削除</button>
                                </form>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <form method="post" action="/p/favorites/settings" class="sugar-account__section sugar-account__form" data-test="favorites-settings">
            @csrf
            <h2 class="sugar-page__heading">お知らせ</h2>
            <p class="sugar-page__note">お気に入りの商品が値下げ・再入荷したら、1 日 1 通までメールでお知らせします。</p>
            <label class="sugar-page__choice">
                <input type="checkbox" name="price_drop" value="1" @checked($settings['price_drop']) data-test="favorites-price-drop">
                <span>値下げしたらお知らせを受け取る</span>
            </label>
            <label class="sugar-page__choice">
                <input type="checkbox" name="restock" value="1" @checked($settings['restock']) data-test="favorites-restock">
                <span>売り切れから再入荷したらお知らせを受け取る</span>
            </label>
            @if (! $consentAvailable)
                <p class="sugar-page__note" data-test="favorites-consent-unavailable">このショップでは、いまお知らせのメールを送っていません。</p>
            @elseif (! $consented)
                <p class="sugar-page__note" data-test="favorites-consent-needed">お知らせは、ショップのお知らせのメールに登録している方にだけ届きます（マイページの「お知らせのメール」から登録できます）。</p>
            @endif
            <button type="submit" class="sugar-account__primary" data-test="favorites-settings-submit">お知らせの設定を保存</button>
        </form>
    </div>
@endsection
