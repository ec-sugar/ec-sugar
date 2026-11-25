{{--
    App の料金の選んだ内容（ラッピング・のしなど。核の部品。ADR 0078）。テーマの注文の確かめの画面が、注文の項目の値（order-fields-summary）の後に置く。
    使い方（テーマ）: <x-storefront::charges-summary :items="$order->charges" />
    入れた本人のセッションの画面なので、名入れの文字も出す（ご注文の状況・メールには出さず、料金の行の名前に「名入れあり」とだけ出る）。
    選んでいなければ何も出さない。値は文字のまま出す（HTML として解釈しない）。見た目は注文の項目の値と同じ核の CSS（.sugar-order-fields-summary）
--}}
@props(['items'])
@if ($items !== [])
    <div class="sugar-order-fields-summary" data-test="charges-summary">
        <dl class="sugar-order-fields-summary__list">
            @foreach ($items as $item)
                <div class="sugar-order-fields-summary__row" data-test="charges-summary-row">
                    <dt class="sugar-order-fields-summary__label">{{ $item['name'] }}</dt>
                    <dd class="sugar-order-fields-summary__value">{{ $item['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
@endif
