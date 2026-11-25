{{--
    外部送信に関する公表（ADR 0034 の 5。電気通信事業法の外部送信規律）。核が、その店で使っている App のマニフェストから作る。
    冒頭の文は、同意の帯を出す店（$asksConsent）と出さない店（既定）で変える。
    テーマの外枠の中に描く（テーマの画面として）。文は App の作り手が書いたものなので {{ }} で文字として出すだけ。
    注: 冒頭の文と並べ方は雛形。公開の前に専門家（弁護士など）に確かめる（ADR 0034）
--}}
@extends($theme->view('layout'))

@section('title', '外部送信に関する公表 — '.$shop->name)

@section('content')
    <div class="sugar-page" data-test="disclosure-page">
        <h1 class="sugar-page__title">外部送信に関する公表</h1>
        <p class="sugar-page__lead">
            {{ $shop->name }}では、ショップのページをご覧になったときに、次の事業者へお客さまの情報が送信されることがあります。
            @if ($asksConsent)
                アクセス解析と広告の効果測定のための送信は、お客さまが同意された場合にだけ行います。
            @else
                アクセス解析と広告の効果測定のための送信は、ショップのページを表示したときに行います。送信先ごとの停止の方法（オプトアウト）は、各事業者の案内ページをご覧ください。
            @endif
            ページ内の動画と地図は、そのページを表示したときに、動画と地図の事業者から読み込みます（このときに情報が送信されます）。
            ご注文の入力・確認の画面と、ご注文の状況の画面では、外部への送信は一切行いません。
        </p>
        @if (collect($rows)->contains(fn ($row) => ($row['agent'] ?? false) === true))
            {{-- 店が AI エージェントにお客さんの情報を渡す（ADR 0101）。ショップを見た時でなく、店の人が頼んだ時に送られる --}}
            <p class="sugar-page__lead" data-test="disclosure-agent-note">
                「AI エージェント」の行は、ページの表示時ではなく、ショップの担当者が AI エージェントにご注文やお問い合わせの対応を依頼したときに、その対応に必要な情報が送信されるものです。
            </p>
        @endif

        @if ($rows === [])
            <p class="sugar-page__empty" data-test="disclosure-empty">現在、外部の事業者へ情報を送信していません。</p>
        @else
            <div class="sugar-page__list">
                @foreach ($rows as $row)
                    <section class="sugar-page__item" data-test="disclosure-row">
                        <h2 class="sugar-page__heading">{{ $row['disclosure']->company }}</h2>
                        <dl class="sugar-page__table">
                            <div><dt>送信する情報</dt><dd>{{ $row['disclosure']->data }}</dd></div>
                            <div><dt>目的</dt><dd>{{ $row['disclosure']->purpose }}</dd></div>
                            <div><dt>利用している機能</dt><dd>{{ $row['title'] }}</dd></div>
                            <div><dt>送信先の案内</dt><dd><a href="{{ $row['disclosure']->policy }}" rel="noopener noreferrer external">{{ $row['disclosure']->policy }}</a></dd></div>
                        </dl>
                    </section>
                @endforeach
            </div>
        @endif

        @if ($apps->consentUrl)
            <p><a href="{{ $apps->consentUrl }}" class="sugar-page__link">同意の内容を変更する（Cookie の設定）</a></p>
        @endif
    </div>
@endsection
