{{ $shopName }}

@if ($kind === 'order_paid')
ご注文ありがとうございます。
@if ($order->free)
ご注文を受け付けました（お支払いは不要です）。発送の準備ができしだい、あらためてお知らせします。
@elseif ($order->payment?->payOnSite())
ご予約を当日払いで受け付けました。代金（{{ $order->totalLabel }}）は、ご予約の当日にお店でお支払いください。
@elseif ($order->inPerson)
お支払いを受け付け、ご予約が確定しました。ご予約の日時にお待ちしています。
@elseif ($order->payment?->onDelivery)
ご注文を代金引換で受け付けました。代金（代引き手数料を含む {{ $order->totalLabel }}）は、商品のお届け時に配達員へお支払いください。発送の準備ができしだい、あらためてお知らせします。
@else
お支払いを受け付けました。発送の準備ができしだい、あらためてお知らせします。
@endif
@if ($order->gift)

■ 贈り物（お届け先は受け取る方が入力します）
@if ($order->gift->url)
下のリンクを、贈りたい方へ送ってください（メール・LINE など）。リンクを開いた方がお届け先を入力すると、商品をお届けします。受け取る方に金額は表示されません。
受け取りのリンク: {{ $order->gift->url }}
@if ($order->gift->expiresOn)
受け取りの期限: {{ $order->gift->expiresOn }}（期限までにお届け先の入力がないときは、ご注文を取り消して全額を返金します）
@endif
このリンクを知っている方は、どなたでも受け取れます。別の方に送ってしまったときは、リンクを作り直してください（前のリンクは使えなくなります）。
@else
{{ $order->gift->statusLabel }}
@endif
@if ($order->gift->manageUrl)
リンクの作り直し・贈り物の取り消し: {{ $order->gift->manageUrl }}
@endif
@endif
@elseif ($kind === 'order_awaiting_payment' && $order->payment?->awaitingTransfer())
ご注文ありがとうございます。
銀行振込でのお支払いをお待ちしています。下の口座へお振込みください。ご入金を確認しだい、発送の準備に入ります。

お振込み金額: {{ $order->payment->amountLabel() }}
@if ($order->payment->payByLabel())
お振込み期限: {{ $order->payment->payByLabel() }}（期限までにお振込みがないときは、ご注文を取り消します）
@endif
@foreach ($order->payment->bankLines() as $line)
{{ $line['label'] }}: {{ $line['value'] }}
@endforeach
振込手数料はお客さまのご負担です。振込依頼人名は、ご注文者のお名前でお願いします。
@elseif ($kind === 'order_awaiting_payment')
ご注文ありがとうございます。
コンビニでのお支払いをお待ちしています。お支払いが確認できしだい、発送の準備に入ります。
@if ($order->payment?->payByLabel())
お支払い期限: {{ $order->payment->payByLabel() }}（期限までにお支払いがないときは、ご注文を取り消します）
@endif
@if ($order->payment?->instructionsUrl)
お支払い方法の案内（お支払い番号・確認番号）: {{ $order->payment->instructionsUrl }}
@else
お支払い番号・確認番号は、お支払いの画面に表示された案内でご確認ください。
@endif
@elseif ($kind === 'order_refunded')
ご注文の代金のうち {{ $refund['amountLabel'] ?? '' }} を返金しました。
@if ($refund['card'] ?? false)
お支払いに使われたカードなどへ返金します。ご利用の明細に反映されるまで、カード会社の手続きにより数日（長いときは 1 か月ほど）かかることがあります。
@else
返金の受け取り方法は、ショップからのご案内をご確認ください。
@endif
@elseif ($kind === 'order_payment_lapsed')
お支払いの期限までに{{ $order->bankTransfer ? 'お振込み' : 'コンビニでのお支払い' }}が確認できなかったため、ご注文を取り消しました。
お手数ですが、もう一度ご注文ください。
@else
ご注文の商品を発送しました。
@foreach ($order->shipments as $shipment)
{{ $shipment['shippedAt'] }} 発送{{ $shipment['carrier'] ? '（'.$shipment['carrier'].'）' : '' }}{{ $shipment['trackingNumber'] ? '　お問い合わせ番号 '.$shipment['trackingNumber'] : '' }}
@endforeach
@endif

ご注文番号: {{ $order->number() }}
ご注文日: {{ $order->placedAt }}
@if ($order->payment?->label)
お支払い方法: {{ $order->payment->label }}
@endif
@if ($order->deliveryWish)
お届け希望日時: {{ $order->deliveryWish }}
@endif
@if ($order->preorder)
発送予定: {{ $order->preorder }}
@if ($order->preorderOrderedOn)
（ご予約の商品の発売日が変更になりました。ご注文時の発売予定日は {{ $order->preorderOrderedOn }} でした）
@endif
@endif
@if (in_array($kind, ['order_paid', 'order_awaiting_payment'], true))
@foreach ($order->fields as $field)
{{ $field['name'] }}: {{ $field['value'] }}
@endforeach
@if ($order->fieldsWithheld)
ご注文時にご記入いただいた内容は、ショップで受け付けました。
@endif
@endif

@if ($kind === 'order_shipped' && $order->hidePrices)
{{-- 贈り物の発送の知らせは、受け取る人の目に触れうるので値段を出さない（ADR 0055） --}}
@foreach ($order->items as $item)
{{ $item['title'] }} × {{ $item['quantity'] }}
@endforeach
@else
@foreach ($order->items as $item)
{{ $item['title'] }}{{ ($item['reduced'] ?? false) ? ' ※' : '' }} × {{ $item['quantity'] }}　{{ $item['amountLabel'] }}
@endforeach
@foreach ($order->discounts as $discount)
{{ $discount['title'] }}　{{ $discount['amountLabel'] }}
@endforeach
@if ($order->shippingLabel)
送料　{{ $order->shippingLabel }}
@endif
@foreach ($order->charges as $charge)
{{ $charge['title'] }}　{{ $charge['amountLabel'] }}
@endforeach
お支払い合計（税込）　{{ $order->totalLabel }}
@foreach ($order->taxes?->rows ?? [] as $tax)
（{{ $tax['rateLabel'] }}{{ $tax['reduced'] ? '※' : '' }} 対象 {{ $tax['amountLabel'] }}　うち消費税 {{ $tax['taxLabel'] }}）
@endforeach
@if ($order->taxes?->hasReduced())
※ は軽減税率（8%）の対象です。
@endif
@if ($order->points?->note() !== null)
{{ $order->points->note() }}。
@endif
@if ($order->giftCard !== null)
{{ $order->giftCard->note() }}。
@endif
@endif

ご注文の状況の確認（このリンクは {{ \App\Storefront\Orders\OrderReader::LINK_DAYS }} 日間有効です）:
{{ $link }}

このメールは送信専用です。お心当たりのない場合は、お手数ですがこのメールを削除してください。
