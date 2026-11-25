{{--
    会員のマイページ（ADR 0044 の 3）。核が描く。注文の履歴は中身（日付・品・金額・状態）と App のリンク（レビューを書く・領収書など）だけ。
    同じメールのゲストの時の注文も並ぶが、届け先・電話・名前・追跡番号は出さない（打ち間違えたメールの持ち主に、他人の届け先を見せない）
--}}
@extends($theme->view('layout'))

@section('title', 'マイページ — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="account-page">
        <h1 class="sugar-page__title">マイページ</h1>
        {{-- つないでいる App のログインの手段（ADR 0070）。心当たりのないつなぎに気づけるよう、見出しのすぐ下に --}}
        @foreach ($linkedLogins ?? [] as $label)
            <p class="sugar-page__note" data-test="account-linked-login">{{ $label }}</p>
        @endforeach
        @if ($flash['message'])
            <p role="status" class="sugar-account__notice" data-test="account-message">{{ $flash['message'] }}</p>
        @endif

        <section class="sugar-account__section" aria-labelledby="account-settings">
            <div class="sugar-account__section-head">
                <h2 id="account-settings" class="sugar-page__heading">会員の情報</h2>
                <span class="sugar-page__note sugar-account__email" data-test="account-email">{{ $email }}</span>
            </div>
            <nav class="sugar-account__links" aria-label="会員の情報">
                <a href="{{ route('storefront.account.addresses') }}" data-test="account-addresses-link">お届け先</a>
                <a href="{{ route('storefront.account.email') }}" data-test="account-email-link">メールアドレスの変更</a>
                <a href="{{ route('storefront.account.passkeys') }}" data-test="account-passkeys-link">パスキー</a>
                @foreach ($appLinks as $link)
                    <a href="{{ $link->path }}" data-test="account-app-link">{{ $link->label }}</a>
                @endforeach
            </nav>
        </section>

        {{-- ポイント（ADR 0068）: 残高・期限・付く予定（払ってから発送まで）・履歴 --}}
        @if ($points !== null)
            <section class="sugar-account__section" aria-labelledby="account-points" data-test="account-points">
                <div class="sugar-account__section-head">
                    <h2 id="account-points" class="sugar-page__heading">ポイント</h2>
                    <span class="sugar-page__note">1 ポイント = 1 円。最後にポイントが付いた日、または使った日から 1 年で失効します</span>
                </div>
                <p class="sugar-account__total">お持ちのポイント <strong data-test="account-points-balance">{{ number_format($points['balance']) }}</strong> ポイント@if ($points['expiresAt'])<span class="sugar-page__note" data-test="account-points-expiry">（有効期限 {{ $points['expiresAt'] }}）</span>@endif</p>
                @if ($points['pending'] !== [])
                    <ul class="sugar-account__lines" data-test="account-points-pending">
                        @foreach ($points['pending'] as $pending)
                            <li><span>{{ $pending['placedAt'] }} ご注文番号 {{ $pending['number'] }}（{{ ($pending['inPerson'] ?? false) ? 'ご来店の後に' : '発送時に' }}付与）</span><span>付与予定 {{ number_format($pending['points']) }}</span></li>
                        @endforeach
                    </ul>
                @endif
                @if ($points['history'] !== [])
                    <ul class="sugar-account__lines" data-test="account-points-history">
                        @foreach ($points['history'] as $entry)
                            <li data-test="account-points-entry"><span>{{ $entry['at'] }} {{ $entry['label'] }}@if ($entry['number'])（ご注文番号 {{ $entry['number'] }}）@endif</span><span class="tabular">{{ $entry['points'] }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <section class="sugar-account__section" aria-labelledby="account-orders">
            <h2 id="account-orders" class="sugar-page__heading">ご注文の履歴</h2>
            @if ($orders === [])
                <p class="sugar-page__empty" data-test="account-orders-empty">まだご注文はありません。</p>
            @else
                <ul class="sugar-account__list" data-test="account-orders">
                    @foreach ($orders as $order)
                        <li class="sugar-account__order" data-test="account-order">
                            <p class="sugar-account__order-head">
                                <span>{{ $order->placedAt }}</span>
                                <span class="sugar-page__note">ご注文番号 {{ $order->number() }}</span>
                                <span class="sugar-account__status" data-test="account-order-status">{{ $order->statusLabel }}</span>
                            </p>
                            <ul class="sugar-account__lines">
                                @foreach ($order->items as $item)
                                    <li><span>{{ $item['title'] }}</span><span>× {{ $item['quantity'] }}</span></li>
                                @endforeach
                            </ul>
                            <p class="sugar-account__total">合計 <strong data-test="account-order-total">{{ $order->totalLabel }}</strong></p>
                            {{-- 予約の品のある注文の発送の予定（ADR 0073） --}}
                            <x-storefront::delivery-summary :label="null" :preorder="$order->preorder" :ordered-on="$order->preorderOrderedOn" />
                            {{-- 注文の項目の選ぶ・チェックの答え（ADR 0066。注文の時の欄の名前で）。文の答えは中身を出さず「お店に届いています」だけ（同じメールのゲストの注文も並ぶため） --}}
                            <x-storefront::order-fields-summary :fields="$order->fields" :withheld="$order->fieldsWithheld" />
                            <x-storefront::order-links :links="$order->links" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <form method="post" action="{{ route('storefront.account.logout') }}" class="sugar-account__actions">
            @csrf
            <button type="submit" class="sugar-account__logout" data-test="account-logout">ログアウト</button>
            <a href="{{ route('storefront.account.leave') }}" class="sugar-account__logout" data-test="account-leave-link">退会</a>
        </form>
    </div>
@endsection
