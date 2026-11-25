{{--
    デモの店の帯（ADR 0086。テーマ API 2 の付け足し）。テーマは layout と password の <body> のすぐ後に 1 行置くだけ:
        <x-storefront::demo-banner />
    デモの店（CLI の shop:demo で印を付けた店）でだけ描き、ほかの店では何も出さない。
    文は核が決める: 「デモのお店です。実際の代金は動きません」と、送るはずだったメール・デモの会員でログインの入口。
    見た目は核の CSS（resources/storefront/apps.css の .sugar-demo）がテーマの色の変数で付け、テーマは data-sugar-demo の印で上書きできる
--}}
@if (\App\Core\Sample\Demo\DemoShop::active())
    <div class="sugar-demo" data-sugar-demo role="note" data-test="demo-banner">
        <p class="sugar-demo__text"><strong>デモのお店です。</strong>代金は実際には請求されません（カードはテスト用の番号 4242 4242 4242 4242 でお試しいただけます）。メールは実際には送信せず、「送るはずだったメール」に表示します。</p>
        <p class="sugar-demo__actions">
            <a href="{{ route('storefront.demo.mails') }}" class="sugar-demo__link" data-test="demo-mails-link">送るはずだったメール</a>
            @unless (auth('customer')->check())
                <form method="post" action="{{ route('storefront.demo.member') }}" class="sugar-demo__form">
                    @csrf
                    <button type="submit" class="sugar-demo__button" data-test="demo-member">デモの会員でログイン</button>
                </form>
            @endunless
        </p>
    </div>
@endif
