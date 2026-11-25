{{--
    お届け日時の希望（ADR 0059）。核の部品で、テーマの注文の確認の画面（$order->deliveryLabel()）と、
    ご注文の状況の画面（$order->delivery）が置く。$label が null（店が受けていない・選ばなかった注文・贈り物）なら何も出さない。
    予約の品のある注文（ADR 0073）は、ご注文の状況の画面が :preorder="$order->preorder" と :ordered-on="$order->preorderOrderedOn" も渡し、
    「発送の予定 11月20日ごろ発送（予約商品）」（発売日が変わっていれば注文の時の日を添える）を出す
--}}
@props(['label', 'preorder' => null, 'orderedOn' => null])
@if ($preorder !== null)
    <div class="sugar-delivery-summary" data-test="preorder-summary">
        <span class="sugar-delivery-summary__label">発送予定</span>
        <span class="sugar-delivery-summary__value" data-test="preorder-summary-value">{{ $preorder }}@if ($orderedOn !== null)（発売日が変更になりました。ご注文時は {{ $orderedOn }}）@endif</span>
    </div>
@endif
@if ($label !== null)
    <div class="sugar-delivery-summary" data-test="delivery-summary">
        <span class="sugar-delivery-summary__label">お届け希望日時</span>
        <span class="sugar-delivery-summary__value" data-test="delivery-summary-value">{{ $label }}</span>
    </div>
@endif
