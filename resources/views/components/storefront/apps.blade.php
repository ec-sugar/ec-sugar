{{--
    App の差し込み口（ADR 0034）。テーマは layout の </body> の前に 1 行 <x-storefront.apps :apps="$apps" /> を置くだけ。
    核が描くもの: 同意の帯（この店で同意が要る App があるとき。個人情報の画面では出さない）と、差し込む App の <script src defer>。
    スクリプトの src は同じオリジンの App のファイルだけ、値は data- の印で、すべて {{ }} でエスケープする（HTML の文字を渡す口は無い）。
    App を差し込む画面では、App のスクリプトより前に、核の Trusted Types の既定の関所（resources/storefront/trusted-types.js）を読む。
    関所が script の src に通す外の宛先は、この画面で差し込んだ App の script-src だけを data-sugar-script-src（JSON）で渡す。
    defer どうしは書いた順に動くので、関所は App のスクリプトより先にできる。App は関所を作らない（ADR 0034）。
    帯の見た目は核の CSS（resources/storefront/apps.css）がテーマの色の変数で付け、テーマは data-sugar-consent の印で上書きできる
    商品のプレビューの画面では、App を差し込まず、画面の上にプレビューの帯を描く（どのテーマでも同じ。見た目は apps.css の data-sugar-preview）。
    帯があると、核の部品（cart.js）と CSS が「カートに入れる」のフォーム（form[data-cart-form]）を押せなくする
    テーマの下書きのプレビュー（ADR 0039 区切り 2）では、帯の代わりに編集の印（data-sugar-editor に枠の親＝管理画面のオリジン）を置く。
    核の部品（editor.js）がこれを見て、区画（data-section）を押した時に親へ知らせ、リンクとフォームを動かさない。
    data-sugar-trial は区画の試し見の断片の道筋（同じオリジンの署名つき）。親が知らせた種類とパターンを足して読み、区画を仮に差し込む
--}}
@props(['apps'])
@if ($apps->preview && $apps->editor !== null)
    <div hidden data-sugar-preview data-sugar-editor="{{ $apps->editor }}" @if ($apps->trial !== null) data-sugar-trial="{{ $apps->trial }}" @endif data-test="editor-marker"></div>
@elseif ($apps->preview)
    <div class="sugar-preview" data-sugar-preview role="note" data-test="preview-banner">プレビュー（お客さんには見えていません）</div>
@endif
@if ($apps->banner)
    <section class="sugar-consent" data-sugar-consent aria-labelledby="sugar-consent-title" data-test="consent-banner" @if (! $apps->banner->open) hidden @endif>
        <div class="sugar-consent__inner">
            <h2 id="sugar-consent-title" class="sugar-consent__title">外部への送信と Cookie について</h2>
            <p class="sugar-consent__text">
                このショップでは、{{ $apps->banner->purposes() }}のために、外部の事業者へ情報を送信します。送信するのは同意いただいた場合だけです（設定はいつでも変更できます）。
                @if ($apps->disclosureUrl)
                    <a href="{{ $apps->disclosureUrl }}" class="sugar-consent__link">送信先と送信する情報</a>
                @endif
            </p>
            <form method="post" action="{{ route('storefront.consent.update') }}" class="sugar-consent__actions">
                @csrf
                <input type="hidden" name="return" value="{{ $apps->banner->returnPath }}">
                <button type="submit" name="decision" value="all" class="sugar-consent__button" data-test="consent-accept">同意する</button>
                <button type="submit" name="decision" value="none" class="sugar-consent__button" data-test="consent-reject">同意しない</button>
                <a href="{{ route('storefront.consent', ['return' => $apps->banner->returnPath]) }}" class="sugar-consent__link">個別に設定する</a>
            </form>
        </div>
    </section>
@endif
@if ($apps->tags !== [])
    <script src="{{ \Illuminate\Support\Facades\Vite::asset($apps::TRUSTED_TYPES_SCRIPT) }}" defer data-sugar-trusted-types data-sugar-script-src="{{ json_encode($apps->scriptSources(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}"></script>
@endif
@foreach ($apps->tags as $tag)
    <script src="{{ $tag->src }}" defer @foreach ($tag->data as $name => $value) {{ $name }}="{{ $value }}" @endforeach></script>
@endforeach
