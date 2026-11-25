{{-- 合計と税率ごとの内訳（インボイスの記載事項: 税率ごとに区分した対価の額と消費税額）。$sheet（OrderSheet） --}}
<table class="totals" data-test="totals">
    <tr><th>小計</th><td class="num">{{ $sheet->subtotalLabel }}</td></tr>
    @foreach ($sheet->adjustments as $line)
        <tr><th>{{ $line['label'] }}</th><td class="num">{{ $line['amount'] }}</td></tr>
    @endforeach
    <tr class="grand"><th>合計（税込み）</th><td class="num" data-test="total">{{ $sheet->totalLabel }}</td></tr>
    @foreach ($sheet->taxes as $tax)
        <tr data-test="tax-row"><th>{{ $tax['rate'] }}@if ($tax['reduced'])※@endif 対象</th><td class="num">{{ $tax['base'] }}（うち消費税 {{ $tax['tax'] }}）</td></tr>
    @endforeach
</table>
@if ($sheet->hasReduced)
    <p class="note">※ は軽減税率（8%）の対象です。</p>
@endif
