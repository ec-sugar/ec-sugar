{{ $shopName }} から、ご予約の商品のお知らせです。

ご注文番号: {{ $facts['number'] }}
@foreach ($facts['items'] as $item)
{{ $item }}
@endforeach

@if ($facts['listing'] !== '')
ご予約の「{{ $facts['listing'] }}」の発売日が {{ $facts['from'] }} から {{ $facts['to'] }} に変わりました。
@else
ご予約の商品の発売日が {{ $facts['from'] }} から {{ $facts['to'] }} に変わりました。
@endif
ご注文の品は {{ $facts['shipsOn'] }}ごろにまとめてお送りします。

@if ($facts['later'])
お待たせしてしまい申し訳ありません。
@endif
ご注文の取り消しをご希望のときは、お手数ですがお店へご連絡ください。

このメールは送信専用です。
