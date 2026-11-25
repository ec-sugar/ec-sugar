{{--
    贈り物の受け取りの画面（ADR 0055 の 4）。受け取りのリンクで開く。出すのは差出人の表示名・メッセージ・商品の名前と写真と数だけ
    （値段・買う人のメールと住所は渡していない）。state: form（入れる）・confirm（確かめる）・claimed（受け取り済み）・shipped（発送済み）・
    expired（期限切れ）・cancelled（取り消し）・invalid（使えないリンク）。文はすべてエスケープして出す（メッセージの改行はそのまま）
--}}
@extends($theme->view('layout'))

@section('title', '贈り物が届いています — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="gift-receive" data-gift-state="{{ $state }}">
        <div class="sugar-account__card">
            @if ($state === 'invalid')
                <div class="sugar-account__head">
                    <h1 class="sugar-page__title">このリンクは使えません</h1>
                    <p class="sugar-page__lead">リンクが作り直されたか、URL が途中で切れているかもしれません。贈ってくださった方に、新しいリンクをお問い合わせください。</p>
                </div>
            @else
                <div class="sugar-account__head">
                    <h1 class="sugar-page__title">贈り物が届いています</h1>
                    <p class="sugar-gift-receive__from" data-test="gift-sender">{{ $sender }} さんから</p>
                </div>

                @if ($message !== '')
                    <p class="sugar-gift-receive__message" data-test="gift-message">{{ $message }}</p>
                @endif

                <ul class="sugar-gift-receive__items" aria-label="贈り物の品">
                    @foreach ($items as $item)
                        <li class="sugar-gift-receive__item" data-test="gift-item">
                            @if ($item['photo'])
                                <img src="{{ $item['photo']->url('medium') }}" alt="" width="64" height="64" class="sugar-gift-receive__photo" loading="lazy">
                            @else
                                <span class="sugar-gift-receive__photo" aria-hidden="true"></span>
                            @endif
                            <span>{{ $item['title'] }} × {{ $item['quantity'] }}</span>
                        </li>
                    @endforeach
                </ul>

                @if ($error)
                    <p role="alert" class="sugar-account__error" data-test="gift-error">{{ $error }}</p>
                @endif

                @switch($state)
                    @case('form')
                        <p class="sugar-page__note">{{ $expiresOn }} までに、お届け先を入力してください。入力いただいたお届け先は、お店が発送のためだけに使い、贈ってくださった方にはお知らせしません。</p>
                        <form method="post" action="/p/gift/{{ $token }}" class="sugar-account__form" novalidate data-test="gift-address-form">
                            @csrf
                            <x-storefront::address-fields :form="$form" />
                            <button type="submit" class="sugar-account__primary" data-test="gift-address-submit">入力の確認へ</button>
                        </form>
                        @break
                    @case('confirm')
                        <section class="sugar-account__section" aria-labelledby="gift-confirm-title" data-test="gift-confirm">
                            <h2 id="gift-confirm-title" class="sugar-page__heading">このお届け先でよろしいですか</h2>
                            <p>{{ $address['name'] }} 様</p>
                            <p>{{ $address['line'] }}</p>
                            <p>{{ $address['phone'] }}</p>
                            <p class="sugar-page__note">確定した後は、お届け先を変更できません（変更したいときはお店へお問い合わせください）。</p>
                        </section>
                        <form method="post" action="/p/gift/{{ $token }}/confirm" class="sugar-account__form">
                            @csrf
                            <button type="submit" class="sugar-account__primary" data-test="gift-claim">このお届け先で受け取る</button>
                        </form>
                        <p class="sugar-account__foot"><a href="/p/gift/{{ $token }}" data-test="gift-back">入力し直す</a></p>
                        @break
                    @case('claimed')
                        <p class="sugar-account__notice" data-test="gift-claimed">お届け先を受け付けました。お店が発送の準備をしています。発送したら、このページでお知らせします。</p>
                        @break
                    @case('shipped')
                        <p class="sugar-account__notice" data-test="gift-shipped">発送しました。</p>
                        @foreach ($shipments as $shipment)
                            <p class="sugar-page__note" data-test="gift-shipment">{{ $shipment['shippedAt'] }} 発送{{ $shipment['carrier'] ? '（'.$shipment['carrier'].'）' : '' }}@if ($shipment['trackingNumber'])　お問い合わせ番号 {{ $shipment['trackingNumber'] }}@endif</p>
                        @endforeach
                        @break
                    @case('expired')
                        <p class="sugar-account__notice" data-test="gift-expired">受け取りの期限が過ぎました。この贈り物はお受け取りいただけません。</p>
                        @break
                    @default
                        <p class="sugar-account__notice" data-test="gift-cancelled">この贈り物は取り消されました。</p>
                @endswitch
            @endif
        </div>
    </div>
@endsection
