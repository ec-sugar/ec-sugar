{{--
    支払いの様子（核の部品。ADR 0058）。ご注文の確認（テーマの order の画面）に置く。
    使い方（テーマ）:
        <x-storefront::payment-status :order="$order" />
    - $order: OrderView。支払いの記録が無い注文（0 円・まだ支払いの画面に進んでいない）と取り消した注文は、何も描かない
    - コンビニ払いの入金待ち: 払いの期限と、払込票の画面（Stripe。お支払い番号・確認番号）へのリンク。払込票の画面は rel="noreferrer"（署名つきのご注文の画面の URL を渡さない）
    - 銀行振込の入金待ち・代金引換で払う前（ADR 0074）: 核の部品 <x-storefront::direct-payment> で振込先・期限・額か、届けた時に払う案内
    - 払われた注文: 「お支払いの方法 クレジットカード」など
    - 見た目は核の CSS（resources/storefront/apps.css の [data-payment-status]）が付け、テーマは同じ印で上書きできる
--}}
@props(['order'])
@if ($order->payment !== null && ! $order->cancelled())
    @if ($order->payment->awaitingTransfer() || $order->payment->onDelivery)
        <x-storefront::direct-payment :payment="$order->payment" />
    @else
        <section {{ $attributes->merge(['data-payment-status' => $order->payment->awaiting ? 'awaiting' : 'paid', 'data-test' => 'payment-status', 'aria-label' => 'お支払い']) }}>
            @if ($order->payment->awaiting)
                <p data-test="payment-awaiting">コンビニでのお支払いをお待ちしています。@if ($order->payment->payByLabel())お支払い期限は <span class="tabular">{{ $order->payment->payByLabel() }}</span> です（期限までにお支払いがないときは、ご注文を取り消します）。@endif</p>
                @if ($order->payment->instructionsUrl)
                    <a href="{{ $order->payment->instructionsUrl }}" target="_blank" rel="noopener noreferrer" data-test="payment-instructions">お支払い方法の案内（お支払い番号・確認番号）を見る</a>
                @endif
            @elseif ($order->payment->label)
                <p data-test="payment-method">お支払い方法　{{ $order->payment->label }}</p>
            @endif
        </section>
    @endif
@endif
