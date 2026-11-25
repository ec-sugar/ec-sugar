{{--
    税率ごとの内訳（核の部品。ADR 0063）。ご注文の確認の画面（checkout-confirm）とご注文の確認（order）で、合計の下に置く。
    使い方（テーマ）:
        <x-storefront::tax-breakdown :taxes="$order->cart->taxBreakdown()" class="…" />   （確かめの画面）
        <x-storefront::tax-breakdown :taxes="$order->taxes" class="…" />                    （ご注文の確認）
    - $taxes: TaxBreakdown（核。率ごとの rateLabel・reduced・amountLabel・taxLabel）か null。行が無ければ何も描かない
    - 「（8%※ 対象 ¥1,080 うち消費税 ¥80）」の形で 1 率 1 行。軽減税率の率があれば「※ は軽減税率（8%）の対象です。」を添える
    - 見た目は核の CSS（resources/storefront/apps.css の [data-tax-breakdown]）が付け、テーマは同じ印で上書きできる
--}}
@props(['taxes' => null])
@if ($taxes !== null && $taxes->rows !== [])
    <div {{ $attributes->merge(['data-tax-breakdown' => '', 'data-test' => 'tax-breakdown']) }}>
        @foreach ($taxes->rows as $tax)
            <p data-test="tax-row">（{{ $tax['rateLabel'] }}@if ($tax['reduced'])※@endif 対象 <span class="tabular">{{ $tax['amountLabel'] }}</span>　うち消費税 <span class="tabular">{{ $tax['taxLabel'] }}</span>）</p>
        @endforeach
        @if ($taxes->hasReduced())
            <p>※ は軽減税率（8%）の対象です。</p>
        @endif
    </div>
@endif
