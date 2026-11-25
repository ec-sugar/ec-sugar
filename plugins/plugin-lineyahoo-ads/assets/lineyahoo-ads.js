// App「LINEヤフー広告」（ADR 0034、docs/design/tag-apps.md の 5）。計測タグ（新しい形）を読む。
// 核が <script src defer> でこのファイルを読み、設定（data-setting-tag-id）・画面の種類（data-sugar-page）・
// 購入の完了の値（data-order-number・data-order-total・data-order-currency・data-order-items）を data- の印で渡す。
// 核が差し込むのは、店がこの App を使い、同意の帯を出す店ではお客さんが「広告」に同意し、ふつうか購入の完了の画面のときだけ。
// 同意の帯は既定では出さない。帯を出さない店では同意を待たずに差し込むので、読み込まれたことを「同意あり」として扱う（店が帯を出さないと決めたため）。
// 個人情報の画面では読まれない。名前・メール・住所・電話は核から渡らず、照合用の情報（email・phoneNumber）も送らない。
// 計測タグの同意の API（同意あり・なしを伝える呼び出し）は公式の資料に無いので、同意の帯を出す店では、同意の後に読まれることだけで守る。
// Trusted Types の既定の関所（default policy）は核が作る（この App では作らない）。lytag.js の src は、その関所が
// マニフェストの script-src（https://tag.flvcdn.net）に合うかを見て通す。
// 読み込みと呼び出しの形は、LINEヤフーが公開する Google タグ マネージャーのテンプレート（yahoojp-marketing の
// gtm-template-tag-global-snippet・gtm-template-tag-event-snippet）から読み取った: lytag.js を読み、キューの関数 lytag が
// lyDataLayer に arguments を積む。lytag.js（2026-10-29 に読んだ、最終更新 2026-10-15）は自前の関所を作らず、2 段目の
// スクリプトも読まない（createElement・innerHTML・eval が無い）。送信は fetch で https://apm.yahoo.co.jp へ。
// 応答の見出し（X-Z-BurlS）にある別の宛先へも送ろうとするが、マニフェストに書いていない宛先は CSP で止まる
(() => {
    const me = document.currentScript;

    if (!me) {
        return;
    }

    const data = me.dataset;
    const tagId = data.settingTagId || '';

    // 形は核がサーバーで確かめ済み（UUID の第 4 版。lytag.js も同じ形でなければ何もしない）。念のためここでも確かめる
    if (!/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(tagId)) {
        return;
    }

    // 公式のテンプレートと同じ形のキュー（lytag.js が読み込まれたら、積んだ分を順に処理する）
    window.lyDataLayer = window.lyDataLayer || [];

    if (typeof window.lytag !== 'function') {
        window.lytag = function lytag() {
            window.lyDataLayer.push(arguments);
        };

        const script = document.createElement('script');
        script.async = true;

        try {
            // Trusted Types の下では、核の既定の関所が宣言した宛先（script-src）の URL だけを通す
            script.src = 'https://tag.flvcdn.net/lytag.js';
        } catch {
            return; // 関所が通さないときは読み込まない（止まる側に倒す）
        }

        document.head.appendChild(script);
    }

    const lytag = window.lytag;

    // 初期化: 照合用の情報（email・phoneNumber）は渡さない。広告から来たことの識別子は、同意があるので Cookie と
    // ローカルストレージに残す（lytag.js の既定は残さない。残さないと、広告を押した後の別のページでの購入が数えられない）
    lytag({ type: 'init', tagId, config: { useCookie: true, useLocalStorage: true } });
    // ページの表示。パートナー企業へは送らない（isExternalTransmission の既定は送る）
    lytag({ type: 'event', eventType: 'page_view', tagId, config: { isExternalTransmission: false } });

    if (data.sugarPage !== 'purchase' || !data.orderNumber) {
        return;
    }

    // 購入の完了: 注文の番号（重複を除く transactionId）・金額・通貨・品の ID と数だけを送る。
    // 同じ番号は LINEヤフーが 7 日のあいだ重複として除くので、画面を読み直しても二度は数えられない
    const config = { transactionId: data.orderNumber, isExternalTransmission: false };
    const currency = (data.orderCurrency || '').toUpperCase();
    const total = Number(data.orderTotal);

    // 金額を送れる通貨は JPY と USD だけ（公式の資料）。ほかの通貨では金額を付けずに購入だけを数える
    if (Number.isSafeInteger(total) && total >= 0 && (currency === 'JPY' || currency === 'USD')) {
        // 核は最小通貨単位の整数で渡す（JPY なら円、USD ならセント）。LINEヤフーは通常の単位で受ける
        config.value = currency === 'USD' ? total / 100 : total;
        config.currency = currency;
    }

    let items = [];

    try {
        const parsed = JSON.parse(data.orderItems || '[]');

        items = Array.isArray(parsed) ? parsed : [];
    } catch {
        items = [];
    }

    // 商品 ID は英数字・ハイフン・下線の 100 文字まで、数は 10 桁までの整数、品は 10 個まで（11 個以上は lytag.js が品を全部捨てる）
    const lines = items
        .filter((item) => item && typeof item.id === 'string' && /^[A-Za-z0-9_-]{1,100}$/.test(item.id)
            && Number.isSafeInteger(item.quantity) && item.quantity > 0 && item.quantity < 1e10)
        .slice(0, 10)
        .map((item) => ({ itemId: item.id, quantity: item.quantity }));

    if (lines.length > 0) {
        config.items = lines;
    }

    lytag({ type: 'event', eventType: 'purchase', tagId, config });
})();
