{{--
    お気に入りの商品のお知らせ（文の形。text/plain なので HTML として読まれない。& や < をそのまま見せるため、FavoriteNoticeMail が値を HtmlString で渡す）。
    終わりに特定電子メール法の表示
--}}
お気に入りに入れている商品に、お知らせがあります。

@foreach ($text['items'] as $item)
・{{ $item['title'] }}
  {{ $item['line'] }}
  {{ $item['url'] }}

@endforeach
価格と在庫は、ご注文の時点のものになります。売り切れになる場合もあります。
お気に入りの一覧: {{ $text['listUrl'] }}

――――――――――――――――――――
このメールは、{{ $text['shopName'] }} のお気に入りの値下げ・再入荷のお知らせを希望された方にお送りしています。
配信の停止: 下のリンクを開いて「お知らせを停止する」を押すと、すぐに停止します。
{{ $text['unsubscribe'] }}

送り主: {{ $text['senderName'] }}
住所: {{ $text['senderAddress'] }}
@foreach ($text['contacts'] as $contact)
{{ $contact }}
@endforeach
