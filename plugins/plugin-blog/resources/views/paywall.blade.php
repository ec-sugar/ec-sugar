{{--
    「ここから先は有料」の案内。$paywall（PostPaywall）が入っているのは、有料の分を落としたときだけ。
    **ここに出せるのは案内と導線だけで、有料の本文はそもそもこの画面に渡っていない**（サーバーが組み立てる前に落としている）。
    買う・入会するボタンは後の区切り（買い切り・月額）がこの中に差し込む。文は決まった文で、店の人の入力は入らない
--}}
<aside class="sugar-paywall" data-test="news-post-paywall" aria-label="ここから先は有料">
    <p class="sugar-paywall__message">{{ $paywall->message() }}</p>
    @if ($paywall->signInNote())
        <p class="sugar-paywall__note">
            {{ $paywall->signInNote() }}
            <a class="sugar-page__link" href="{{ $paywall->signInUrl() }}">ログインする</a>
        </p>
    @endif
    {{-- 買う・入会するの導線はここ（区切り 3・4） --}}
</aside>
