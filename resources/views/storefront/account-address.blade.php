{{--
    会員の届け先を直す（ADR 0044 の 3）。核が描く
--}}
@extends($theme->view('layout'))

@section('title', 'お届け先の編集 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="account-address">
        <p class="sugar-account__back"><a href="{{ route('storefront.account.addresses') }}">← お届け先</a></p>
        <h1 class="sugar-page__title">お届け先の編集</h1>
        <section class="sugar-account__section">
            <form method="post" action="{{ route('storefront.account.address.update', ['addressId' => $address['id']]) }}" class="sugar-account__form" data-test="address-form">
                @csrf
                <x-storefront::address-fields :form="$form" />
                <button type="submit" class="sugar-account__primary" data-test="address-save">保存する</button>
            </form>
        </section>
    </div>
@endsection
