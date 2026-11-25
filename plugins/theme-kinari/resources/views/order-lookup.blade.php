@extends('theme-kinari::layout')

{{-- テーマ API 1: $orderNumber（入れ直しのための注文番号）、$flash（error）。照会は route('storefront.orders.find') へ POST（@csrf、order_number・email） --}}

@section('title', 'ご注文の確認 — '.$shop->name)

@section('content')
    <div class="mx-auto flex max-w-[640px] flex-col gap-8 px-5 pt-14 pb-22 sm:px-8" data-test="order-lookup">
        <h1 class="font-mincho text-[28px] font-medium tracking-[0.06em]">ご注文の確認</h1>
        <p class="text-sm">ご注文番号と、ご注文時のメールアドレスを入力してください。ご注文番号は、ご注文確認メールに記載しています。</p>
        @if ($flash['error'])
            <p role="alert" class="bg-band px-5 py-3 text-[13px] text-sold" data-test="order-lookup-error">{{ $flash['error'] }}</p>
        @endif
        <form method="post" action="{{ route('storefront.orders.find') }}" class="flex flex-col gap-5" novalidate>
            @csrf
            <label class="flex flex-col gap-2">
                <span class="text-[13px] font-semibold">ご注文番号</span>
                <input type="text" name="order_number" value="{{ $orderNumber }}" maxlength="64" autocomplete="off" spellcheck="false" required class="h-12 w-full border border-line-strong bg-canvas px-4 text-[15px]" data-test="lookup-order-number">
            </label>
            <label class="flex flex-col gap-2">
                <span class="text-[13px] font-semibold">メールアドレス</span>
                <input type="email" name="email" maxlength="254" autocomplete="email" required class="h-12 w-full border border-line-strong bg-canvas px-4 text-[15px]" data-test="lookup-email">
            </label>
            <button type="submit" class="inline-flex h-13 items-center justify-center bg-accent px-10 text-sm tracking-[0.08em] text-on-accent hover:bg-accent-hover" data-test="lookup-submit">ご注文を確認する</button>
        </form>
    </div>
@endsection
