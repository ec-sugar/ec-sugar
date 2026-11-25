{{--
    会員の登録した届け先から選ぶ（ADR 0044 の 3）。核の部品で、テーマの注文の入力の画面が置く。
    会員でなければ何も出さない。選ぶとお届け先に入る（?ship_to=）。出すのは会員自身が入れた届け先だけ
--}}
@props(['form'])
@if ($form->addresses() !== [])
    <nav class="sugar-saved-addresses" aria-label="登録したお届け先" data-test="saved-addresses">
        <p class="sugar-saved-addresses__title">登録したお届け先から選ぶ</p>
        <ul class="sugar-saved-addresses__list">
            @foreach ($form->addresses() as $address)
                <li>
                    <a href="{{ $address['url'] }}" class="sugar-saved-addresses__item" @if ($address['selected']) aria-current="true" @endif data-test="saved-address">{{ $address['label'] }}</a>
                </li>
            @endforeach
        </ul>
        <a href="{{ route('storefront.account.addresses') }}" class="sugar-saved-addresses__manage">お届け先の追加・編集</a>
    </nav>
@endif
