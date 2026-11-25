// Trusted Types の既定の関所（default policy。ADR 0034）。テーマでも App でもなく核が持つ JavaScript（ADR 0020）。
// ショップの CSP は `require-trusted-types-for 'script'` と `trusted-types default`（'allow-duplicates' なし）なので、
// 既定の関所は 1 ページに 1 つ・作れるのは最初の 1 回だけ。核が App のスクリプトより前にこのファイルを読み、ここで作る。
// App と外のタグは関所を作らない（作ろうとしても失敗する）。
//
// 核は App を差し込む画面にだけ、<script src defer data-sugar-script-src="[…]"> でこのファイルを読む（App のスクリプトの前）。
// data-sugar-script-src は、その画面で差し込んだ App のマニフェストの script-src の宛先（JSON の配列。核が形を確かめたもの）。
// 画面の中に直にスクリプトは書かない（CSP のまま）。
//
//   createScriptURL: 同じオリジンの /apps/… と、許した宛先に合う URL だけを通す。ほかは投げて止める
//   createHTML・createScript: 通さない（HTML や文字のスクリプトの差し込みが要るタグが見つかったら、ADR 0034 で決め直す）
//
// ビルドの後も、ふつうの script（module でない）として動くように、import・export を書かず、全体を 1 つの関数に包む。
(() => {
    const me = document.currentScript;

    if (!me || !window.trustedTypes || typeof window.trustedTypes.createPolicy !== 'function') {
        return;
    }

    // 宛先の形は核（CspSources）が確かめた https://（*.）ホスト（:ポート）（/道筋）だけ。合わないものは捨てる
    const SOURCE = /^https:\/\/(\*\.)?([a-z0-9.-]+)(?::([0-9]{1,5}))?(\/[A-Za-z0-9._~/-]*)?$/;

    let listed = [];

    try {
        listed = JSON.parse(me.dataset.sugarScriptSrc || '[]');
    } catch {
        listed = [];
    }

    const sources = (Array.isArray(listed) ? listed : [])
        .map((source) => (typeof source === 'string' ? SOURCE.exec(source) : null))
        .filter(Boolean)
        .map(([, wildcard, host, port, path]) => ({ wildcard: Boolean(wildcard), host, port: port === '443' ? '' : (port ?? ''), path: path ?? '' }));

    // CSP の宛先の照らし合わせと同じ考え方: https だけ、*. はサブドメイン（何段でも。元のドメインそのものは含まない）、
    // ポートは書いた番号だけ（書かなければ 443）、道筋は / で終われば前方一致、そうでなければ同じ道筋だけ
    const matches = (url, source) => {
        if (url.protocol !== 'https:' || url.username !== '' || url.password !== '') {
            return false;
        }

        const hostOk = source.wildcard ? url.hostname.endsWith(`.${source.host}`) : url.hostname === source.host;

        if (!hostOk || url.port !== source.port) {
            return false;
        }

        if (source.path === '' || source.path === '/') {
            return true;
        }

        return source.path.endsWith('/') ? url.pathname.startsWith(source.path) : url.pathname === source.path;
    };

    const allowed = (value) => {
        let url;

        try {
            url = new URL(String(value), window.location.href);
        } catch {
            return null;
        }

        // 同じオリジンの App のファイル（核が配る /apps/<App>/<ファイル>）
        if (url.origin === window.location.origin && url.pathname.startsWith('/apps/')) {
            return url.href;
        }

        return sources.some((source) => matches(url, source)) ? url.href : null;
    };

    try {
        window.trustedTypes.createPolicy('default', {
            createScriptURL(value) {
                const url = allowed(value);

                if (url === null) {
                    throw new TypeError('許していない宛先のスクリプトです');
                }

                return url;
            },
            createHTML() {
                throw new TypeError('HTML の文字の差し込みは許していません');
            },
            createScript() {
                throw new TypeError('文字のスクリプトは許していません');
            },
        });
    } catch {
        // 作れなかったとき（すでにある）は、そのまま止まる側に倒す（関所が無ければ文字の URL は通らない）
    }
})();
