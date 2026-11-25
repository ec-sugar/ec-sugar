{{--
    注文の項目の値（ADR 0066）。核の部品で、テーマの注文の確認の画面（$order->orderFields。入れた本人のセッションなので文の答えも出す）と、
    ご注文の状況の画面（$order->fields と :withheld="$order->fieldsWithheld"）が置く。核のマイページも使う。
    ご注文の状況とマイページには選ぶ・チェックの答えだけが渡り、文の答え（ご要望など）は「お店に届いています」の一言だけ（ADR 0066 の決定 5）。
    答えが何も無ければ何も出さない。過去の注文は、注文の時の欄の名前で出る。値は文字のまま出す（HTML として解釈しない。改行はそのまま見せる）
--}}
@props(['fields', 'withheld' => false])
@if ($fields !== [] || $withheld)
    <div class="sugar-order-fields-summary" data-test="order-fields-summary">
        <p class="sugar-order-fields-summary__title">ご注文について</p>
        @if ($fields !== [])
            <dl class="sugar-order-fields-summary__list">
                @foreach ($fields as $field)
                    <div class="sugar-order-fields-summary__row" data-test="order-fields-summary-row">
                        <dt class="sugar-order-fields-summary__label">{{ $field['name'] }}</dt>
                        <dd class="sugar-order-fields-summary__value">{{ $field['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
        @if ($withheld)
            <p class="sugar-order-fields-summary__note" data-test="order-fields-withheld">ご注文時にご記入いただいた内容は、ショップで受け付けました。</p>
        @endif
    </div>
@endif
