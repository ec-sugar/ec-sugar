{{ $shopName }} の贈り物のお知らせです。

ご注文番号: {{ $facts['number'] }}
@foreach ($facts['items'] as $item)
{{ $item }}
@endforeach

@switch($kind)
@case('claimed')
お相手が、贈り物のお届け先を入力しました。お店が発送の準備をしています。
お届け先は、お相手のためにこのメールには載せていません。
@break
@case('expiring')
お相手が、まだお届け先を入力していません。受け取りの期限は {{ $facts['expiresOn'] }} です。
期限までにお届け先の入力がないときは、ご注文を取り消して全額を返金します。
@if ($facts['link'])
受け取りのリンク（もう一度お相手へ送れます）: {{ $facts['link'] }}
@endif
@break
@case('expired')
受け取りの期限（{{ $facts['expiresOn'] }}）までにお届け先の入力がなかったため、ご注文を取り消して全額の返金の手続きをしました。
返金がカードの明細に出るまで、数日かかることがあります。
@break
@case('cancelled')
贈り物のご注文を取り消し、全額の返金の手続きをしました。受け取りのリンクは、もう使えません。
返金がカードの明細に出るまで、数日かかることがあります。
お心当たりが無い場合は、お店へお問い合わせください。
@break
@endswitch

@if ($facts['manage'])
贈り物の状況: {{ $facts['manage'] }}
@endif

このメールは送信専用です。
