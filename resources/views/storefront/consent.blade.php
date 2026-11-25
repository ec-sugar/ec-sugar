{{--
    同意（Cookie の設定。ADR 0034 の 4）。核が描く。この店で同意が要る種類ごとに、同意するかを選ぶ（JavaScript が無くても動くフォーム）。
    決めたことはこのブラウザの Cookie にだけ残す。テーマの外枠の中に描き、App のスクリプトは差し込まない
--}}
@extends($theme->view('layout'))

@section('title', 'Cookie の設定 — '.$shop->name)

@section('content')
    <div class="sugar-page" data-test="consent-page">
        <h1 class="sugar-page__title">Cookie の設定</h1>

        @if ($choices === [])
            <p class="sugar-page__empty" data-test="consent-empty">このショップには、同意が必要な外部への送信はありません。</p>
        @else
            <p class="sugar-page__lead">
                次の目的で外部の事業者へ情報を送信するかどうかを選べます。同意しない場合も、お買い物はそのままご利用いただけます。
                @if ($apps->disclosureUrl)
                    送信先と送信する情報は、<a href="{{ $apps->disclosureUrl }}" class="sugar-page__link">外部送信に関する公表</a>でご確認いただけます。
                @endif
            </p>
            <form method="post" action="{{ route('storefront.consent.update') }}" class="sugar-page__list">
                @csrf
                <input type="hidden" name="return" value="{{ $returnPath }}">
                @foreach ($choices as $choice)
                    <label class="sugar-page__choice" data-test="consent-choice">
                        <input type="checkbox" name="grant[]" value="{{ $choice['kind']->value }}" @checked(($decisions[$choice['kind']->value] ?? false) === true)>
                        <span>
                            <strong>{{ $choice['kind']->label() }}</strong>
                            <span class="sugar-page__note">{{ $choice['kind']->description() }}（{{ implode('・', $choice['apps']) }}）</span>
                        </span>
                    </label>
                @endforeach
                <div class="sugar-consent__actions">
                    <button type="submit" name="decision" value="choose" class="sugar-consent__button" data-test="consent-save">選んだ内容で保存する</button>
                    <button type="submit" name="decision" value="none" class="sugar-consent__button" data-test="consent-reject-all">すべて同意しない</button>
                </div>
            </form>
        @endif
    </div>
@endsection
