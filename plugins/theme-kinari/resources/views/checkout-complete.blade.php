@extends('theme-kinari::layout')

{{-- テーマ API 1: $receipt（OrderReceipt: number()・totalLabel()・paid・returned・cancelled・cancelledForExpiry・free・gift〔贈り物の様子。ADR 0055〕・payment〔銀行振込の振込先・代引きの案内。<x-storefront::direct-payment> に渡す〕・direct()〔銀行振込・代引きで払う前。ADR 0074〕）、$flash（error）。「お支払いへ進む」は route('storefront.checkout.pay') へ POST。個人情報は出さない --}}

@section('title', 'ご注文を受け付けました — '.$shop->name)

@section('content')
    <div class="mx-auto flex max-w-[720px] flex-col items-start gap-6 px-5 pt-16 pb-22 sm:px-8" data-test="order-complete">
        <h1 class="font-mincho text-[28px] font-medium tracking-[0.06em]">ご注文を受け付けました</h1>
        <dl class="flex flex-col gap-2 text-sm">
            <div class="flex gap-4"><dt class="text-ink-soft">ご注文番号</dt><dd class="tabular" data-test="order-number">{{ $receipt->number() }}</dd></div>
            <div class="flex gap-4"><dt class="text-ink-soft">お支払いの合計</dt><dd class="tabular" data-test="order-total">{{ $receipt->totalLabel() }}</dd></div>
        </dl>
        {{-- 支払いは Webhook で確かめたものだけを「済み」とする。戻ってきた印（returned）は案内の文を変えるだけ --}}
        @if ($receipt->free)
            <p class="bg-band px-5 py-4 text-[13px]" data-test="order-free">ご注文を受け付けました。お支払いは不要です。発送の準備ができしだい、お届けします。</p>
        @elseif ($receipt->direct())
            {{-- 銀行振込の振込先と期限・代金引換の案内（核の部品。ADR 0074） --}}
            <x-storefront::direct-payment :payment="$receipt->payment" />
        @elseif ($receipt->paid)
            <p class="bg-band px-5 py-4 text-[13px]" data-test="payment-paid">お支払いを受け付けました。ありがとうございます。発送の準備ができしだい、お届けします。</p>
        @elseif ($receipt->cancelled)
            <p class="bg-band px-5 py-4 text-[13px]" data-test="order-cancelled">{{ $receipt->cancelledForExpiry ? 'お支払いの期限が過ぎたため、ご注文を取り消しました。お手数ですが、もう一度ご注文ください。' : 'ご注文を取り消しました。くわしくは、お店からのご連絡をご確認ください。' }}</p>
        @elseif ($receipt->returned)
            <p class="bg-band px-5 py-4 text-[13px]" data-test="payment-pending">お支払いを確認しています。しばらくしてから、このページを再読み込みしてください。</p>
        @else
            <p class="bg-band px-5 py-4 text-[13px]" data-test="payment-due">お支払いがまだ済んでいません。お支払いが済むと、ご注文が確定します。</p>
        @endif
        @if ($flash['error'])
            <p role="alert" class="bg-band px-5 py-3 text-[13px] text-sold" data-test="payment-error">{{ $flash['error'] }}</p>
        @endif
        {{-- 贈り物の様子（核の部品。贈り物の注文だけ。ADR 0055） --}}
        <x-storefront::gift-share :gift="$receipt->gift" />
        <div class="flex flex-wrap gap-3">
            @unless ($receipt->paid || $receipt->free || $receipt->cancelled || $receipt->direct())
                <form method="post" action="{{ route('storefront.checkout.pay') }}">
                    @csrf
                    <button type="submit" class="inline-flex h-12 items-center bg-accent px-8 text-sm tracking-[0.08em] text-on-accent hover:bg-accent-hover" data-test="pay">お支払いへ進む</button>
                </form>
            @endunless
            <a href="{{ route('storefront.home') }}" class="inline-flex h-12 items-center border border-line px-8 text-sm tracking-[0.08em] hover:border-ink" data-test="continue-shopping">お買い物を続ける</a>
        </div>
    </div>
@endsection
