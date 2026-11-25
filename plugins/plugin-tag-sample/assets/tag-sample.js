// 見本の App「試験用のタグ」（ADR 0034）。宛先は実在しない（.test）。
// 核が <script src defer> でこのファイルを読み、設定（data-setting-*）・画面の種類（data-sugar-page）・
// 購入の完了の値（data-order-number・data-order-total・data-order-currency・data-order-items）を data- の印で渡す。
// 核が差し込むのは、店がこの App を使い、同意の帯を出す店ではお客さんが同意し（analytics）、ふつうか購入の完了の画面のときだけ。
// 同意の帯は既定では出さない。帯を出さない店では同意を待たずに差し込むので、読み込まれたことを「同意あり」として扱う（店が帯を出さないと決めたため）。
// 個人情報の画面では読まれない。名前・メール・住所・電話は核から渡らない。
(() => {
    const me = document.currentScript;

    if (!me) {
        return;
    }

    const data = me.dataset;

    // Trusted Types の既定の関所（default policy）は作らない。核がこのファイルより前に作り、この App のマニフェストの
    // script-src の宛先（と同じオリジンの /apps/…）だけを script の src に通す（ADR 0034）。ショップの CSP は関所を default の 1 つ・
    // 1 度だけに絞るので、App が作ろうとしても失敗する。外のスクリプトを読むときは、文字の URL を script.src に入れれば核の関所を通る

    let items = [];

    try {
        items = JSON.parse(data.orderItems || '[]');
    } catch {
        items = [];
    }

    const state = {
        page: data.sugarPage || null,
        tagId: data.settingTagId || null,
        region: data.settingRegion || null,
        order: data.orderNumber
            ? { number: data.orderNumber, total: Number(data.orderTotal), currency: data.orderCurrency, items }
            : null,
    };

    // ブラウザのテストが読む印（送ったものを外から確かめられるように）
    window.sugarTagSample = state;
    document.documentElement.setAttribute('data-tag-sample', state.page ?? '');

    // ページの表示を送る（道筋だけ。クエリには署名などが入りうるので送らない）
    if (data.settingSendPageViews === 'true' && typeof navigator.sendBeacon === 'function') {
        navigator.sendBeacon('https://collect.example.test/collect', JSON.stringify({ id: state.tagId, path: window.location.pathname, order: state.order }));
    }
})();
