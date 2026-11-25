{{--
    プライバシーポリシーの核の既定の画面（ADR 0094）。テーマに privacy の画面が無いときに、テーマの外枠の中で描く。
    $privacy（PrivacyPolicyView）: 題と、本文のブロック（店の人が書いた素の文を核が段落・見出し・箇条書きにしたもの）。
    本文は核の部品 <x-storefront::description-blocks> で描く（文はどれも {{ }} で出す。HTML として読まない）。
    外へ送る App を使う店では、外部送信に関する公表のページ（ADR 0034 の 5）への道を添える
--}}
@extends($theme->view('layout'))

@section('title', $privacy->title.' — '.$shop->name)

@section('content')
    <article class="sugar-page sugar-privacy" data-test="privacy-page">
        <h1 class="sugar-page__title">{{ $privacy->title }}</h1>
        <x-storefront::description-blocks :blocks="$privacy->blocks" :heading="2" class="sugar-privacy__body" data-test="privacy-body" />
        @if ($apps->disclosureUrl)
            <p class="sugar-page__note" data-test="privacy-disclosure">ショップのページをご覧になったときに外部の事業者へ送信される情報は、<a href="{{ $apps->disclosureUrl }}" class="sugar-page__link">外部送信に関する公表</a>に掲載しています。</p>
        @endif
    </article>
@endsection
