{{--
    納品書（店の人だけ。何件かまとめると 1 枚ずつ続けて、印刷では 1 件ごとに改ページ）。
    $sheets（list<OrderSheet>。届け先つき）・$issuer・$logoUrl・$issuedOn
--}}
@extends('print.layout')

@section('toolbar')
    <p><strong>納品書{{ count($sheets) > 1 ? '（'.count($sheets).' 件）' : '' }}</strong></p>
@endsection

@section('content')
    @foreach ($sheets as $sheet)
        <section class="sheet" data-test="delivery-note">
            <div class="doc-head">
                <h1 class="doc-title">納品書</h1>
                <div class="doc-meta">
                    <div>ご注文番号 <span data-test="order-number">{{ $sheet->number }}</span></div>
                    <div>ご注文日 {{ $sheet->orderedOn }}</div>
                    <div>発行日 {{ $issuedOn }}</div>
                </div>
            </div>
            <div class="parties">
                <div data-test="ship-to">
                    @forelse ($sheet->shipTo as $to)
                        <p class="to">{{ $to['name'] }} 様</p>
                        <p>{{ $to['postal'] }}<br>{{ $to['address'] }}</p>
                    @empty
                        <p class="to">{{ $sheet->customerName }} 様</p>
                    @endforelse
                    <p>このたびはお買い上げいただき、ありがとうございます。下記のとおり納品いたします。</p>
                </div>
                @include('order-print::partials.issuer')
            </div>
            @include('order-print::partials.lines')
            @include('order-print::partials.totals')
            @if ($sheet->remark)
                <p class="note" data-test="delivery-note-remark">備考: {{ $sheet->remark }}</p>
            @endif
            {{-- 備考: App が注文に足した料金の内容（ラッピング・のしと名入れ。ADR 0078）。文字のまま出す --}}
            @if ($sheet->notes !== [])
                <p class="note" data-test="order-notes">備考: {{ $sheet->notesLine() }}</p>
            @endif
        </section>
    @endforeach
@endsection
