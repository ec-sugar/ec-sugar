{{-- お客さんへの注文の知らせ。値はすべてエスケープして出す。住所・電話は載せない --}}
<!DOCTYPE html>
<html lang="ja">
<head><meta charset="utf-8"><title>{{ $shopName }}</title></head>
<body style="margin:0;padding:24px;background:#f7f7f5;color:#1f2328;font-family:-apple-system,'Hiragino Sans','Noto Sans JP',sans-serif;font-size:14px;line-height:1.7">
<div style="max-width:560px;margin:0 auto;background:#ffffff;padding:32px;border-radius:8px">
    <p style="margin:0 0 4px;font-size:13px;color:#6b6f76">{{ $shopName }}</p>
    @if ($kind === 'order_paid')
        <h1 style="margin:0 0 16px;font-size:20px">ご注文ありがとうございます</h1>
        @if ($order->payment?->payOnSite())
            {{-- 予約の当日払い（ADR 0056）: 払う前に予約が確定する。日時・場所・人数は下の「ご注文について」に App が足す --}}
            <p style="margin:0 0 16px">ご予約を当日払いで受け付けました。代金（{{ $order->totalLabel }}）は、ご予約の当日にお店でお支払いください。</p>
        @elseif ($order->inPerson)
            {{-- 来店で受け渡す注文（予約の品）は発送の言葉を使わない --}}
            <p style="margin:0 0 16px">{{ $order->free ? 'ご予約を受け付けました（お支払いは不要です）。' : 'お支払いを受け付け、ご予約が確定しました。' }}ご予約の日時にお待ちしています。</p>
        @elseif ($order->payment?->onDelivery)
            {{-- 代金引換（ADR 0074）: 払う前に発送の準備に入る --}}
            <p style="margin:0 0 16px">ご注文を代金引換で受け付けました。代金（代引き手数料を含む {{ $order->totalLabel }}）は、商品のお届け時に配達員へお支払いください。発送の準備ができしだい、あらためてお知らせします。</p>
        @else
            <p style="margin:0 0 16px">{{ $order->free ? 'ご注文を受け付けました（お支払いは不要です）。' : 'お支払いを受け付けました。' }}発送の準備ができしだい、あらためてお知らせします。</p>
        @endif
        @if ($order->gift)
            {{-- 贈り物（ADR 0055）。受け取りのリンクは買う人へのこのメールと、買う人だけが開ける画面にだけ出す --}}
            <div style="margin:0 0 16px;padding:16px;background:#f7f7f5;border-radius:6px">
                <p style="margin:0 0 8px;font-weight:bold">贈り物（お届け先は受け取る方が入力します）</p>
                @if ($order->gift->url)
                    <p style="margin:0 0 8px">下のリンクを、贈りたい方へ送ってください（メール・LINE など）。リンクを開いた方がお届け先を入力すると、商品をお届けします。受け取る方に金額は表示されません。</p>
                    <p style="margin:0 0 8px;word-break:break-all"><a href="{{ $order->gift->url }}" style="color:#1d4ed8">{{ $order->gift->url }}</a></p>
                    <p style="margin:0 0 8px"><a href="{{ $order->gift->lineUrl() }}" style="color:#1d4ed8">LINE で送る</a></p>
                    @if ($order->gift->expiresOn)
                        <p style="margin:0 0 8px">受け取りの期限: {{ $order->gift->expiresOn }}（期限までにお届け先の入力がないときは、ご注文を取り消して全額を返金します）</p>
                    @endif
                    <p style="margin:0 0 8px;font-size:12px;color:#6b6f76">このリンクを知っている方は、どなたでも受け取れます。別の方に送ってしまったときは、リンクを作り直してください（前のリンクは使えなくなります）。</p>
                @else
                    <p style="margin:0 0 8px">{{ $order->gift->statusLabel }}</p>
                @endif
                @if ($order->gift->manageUrl)
                    <p style="margin:0"><a href="{{ $order->gift->manageUrl }}" style="color:#1d4ed8">リンクの作り直し・贈り物の取り消し</a></p>
                @endif
            </div>
        @endif
    @elseif ($kind === 'order_awaiting_payment' && $order->payment?->awaitingTransfer())
        {{-- 銀行振込の入金待ち（ADR 0074）。振込先は注文の時の写し --}}
        <h1 style="margin:0 0 16px;font-size:20px">ご注文ありがとうございます</h1>
        <p style="margin:0 0 16px">銀行振込でのお支払いをお待ちしています。下の口座へお振込みください。ご入金を確認しだい、発送の準備に入ります。</p>
        <table style="width:100%;border-collapse:collapse;margin:0 0 16px;background:#f7f7f5">
            <tr><td style="padding:4px 12px;color:#6b6f76">お振込み金額</td><td style="padding:4px 12px;text-align:right">{{ $order->payment->amountLabel() }}</td></tr>
            @if ($order->payment->payByLabel())
                <tr><td style="padding:4px 12px;color:#6b6f76">お振込み期限</td><td style="padding:4px 12px;text-align:right">{{ $order->payment->payByLabel() }}</td></tr>
            @endif
            @foreach ($order->payment->bankLines() as $line)
                <tr><td style="padding:4px 12px;color:#6b6f76">{{ $line['label'] }}</td><td style="padding:4px 12px;text-align:right">{{ $line['value'] }}</td></tr>
            @endforeach
        </table>
        <p style="margin:0 0 16px;font-size:12px;color:#6b6f76">期限までにお振込みがないときは、ご注文を取り消します。振込手数料はお客さまのご負担です。振込依頼人名は、ご注文者のお名前でお願いします。</p>
    @elseif ($kind === 'order_awaiting_payment')
        {{-- コンビニ払いの入金待ち（ADR 0058）。払い方そのもの（お支払い番号）は Stripe の払込票の画面 --}}
        <h1 style="margin:0 0 16px;font-size:20px">ご注文ありがとうございます</h1>
        <p style="margin:0 0 16px">コンビニでのお支払いをお待ちしています。お支払いが確認できしだい、発送の準備に入ります。</p>
        @if ($order->payment?->payByLabel())
            <p style="margin:0 0 8px">お支払い期限: {{ $order->payment->payByLabel() }}（期限までにお支払いがないときは、ご注文を取り消します）</p>
        @endif
        @if ($order->payment?->instructionsUrl)
            <p style="margin:0 0 16px"><a href="{{ $order->payment->instructionsUrl }}" style="color:#1d4ed8">お支払い方法の案内（お支払い番号・確認番号）を見る</a></p>
        @else
            <p style="margin:0 0 16px">お支払い番号・確認番号は、お支払いの画面に表示された案内でご確認ください。</p>
        @endif
    @elseif ($kind === 'order_refunded')
        {{-- 返品・一部返金（ADR 0048）。返した額と、カードへの戻りに日数がかかること。明細の住所は載せない --}}
        <h1 style="margin:0 0 16px;font-size:20px">返金しました</h1>
        <p style="margin:0 0 8px">ご注文の代金のうち <strong>{{ $refund['amountLabel'] ?? '' }}</strong> を返金しました。</p>
        @if ($refund['card'] ?? false)
            <p style="margin:0 0 16px">お支払いに使われたカードなどへ返金します。ご利用の明細に反映されるまで、カード会社の手続きにより数日（長いときは 1 か月ほど）かかることがあります。</p>
        @else
            <p style="margin:0 0 16px">返金の受け取り方法は、ショップからのご案内をご確認ください。</p>
        @endif
    @elseif ($kind === 'order_payment_lapsed')
        <h1 style="margin:0 0 16px;font-size:20px">ご注文を取り消しました</h1>
        <p style="margin:0 0 16px">お支払いの期限までに{{ $order->bankTransfer ? 'お振込み' : 'コンビニでのお支払い' }}が確認できなかったため、ご注文を取り消しました。お手数ですが、もう一度ご注文ください。</p>
    @else
        <h1 style="margin:0 0 16px;font-size:20px">ご注文の商品を発送しました</h1>
        @foreach ($order->shipments as $shipment)
            <p style="margin:0 0 8px">{{ $shipment['shippedAt'] }} 発送{{ $shipment['carrier'] ? '（'.$shipment['carrier'].'）' : '' }}@if ($shipment['trackingNumber'])　お問い合わせ番号 {{ $shipment['trackingNumber'] }}@endif</p>
        @endforeach
    @endif
    <table style="width:100%;border-collapse:collapse;margin:16px 0">
        <tr><td style="padding:4px 0;color:#6b6f76">ご注文番号</td><td style="padding:4px 0;text-align:right">{{ $order->number() }}</td></tr>
        <tr><td style="padding:4px 0;color:#6b6f76">ご注文日</td><td style="padding:4px 0;text-align:right">{{ $order->placedAt }}</td></tr>
        @if ($order->payment?->label)
            <tr><td style="padding:4px 0;color:#6b6f76">お支払い方法</td><td style="padding:4px 0;text-align:right">{{ $order->payment->label }}</td></tr>
        @endif
        {{-- お届け日時のご希望（選んだ注文だけ。ADR 0059） --}}
        @if ($order->deliveryWish)
            <tr><td style="padding:4px 0;color:#6b6f76">お届け希望日時</td><td style="padding:4px 0;text-align:right">{{ $order->deliveryWish }}</td></tr>
        @endif
        {{-- 予約の品のある注文の発送の予定（ADR 0073）。発売日が変わっていれば注文の時の日を添える（お支払いの確定の知らせで気づけるように） --}}
        @if ($order->preorder)
            <tr><td style="padding:4px 0;color:#6b6f76">発送予定</td><td style="padding:4px 0;text-align:right">{{ $order->preorder }}@if ($order->preorderOrderedOn)<br>（発売日が変更になりました。ご注文時は {{ $order->preorderOrderedOn }}）@endif</td></tr>
        @endif
        {{-- 注文の項目の選ぶ・チェックの答え（ご注文の確認のメールだけ。注文の時の欄の名前で）。文の答えは載せず一言だけ（宛先を打ち間違えた人・他人宛ての知らせに好きな文を載せない。ADR 0066） --}}
        @if (in_array($kind, ['order_paid', 'order_awaiting_payment'], true))
            @foreach ($order->fields as $field)
                <tr><td style="padding:4px 0;color:#6b6f76;vertical-align:top">{{ $field['name'] }}</td><td style="padding:4px 0;text-align:right">{{ $field['value'] }}</td></tr>
            @endforeach
            @if ($order->fieldsWithheld)
                <tr><td colspan="2" style="padding:4px 0;color:#6b6f76">ご注文時にご記入いただいた内容は、ショップで受け付けました。</td></tr>
            @endif
        @endif
    </table>
    <table style="width:100%;border-collapse:collapse;border-top:1px solid #e5e5e2">
        {{-- 贈り物の発送の知らせは値段を出さない（ADR 0055） --}}
        @foreach ($order->items as $item)
            <tr><td style="padding:8px 0">{{ $item['title'] }}@if ($item['reduced'] ?? false) ※@endif × {{ $item['quantity'] }}</td><td style="padding:8px 0;text-align:right">{{ $kind === 'order_shipped' && $order->hidePrices ? '' : $item['amountLabel'] }}</td></tr>
        @endforeach
        @unless ($kind === 'order_shipped' && $order->hidePrices)
        @foreach ($order->discounts as $discount)
            <tr><td style="padding:8px 0">{{ $discount['title'] }}</td><td style="padding:8px 0;text-align:right">{{ $discount['amountLabel'] }}</td></tr>
        @endforeach
        @if ($order->shippingLabel)
            <tr><td style="padding:8px 0">送料</td><td style="padding:8px 0;text-align:right">{{ $order->shippingLabel }}</td></tr>
        @endif
        {{-- 代引き手数料（ADR 0074） --}}
        @foreach ($order->charges as $charge)
            <tr><td style="padding:8px 0">{{ $charge['title'] }}</td><td style="padding:8px 0;text-align:right">{{ $charge['amountLabel'] }}</td></tr>
        @endforeach
        <tr><td style="padding:8px 0;border-top:1px solid #e5e5e2;font-weight:bold">お支払い合計（税込）</td><td style="padding:8px 0;border-top:1px solid #e5e5e2;text-align:right;font-weight:bold">{{ $order->totalLabel }}</td></tr>
        {{-- 税率ごとの内訳（ADR 0063） --}}
        @foreach ($order->taxes?->rows ?? [] as $tax)
            <tr><td style="padding:2px 0;font-size:12px;color:#6b6f76">{{ $tax['rateLabel'] }}@if ($tax['reduced'])※@endif 対象</td><td style="padding:2px 0;font-size:12px;color:#6b6f76;text-align:right">{{ $tax['amountLabel'] }}（うち消費税 {{ $tax['taxLabel'] }}）</td></tr>
        @endforeach
        @if ($order->taxes?->hasReduced())
            <tr><td colspan="2" style="padding:2px 0;font-size:12px;color:#6b6f76">※ は軽減税率（8%）の対象です。</td></tr>
        @endif
        {{-- ポイント（ADR 0068）: 付く予定・付いた --}}
        @if ($order->points?->note() !== null)
            <tr><td colspan="2" style="padding:8px 0 2px;font-size:13px">{{ $order->points->note() }}。</td></tr>
        @endif
        {{-- ギフトカード（ADR 0076）: 使った額とカードの残り（コードは載せない） --}}
        @if ($order->giftCard !== null)
            <tr><td colspan="2" style="padding:8px 0 2px;font-size:13px">{{ $order->giftCard->note() }}。</td></tr>
        @endif
        @endunless
    </table>
    <p style="margin:24px 0 8px">ご注文の状況は、こちらから確認できます（このリンクは {{ \App\Storefront\Orders\OrderReader::LINK_DAYS }} 日間有効です）。</p>
    <p style="margin:0 0 24px"><a href="{{ $link }}" style="color:#1d4ed8">ご注文の状況を見る</a></p>
    <p style="margin:0;font-size:12px;color:#6b6f76">このメールは送信専用です。お心当たりのない場合は、お手数ですがこのメールを削除してください。</p>
</div>
</body>
</html>
