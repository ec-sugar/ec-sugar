{{--
    配信のメール（文だけ。text/plain なので、店の人が書いた文は HTML として読まれない。& や < をそのまま見せるため、NewsletterMail が文を HtmlString で渡す）。
    終わりに特定電子メール法の表示: 受け取ると選んだ人へ送っていること・配信の停止のリンク（止められること）・送り主の名前・住所・問い合わせ先
--}}
@if ($test)
（これはテスト送信のメールです。下の配信の停止のリンクは使えません）

@endif
{{ $body }}

――――――――――――――――――――
このメールは、{{ $shopName }} のお知らせのメールに登録された方にお送りしています。
配信の停止: 下のリンクを開いて「配信を停止する」を押すと、すぐに停止します。
{{ $unsubscribe }}

送り主: {{ $senderName }}
住所: {{ $senderAddress }}
@foreach ($contacts as $contact)
{{ $contact }}
@endforeach
