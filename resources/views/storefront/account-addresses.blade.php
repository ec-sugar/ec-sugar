{{--
    会員の届け先（ADR 0044 の 3）。核が描く。会員として入れた届け先だけ（ゲストの時の住所は出さない）。
    いつもの届け先は注文の入力に最初から入る。テーマの外枠の中に描き、App のスクリプトは差し込まない
--}}
@extends($theme->view('layout'))

@section('title', 'お届け先 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="account-addresses">
        <p class="sugar-account__back"><a href="{{ route('storefront.account') }}">← マイページ</a></p>
        <h1 class="sugar-page__title">お届け先</h1>
        @if ($flash['message'])
            <p role="status" class="sugar-account__notice" data-test="account-message">{{ $flash['message'] }}</p>
        @endif
        @if ($flash['error'])
            <p role="alert" class="sugar-account__error" data-test="account-error">{{ $flash['error'] }}</p>
        @endif

        <section class="sugar-account__section" aria-label="登録したお届け先">
            @if ($addresses === [])
                <p class="sugar-page__empty" data-test="addresses-empty">お届け先はまだ登録されていません。登録すると、ご注文の時に入力の手間が省けます。</p>
            @else
                <ul class="sugar-account__list">
                    @foreach ($addresses as $address)
                        <li class="sugar-account__order" data-test="address-item">
                            <p class="sugar-account__order-head"><strong>{{ $address['name'] }}</strong>@if ($address['default'])<span class="sugar-account__status" data-test="address-default">いつものお届け先</span>@endif</p>
                            <p class="sugar-page__note">{{ $address['summary'] }}</p>
                            <div class="sugar-account__actions">
                                <a href="{{ route('storefront.account.address', ['addressId' => $address['id']]) }}" class="sugar-account__secondary" data-test="address-edit">編集</a>
                                @unless ($address['default'])
                                    <form method="post" action="{{ route('storefront.account.address.default', ['addressId' => $address['id']]) }}">
                                        @csrf
                                        <button type="submit" class="sugar-account__secondary" data-test="address-make-default">いつものお届け先にする</button>
                                    </form>
                                @endunless
                                <form method="post" action="{{ route('storefront.account.address.delete', ['addressId' => $address['id']]) }}">
                                    @csrf
                                    <button type="submit" class="sugar-account__secondary" data-test="address-delete">削除</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if (count($addresses) < $limit)
            <section class="sugar-account__section" aria-labelledby="address-add">
                <h2 id="address-add" class="sugar-page__heading">お届け先の追加</h2>
                <form method="post" action="{{ route('storefront.account.addresses.store') }}" class="sugar-account__form" data-test="address-form">
                    @csrf
                    <x-storefront::address-fields :form="$form" />
                    <button type="submit" class="sugar-account__primary" data-test="address-save">追加</button>
                </form>
            </section>
        @endif
    </div>
@endsection
