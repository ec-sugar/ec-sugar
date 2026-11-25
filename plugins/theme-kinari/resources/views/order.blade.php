@extends('theme-kinari::layout')

{{-- テーマ API 1: $order（OrderView: number()・status・statusLabel・placedAt・items（title・quantity・amountLabel・reduced〔軽減税率の対象。※ を添える〕）・discounts（title・amountLabel。使ったポイントの「ポイントのご利用」も）・points（ポイント。<x-storefront::order-points> に渡す。ADR 0068）・giftCard（ギフトカード。<x-storefront::order-gift-card> に渡す。ADR 0076）・shippingLabel・totalLabel・taxes（税率ごとの内訳。<x-storefront::tax-breakdown> に渡す）・shipments（carrier・trackingNumber・shippedAt）・deliveryWish（お届け日時のご希望。ADR 0059）・fields（注文の項目の選ぶ・チェックの答え。注文の時の名前で）・fieldsWithheld（文の答えがある印。中身は出さない。ADR 0066）・links（App のリンク。レビューを書く・領収書など）・payment（支払いの様子。ADR 0058）・charges（App の料金〔ラッピング・のしなど。ADR 0078〕と代引き手数料の行。title・amountLabel〔0 円は「無料」〕。ADR 0074）・cancelled()）。名前・住所・電話は渡さない --}}

@section('title', 'ご注文 '.$order->number().' — '.$shop->name)

@section('content')
    <div class="mx-auto flex max-w-[640px] flex-col gap-8 px-5 pt-14 pb-22 sm:px-8" data-test="order">
        <h1 class="font-mincho text-[28px] font-medium tracking-[0.06em]">ご注文の状況</h1>
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex h-7 items-center border border-line-strong px-3 text-xs tracking-[0.06em]" data-test="order-status-label">{{ $order->statusLabel }}</span>
            <span class="text-xs text-ink-soft">ご注文番号 <span class="tabular" data-test="order-number">{{ $order->number() }}</span>・{{ $order->placedAt }}</span>
        </div>
        @if ($order->cancelled())
            <p class="bg-band px-5 py-4 text-[13px]" data-test="order-cancelled-note">このご注文は取り消しました。お支払い済みの場合は、返金の手続きをしています。</p>
        @endif
        {{-- お届け日時のご希望（核の部品。選ばなかった注文では何も出さない。ADR 0059） --}}
        <x-storefront::delivery-summary :label="$order->deliveryWish" :preorder="$order->preorder" :ordered-on="$order->preorderOrderedOn" />
        {{-- 注文の項目の答え（核の部品。選ぶ・チェックの答えと、文の答えは「お店に届いています」の一言だけ。ADR 0066） --}}
        <x-storefront::order-fields-summary :fields="$order->fields" :withheld="$order->fieldsWithheld" />
        @if ($order->shipments !== [])
            <section class="flex flex-col gap-2" aria-labelledby="shipments">
                <h2 id="shipments" class="text-[15px] font-semibold">発送</h2>
                @foreach ($order->shipments as $shipment)
                    <p class="text-sm" data-test="shipment">{{ $shipment['shippedAt'] }} 発送{{ $shipment['carrier'] ? '（'.$shipment['carrier'].'）' : '' }}@if ($shipment['trackingNumber'])　お問い合わせ番号 <span class="tabular">{{ $shipment['trackingNumber'] }}</span>@endif</p>
                @endforeach
            </section>
        @endif
        <section class="flex flex-col gap-2" aria-labelledby="items">
            <h2 id="items" class="text-[15px] font-semibold">ご注文の内容</h2>
            <dl class="flex flex-col divide-y divide-line border-y border-line text-sm">
                @foreach ($order->items as $item)
                    <div class="flex justify-between gap-4 py-3" data-test="order-item"><dt>{{ $item['title'] }}@if ($item['reduced'] ?? false) ※@endif × {{ $item['quantity'] }}</dt><dd class="tabular whitespace-nowrap">{{ $item['amountLabel'] }}</dd></div>
                @endforeach
                @foreach ($order->discounts as $discount)
                    <div class="flex justify-between gap-4 py-3" data-test="order-discount"><dt>{{ $discount['title'] }}</dt><dd class="tabular whitespace-nowrap">{{ $discount['amountLabel'] }}</dd></div>
                @endforeach
                @if ($order->shippingLabel)
                    <div class="flex justify-between gap-4 py-3"><dt>送料</dt><dd class="tabular whitespace-nowrap">{{ $order->shippingLabel }}</dd></div>
                @endif
                @foreach ($order->charges as $charge)
                    <div class="flex justify-between gap-4 py-3" data-test="order-charge"><dt>{{ $charge['title'] }}</dt><dd class="tabular whitespace-nowrap">{{ $charge['amountLabel'] }}</dd></div>
                @endforeach
                <div class="flex justify-between gap-4 py-3 font-semibold"><dt>お支払いの合計（税込み）</dt><dd class="tabular whitespace-nowrap" data-test="order-total">{{ $order->totalLabel }}</dd></div>
            </dl>
            {{-- 税率ごとの内訳（核の部品。ADR 0063） --}}
            <x-storefront::tax-breakdown :taxes="$order->taxes" />
            {{-- ポイントの付く予定・付いた（核の部品。ADR 0068） --}}
            <x-storefront::order-points :points="$order->points" />
            {{-- ギフトカードで払った額とカードの残り（核の部品。ADR 0076） --}}
            <x-storefront::order-gift-card :gift-card="$order->giftCard" />
        </section>
        {{-- 支払いの様子（核の部品。コンビニ払いの入金待ちと払込票の画面・払われた手段。ADR 0058） --}}
        <x-storefront::payment-status :order="$order" />
        {{-- App のリンク（レビューを書く・領収書など。核の部品。使っている App が出すものが無ければ何も描かない） --}}
        <x-storefront::order-links :links="$order->links" />
    </div>
@endsection
