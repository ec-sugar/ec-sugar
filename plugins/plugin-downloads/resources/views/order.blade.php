{{--
    注文のダウンロードの画面（App「ダウンロード販売」）。会員の自分の注文か、ご注文の確認の照会を通した人だけが開ける。
    App の画面で、テーマの外枠の中に描く（App のタグは差し込まない）。出すのは注文番号・ファイルの名前と大きさ・残りの回数・期限だけ。
    押すと回数を 1 つ使い、短い期限のリンクからファイルを受け取る（POST。CSRF つき）
--}}
@extends($theme->view('layout'))

@section('title', 'ダウンロード — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account" data-test="downloads-order">
        @if ($member)
            <p class="sugar-account__back"><a href="{{ route('storefront.plugin.downloads.account') }}">← ダウンロードの一覧</a></p>
        @endif
        <h1 class="sugar-page__title">ダウンロード</h1>
        <p class="sugar-page__lead">ご注文番号 {{ $number }}。「ダウンロード」を押すと、ダウンロードできる回数を 1 回使います。期限が過ぎたときや、回数を使い切ったときは、お店へお問い合わせください。</p>
        @if ($message)
            <p role="status" class="sugar-account__notice" data-test="downloads-message">{{ $message }}</p>
        @endif

        @if ($items === [])
            <p class="sugar-page__empty" data-test="downloads-empty">お渡しできるファイルはまだありません（お支払いが確認できると、ここに表示されます）。</p>
        @else
            <ul class="sugar-account__lines">
                @foreach ($items as $item)
                    <li data-test="downloads-item">
                        <span>{{ $item['name'] }}（{{ $item['size'] }}）</span>
                        <span class="sugar-page__note">残り {{ $item['left'] }} 回 / {{ $item['max'] }} 回・{{ $item['expires'] }} まで</span>
                        @if ($item['action'])
                            <form method="post" action="{{ $item['action'] }}">
                                @csrf
                                <button type="submit" data-test="downloads-fetch">ダウンロード</button>
                            </form>
                        @else
                            <span class="sugar-page__note" data-test="downloads-reason">{{ $item['reason'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
