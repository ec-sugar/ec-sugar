{{--
    領収書（店の人とお客さん）。上の欄（印刷に出ない）で宛名を入れて「領収書を出す」を押すと、回数を数えてから下に領収書を描く。
    2 回目からは「再発行」の印。店の人は但し書きも変えられる。
    $staff・$action（フォームの送信先）・$to・$proviso・$error・$issuedBefore（今までに出した回数）・$number（今回が何回目か。出す前は null）・
    $sheet（OrderSheet）・$issuer・$logoUrl・$issuedOn
--}}
@extends('print.layout')

@section('toolbar')
    <p><strong>領収書</strong>（ご注文番号 {{ $sheet->number }}・{{ $sheet->totalLabel }}）</p>
    @if ($error)
        <p class="error" role="alert" data-test="receipt-error">{{ $error }}</p>
    @endif
    @if ($number !== null)
        <p data-test="receipt-issued">{{ $number > 1 ? '領収書を再発行しました（'.$number.' 回目）。' : '領収書を発行しました。' }}宛名を変えるときは、下の欄から発行し直してください（「再発行」の印が付きます）。</p>
    @elseif ($issuedBefore > 0)
        <p data-test="receipt-reissue-note">この注文の領収書は、これまでに {{ $issuedBefore }} 回発行しています。次に発行するものには「再発行」の印が付きます。</p>
    @endif
    <form method="post" action="{{ $action }}" data-test="receipt-form">
        @csrf
        <label class="field">宛名（空欄のままなら、手書き用の空欄で発行します）
            <input type="text" name="to" value="{{ $to }}" maxlength="{{ \EcSugar\PluginOrderPrint\ReceiptInput::MAX }}" autocomplete="off" data-test="receipt-to">
        </label>
        @if ($staff)
            <label class="field">但し書き
                <input type="text" name="proviso" value="{{ $proviso }}" maxlength="{{ \EcSugar\PluginOrderPrint\ReceiptInput::MAX }}" autocomplete="off" required data-test="receipt-proviso">
            </label>
        @endif
        <button type="submit" data-test="receipt-submit">領収書を発行する</button>
    </form>
@endsection

@section('content')
    @if ($number !== null)
        <section class="sheet" data-test="receipt">
            <div class="doc-head">
                <h1 class="doc-title">領収書@if ($number > 1)<span class="stamp" data-test="reissue">再発行</span>@endif</h1>
                <div class="doc-meta">
                    <div>No. <span data-test="order-number">{{ $sheet->number }}</span>@if ($number > 1)-{{ $number }}@endif</div>
                    <div>発行日 {{ $issuedOn }}</div>
                </div>
            </div>
            <div class="parties">
                <div>
                    <p class="to" data-test="receipt-to-name">{{ $to }} 様</p>
                    <p class="amount" data-test="receipt-amount">{{ $sheet->totalLabel }}-<br><small>（税込み）</small></p>
                    <p>但し <span data-test="receipt-proviso-text">{{ $proviso }}</span></p>
                    <p>上記の金額を正に領収いたしました。</p>
                    <p class="note">ご注文日 {{ $sheet->orderedOn }}@if ($sheet->paidOn)・お支払い日 {{ $sheet->paidOn }}@endif</p>
                </div>
                @include('order-print::partials.issuer')
            </div>
            <p class="section-title">内訳</p>
            @include('order-print::partials.lines')
            @include('order-print::partials.totals')
        </section>
    @endif
@endsection
