{{-- 年齢の門（ADR 0077）。$age（20 など）・$return（答えた後に戻る、同じショップの中の道筋）。検索に載せない --}}
@extends($theme->view('layout'))

@section('title', '年齢の確認 — '.$shop->name)

@section('content')
    <div class="sugar-page sugar-account sugar-account--card" data-test="age-gate">
        <div class="sugar-account__card">
            <div class="sugar-account__head">
                <h1 class="sugar-page__title">{{ $age }} 歳以上ですか</h1>
                <p class="sugar-page__lead">この先の商品は、{{ $age }} 歳以上の方だけがお求めいただけます。ご注文の時にも、生年月日で確認します。</p>
            </div>
            <form method="post" action="{{ route('storefront.plugin.age-check.gate.answer') }}" class="sugar-account__form">
                @csrf
                <input type="hidden" name="age" value="{{ $age }}">
                <input type="hidden" name="return" value="{{ $return }}">
                <button type="submit" name="answer" value="yes" class="sugar-account__primary" data-test="age-gate-yes">はい、{{ $age }} 歳以上です</button>
                <button type="submit" name="answer" value="no" class="sugar-account__secondary" data-test="age-gate-no">いいえ</button>
            </form>
        </div>
    </div>
@endsection
