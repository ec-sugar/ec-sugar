{{--
    銀行振込の振込先・代金引換の案内（核の部品。ADR 0074）。注文を受け付けた画面（テーマの checkout-complete）と、ご注文の状況の支払いの様子（payment-status）が使う。
    使い方（テーマ）: <x-storefront::direct-payment :payment="$receipt->payment" />
    - $payment: PaymentState（null・払われた・取り消した注文では何も描かない）
    - 銀行振込の入金待ち: 払う額・期限・振込先（注文の時の写し）。期限までに振込が無ければ注文を取り消す
    - 代金引換で払う前: 代金は届けた時に配達員へ（代引き手数料を含む合計）
    - 予約の当日払いで払う前: 代金はご予約の当日にお店で
    見た目は核の CSS（resources/storefront/apps.css の [data-direct-payment]）が付け、テーマは同じ印で上書きできる
--}}
@props(['payment'])
@if ($payment !== null && $payment->awaitingTransfer())
    <section {{ $attributes->merge(['data-direct-payment' => 'bank_transfer', 'data-test' => 'direct-payment', 'aria-label' => 'お振込み先']) }}>
        <p data-test="bank-transfer-due">銀行振込でのお支払いをお待ちしています。下の口座へお振込みください（期限までにお振込みがないときは、ご注文を取り消します）。ご入金を確認してから発送します。</p>
        <dl data-test="bank-transfer-terms">
            @if ($payment->amountLabel())
                <div><dt>お振込み金額</dt><dd class="tabular" data-test="bank-transfer-amount">{{ $payment->amountLabel() }}</dd></div>
            @endif
            @if ($payment->payByLabel())
                <div><dt>お振込み期限</dt><dd class="tabular" data-test="bank-transfer-pay-by">{{ $payment->payByLabel() }}</dd></div>
            @endif
        </dl>
        <dl data-test="bank-account">
            @foreach ($payment->bankLines() as $line)
                <div><dt>{{ $line['label'] }}</dt><dd>{{ $line['value'] }}</dd></div>
            @endforeach
        </dl>
        <p class="sugar-direct-payment__note">振込手数料はお客さまのご負担です。振込依頼人名は、ご注文者のお名前でお願いします。</p>
        @if (\App\Core\Sample\Demo\DemoShop::active())
            <p class="sugar-direct-payment__note" data-test="demo-direct-note">デモのお店です。お振込みはしないでください（振込先は見本です）。デモでは入金の確認は行わず、管理画面の操作で次の状態へ進めます。</p>
        @endif
    </section>
@elseif ($payment !== null && $payment->payOnSite())
    <section {{ $attributes->merge(['data-direct-payment' => 'on_site', 'data-test' => 'direct-payment', 'aria-label' => '当日払い']) }}>
        <p data-test="on-site-due">当日払いで受け付けました。代金（<strong class="tabular">{{ $payment->amountLabel() }}</strong>）は、ご予約の当日にお店でお支払いください。</p>
    </section>
@elseif ($payment !== null && $payment->onDelivery)
    <section {{ $attributes->merge(['data-direct-payment' => 'cod', 'data-test' => 'direct-payment', 'aria-label' => '代金引換']) }}>
        <p data-test="cod-due">代金引換で受け付けました。代金（代引き手数料を含む <strong class="tabular">{{ $payment->amountLabel() }}</strong>）は、商品のお届け時に配達員へお支払いください。</p>
        @if (\App\Core\Sample\Demo\DemoShop::active())
            <p class="sugar-direct-payment__note" data-test="demo-direct-note">デモのお店です。商品は届かず、代金も請求されません。デモでは入金の確認は行わず、管理画面の操作で次の状態へ進めます。</p>
        @endif
    </section>
@endif
