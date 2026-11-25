@if ($kind === 'confirm' && $facts['message'])
{{ $facts['message'] }}

@endif
{{ $shopName }} の入荷のお知らせです。

商品: {{ $facts['title'] }}@if ($facts['variant'] !== '')（{{ $facts['variant'] }}）@endif

@switch($kind)
@case('confirm')
この商品の入荷のお知らせの登録を受け付けました。下のリンクを開き、「登録を確定する」を押すと登録が完了します。
{{ $facts['confirm'] }}

{{ $facts['hours'] }} 時間以内に登録を確定しないと、登録は取り消されます。お心当たりが無い場合は、このメールを破棄してください（何も届きません）。
@break
@case('registered')
この商品が入荷したら、このメールアドレスへ 1 回だけお知らせします。
@break
@case('restocked')
お待たせしました。この商品が入荷しました。数に限りがあるため、ご注文の時に売り切れていることがあります（お取り置きはしていません）。
{{ $facts['product'] }}

お知らせはこの 1 回だけで、登録はこれで終了しました。
@break
@endswitch
@if ($kind !== 'restocked' && $facts['cancel'])

登録を取り消すとき: {{ $facts['cancel'] }}
@endif

このメールは、商品ページで「入荷したら知らせる」に登録された方にだけお送りしています（宣伝のメールではありません）。
