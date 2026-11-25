{{--
    ギフトカードでのお支払いの行（核の部品。ADR 0076）。テーマの注文の確かめの画面が、合計の欄（dl）の中の合計の下に置く。
    使い方（テーマ）: <x-storefront::gift-card-used :cart="$order->cart" />
    ギフトカードは値引きではなく支払いの一部なので、合計（税込み）は変えずに「ギフトカード（末尾 XXXX）−¥3,000」「お支払い ¥1,950」を合計の下に出す。
    カードの残り（使った後）と期限も。使っていなければ何も出さない。使った後のお支払いが 1〜49 円になるので抑えたときは、その一言も。見た目は核の CSS（resources/storefront/apps.css の .sugar-gift-card-used）
--}}
@props(['cart'])
@if ($cart->giftCard->appliedLabel() !== null)
    <div {{ $attributes->merge(['class' => 'sugar-gift-card-used', 'data-test' => 'gift-card-used']) }}><dt>ギフトカード（末尾 {{ $cart->giftCard->last4 }}）</dt><dd class="tabular">{{ $cart->giftCard->appliedLabel() }}</dd></div>
    <div class="sugar-gift-card-used sugar-gift-card-used--due" data-test="gift-card-due"><dt>お支払い</dt><dd class="tabular">{{ $cart->dueLabel() }}</dd></div>
    <div class="sugar-gift-card-used sugar-gift-card-used--note" data-test="gift-card-remaining"><dt>ギフトカードの残高</dt><dd><span class="tabular">{{ $cart->giftCard->remainingLabel() }}</span>（有効期限 {{ $cart->giftCard->expiresAt }}）@if ($cart->giftCard->lowered)<br>お支払いは 50 円以上が必要なため、ギフトカードのご利用額を減らしました。@endif</dd></div>
@endif
