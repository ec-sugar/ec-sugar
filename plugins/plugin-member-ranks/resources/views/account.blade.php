{{--
    マイページの「会員ランク」（App「会員ランク」、ADR 0079）。今のランク・直近 12 か月のお買い物の額・次のランクまでの額・特典・ランクの一覧
--}}
@extends($theme->view('layout'))

@section('title', '会員ランク — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="member-ranks">
        <h1 class="sugar-page__title">会員ランク</h1>

        <section class="sugar-account__section" aria-labelledby="member-rank-now">
            <h2 id="member-rank-now" class="sugar-page__heading">今のランク</h2>
            <p class="sugar-page__lead" data-test="member-rank-current">{{ $rank === null ? 'まだランクはありません' : $rank->name }}</p>
            @if ($benefits !== [])
                <ul class="sugar-page__note" data-test="member-rank-benefits">
                    @foreach ($benefits as $benefit)
                        <li>{{ $benefit }}</li>
                    @endforeach
                </ul>
            @endif
            <p class="sugar-page__note" data-test="member-rank-amount">直近 12 か月（{{ $since }}から）のお買い物: {{ $amount }}</p>
            @if ($next !== null)
                <p class="sugar-page__note" data-test="member-rank-next">
                    @if ($reached)
                        「{{ $next->name }}」の条件の額に届きました。毎日 1 回のランクの更新で切り替わります。
                    @else
                        あと {{ $needed }} で「{{ $next->name }}」です。
                    @endif
                </p>
            @elseif ($rank !== null)
                <p class="sugar-page__note" data-test="member-rank-next">いちばん上のランクです。</p>
            @endif
        </section>

        <section class="sugar-account__section" aria-labelledby="member-rank-list">
            <h2 id="member-rank-list" class="sugar-page__heading">ランクと特典</h2>
            <ul class="sugar-page__note" data-test="member-rank-list">
                @foreach ($ranks as $entry)
                    <li>
                        {{ $entry['name'] }}（{{ $entry['threshold'] }} 以上）@if ($entry['current'])（今のランク）@endif
                        {{ $entry['benefits'] === [] ? '' : '— '.implode('・', $entry['benefits']) }}
                    </li>
                @endforeach
            </ul>
            <p class="sugar-page__note">ランクは、直近 12 か月に発送したご注文の額（返金した分を除く）で、毎日 1 回更新します。会員価格は、カートとご注文の確認の画面で値引きとして反映されます（ほかの割引やクーポンとは併用できず、いちばんお得な 1 つが適用されます）。</p>
        </section>

        <p class="sugar-account__actions"><a href="{{ route('storefront.account') }}" class="sugar-account__secondary">マイページへ戻る</a></p>
    </div>
@endsection
