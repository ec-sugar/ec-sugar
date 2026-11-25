{{--
    カゴ落ちのお知らせ（HTML の形。ADR 0067）。値はすべてエスケープして出す。値段・数・注文番号・名前・住所は載せない。
    App の画面の決まり（ThemeTest）に合わせて style を使わず、表の属性だけで並べる（メールのソフトは外の CSS を読まないので、見た目は素朴）。
    終わりに特定電子メール法の表示: 受け取ると選んだ人へ送っていること・配信の停止のリンク・送り主の名前・住所・問い合わせ先
--}}
<!DOCTYPE html>
<html lang="ja">
<head><meta charset="utf-8"><title>{{ $shopName }}</title></head>
<body>
<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
    <tr>
        <td align="center">
            <table width="560" cellpadding="16" cellspacing="0" border="0" role="presentation">
                <tr><td>
                    <p><small>{{ $shopName }}</small></p>
                    <h1>お買い物の続きのご案内</h1>
                    <p>{{ $opening }}</p>
                    <table width="100%" cellpadding="8" cellspacing="0" border="0" role="presentation">
                        @foreach ($items as $item)
                            <tr>
                                <td width="88" valign="middle">
                                    @if ($item['photo'])
                                        <img src="{{ $item['photo'] }}" alt="{{ $item['title'] }}" width="80">
                                    @endif
                                </td>
                                <td valign="middle">{{ $item['title'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                    <p><strong><a href="{{ $cartUrl }}">カートに戻す</a></strong></p>
                    <p><small>価格と在庫は、カートに戻した時点のものになります。売り切れや販売を終了した商品は戻りません。リンクの有効期限は {{ $days }} 日です。</small></p>
                    <hr>
                    <p><small>このメールは、{{ $shopName }} のお知らせのメールに登録された方にお送りしています。</small></p>
                    <p><small>配信の停止: <a href="{{ $unsubscribe }}">こちらから停止できます</a></small></p>
                    <p><small>送り主: {{ $senderName }}<br>住所: {{ $senderAddress }}@foreach ($contacts as $contact)<br>{{ $contact }}@endforeach</small></p>
                </td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
