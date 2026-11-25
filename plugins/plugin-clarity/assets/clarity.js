// App「Microsoft Clarity」（ADR 0034）。公式のトラッキングコードと同じこと（キューの関数を作る → 外のスクリプトを読む）を、
// 画面の中に直に書かず、同じオリジンのこのファイルで行う。
// 核が <script src defer> でこのファイルを読み、プロジェクト ID を data-setting-project-id で渡す。
// 核が差し込むのは、店がこの App を使い、同意の帯を出す店ではお客さんが「解析」に同意し、ふつうの画面のときだけ（購入の完了・個人情報の画面には入れない）。
// 同意の帯は既定では出さない。帯を出さない店では同意を待たずに差し込むので、読み込まれたことを「同意あり」として扱う（店が帯を出さないと決めたため）。
// Trusted Types の既定の関所（default policy、script の src に入れる URL の確かめ）は核が作る。この App では作らない。
(() => {
    const me = document.currentScript;

    if (!me) {
        return;
    }

    // 核がマニフェストの形（英小文字と数字の 8〜12 文字）で確かめた値だけが来るが、URL に入れる前にもう一度確かめる
    const projectId = me.dataset.settingProjectId || '';

    if (!/^[a-z0-9]{8,12}$/.test(projectId)) {
        return;
    }

    // 隠す設定（マスク）を一番強くする。公式の data-clarity-mask を <html> に付けると、その下の全部（ページの文字すべて）が伏せ字になり、
    // Clarity の管理画面の隠す設定より優先される。入力欄とプルダウンの中身は、Clarity がどの設定でも記録しない
    document.documentElement.setAttribute('data-clarity-mask', 'True');

    // 公式のスニペットのキューの関数（読み込みの前の呼び出しをためておき、読み込んだ Clarity が順に処理する）
    const w = window;

    if (typeof w.clarity !== 'function') {
        w.clarity = function clarity() {
            (w.clarity.q = w.clarity.q || []).push(arguments);
        };
    }

    // 同意の API（consentv2）。核は、同意の帯を出す店では「解析」に同意したお客さんにだけこのファイルを差し込むので、解析は同意あり。
    // 広告（ad_Storage）の同意はこの App では聞いていないので「なし」と伝える（Microsoft の広告との結びつけをしない）
    w.clarity('consentv2', { ad_Storage: 'denied', analytics_Storage: 'granted' });

    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://www.clarity.ms/tag/' + encodeURIComponent(projectId);
    document.head.appendChild(script);
})();
