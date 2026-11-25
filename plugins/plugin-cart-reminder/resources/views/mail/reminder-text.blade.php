{{--
    カゴ落ちのお知らせ（文の形。text/plain なので HTML として読まれない。& や < をそのまま見せるため、CartReminderMail が値を HtmlString で渡す）。
    終わりに特定電子メール法の表示
--}}
{{ $text['opening'] }}

@foreach ($text['titles'] as $title)
・{{ $title }}
@endforeach

カートに戻す: {{ $text['cartUrl'] }}

価格と在庫は、カートに戻した時点のものになります。売り切れや販売を終了した商品は戻りません。リンクの有効期限は {{ $days }} 日です。

――――――――――――――――――――
このメールは、{{ $text['shopName'] }} のお知らせのメールに登録された方にお送りしています。
配信の停止: 下のリンクを開いて「配信を停止する」を押すと、すぐに停止します。
{{ $text['unsubscribe'] }}

送り主: {{ $text['senderName'] }}
住所: {{ $text['senderAddress'] }}
@foreach ($text['contacts'] as $contact)
{{ $contact }}
@endforeach
