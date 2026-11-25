{{-- 明細（商品とオプションの行）。$sheet（OrderSheet）。品名は {{ }} で文字として出す --}}
<table class="lines" data-test="lines">
    <thead>
        <tr><th>品名</th><th class="num">数</th><th class="num">単価</th><th class="num">金額</th></tr>
    </thead>
    <tbody>
        @foreach ($sheet->items as $item)
            <tr data-test="line">
                <td>{{ $item['title'] }}@if ($item['reduced']) ※@endif</td>
                <td class="num">{{ $item['quantity'] }}</td>
                <td class="num">{{ $item['unit'] }}</td>
                <td class="num">{{ $item['amount'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
