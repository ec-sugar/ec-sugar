// App「SmartNews 広告」（ADR 0034、docs/design/tag-apps.md の 11）。
// 核が <script src defer> でこのファイルを読み、設定（data-setting-pixel-id）・画面の種類（data-sugar-page）・
// 購入の完了の値（data-order-number・data-order-total・data-order-currency・data-order-items）を data- の印で渡す。
// 核が差し込むのは、店がこの App を使い、同意の帯を出す店ではお客さんが「広告」に同意し、ふつうか購入の完了の画面のときだけ。
// 同意の帯は既定では出さない。帯を出さない店では同意を待たずに差し込むので、読み込まれたことを「同意あり」として扱う（店が帯を出さないと決めたため）。
// 個人情報の画面では読まれない。名前・メール・住所・電話は核から渡らない。
// Trusted Types の既定の関所（default policy）は核が作る（この App では作らない）。pixel.js の src は、その関所が
// マニフェストの script-src（https://cdn.smartnews-ads.com）に合うかを見て通す。
// pixel.js（2026-10-30 に読んだもの。中の版 8.18.0）は関所を作らず、2 段目のスクリプト・iframe・innerHTML・eval を使わない。
// 送るのは画像（new Image）だけで、宛先は i.smartnews-ads.com/p（エラーは /pe）と、IPv6 の i6.smartnews-ads.com/smallest.png。
// 入力欄から文字を拾う仕組みは無い
(() => {
    const me = document.currentScript;

    if (!me) {
        return;
    }

    const data = me.dataset;
    const pixelId = data.settingPixelId || '';

    // 形は核がサーバーで確かめ済み（英小文字と数字 16〜32 文字）。念のためここでも合わなければ何もしない
    if (!/^[a-z0-9]{16,32}$/.test(pixelId)) {
        return;
    }

    // 公式のベースコードの前半（キューの関数 SmartnewsAds.p を作り、pixel.js を async で読む）を、画面に直に書かずにここで行う。
    // pixel.js は読み終えると p.callMethod を置き、p.queue に溜まった呼び出しを順に送る。p.version は送信の v に入る
    window.SmartnewsAds = window.SmartnewsAds || {};

    if (!window.SmartnewsAds.p) {
        const p = function (...args) {
            if (p.callMethod) {
                p.callMethod.apply(p, args);
            } else {
                p.queue.push(args);
            }
        };

        p.push = p;
        p.version = '1.0.1';
        p.queue = [];
        window.SmartnewsAds.p = p;
        window.SmartnewsAds._p = window.SmartnewsAds._p || p;

        const script = document.createElement('script');
        script.async = true;

        try {
            // Trusted Types の下では、核の既定の関所が宣言した宛先（script-src）の URL だけを通す
            // （公式の //cdn.smartnews-ads.com は https に直す）
            script.src = 'https://cdn.smartnews-ads.com/i/pixel.js';
        } catch {
            return; // 関所が通さないときは読み込まない（止まる側に倒す）
        }

        document.head.appendChild(script);
    }

    const p = window.SmartnewsAds.p;

    // 核は、同意の帯を出す店では同意の後にしかこのファイルを読まない（出さない店では読み込みを同意ありとして扱う）。SmartNews には公式の同意の API が無いので、読み込むことが「同意あり」にあたる
    p(pixelId, 'PageView');

    const order = purchase(data, 'sugar-smartnews-ads:purchase:');

    if (!order) {
        return;
    }

    // 購入の完了: 金額・通貨・品の ID と数だけを送る。
    // 金額の項目名は value（公式の値の表〔英語・日本語〕と日本語の購入の例、Conversion API の properties の例がどれも value。
    // event_value は英語のページの例 1 か所だけで、その例は content_type も値の表に無い "item"。docs/design/tag-apps.md の 11）。
    // pixel.js は項目を d[名前]=文字 にそのまま並べるだけで、配列の中の物（contents）は "[object Object]" になって届かないので、
    // 品は content_ids（ID の並び）と quantity（合計の数）で渡す。注文の番号を受ける項目はピクセルに無いので送らない
    p(pixelId, 'Purchase', {
        currency: order.currency,
        value: order.value,
        content_ids: order.items.map((item) => item.id),
        content_type: 'product',
        quantity: order.items.reduce((sum, item) => sum + item.quantity, 0),
    });

    // 購入の完了の画面の値（無い・送り済みなら null）。金額は最小通貨単位の整数で来るので、通貨の小数の桁で割ってタグの形にする。
    // 同じタブで完了の画面を読み直したときに、二度送らない（ピクセルには重複を除く項目が無い）
    function purchase(values, sentPrefix) {
        if (values.sugarPage !== 'purchase' || !values.orderNumber) {
            return null;
        }

        const currency = (values.orderCurrency || '').toUpperCase();
        const minor = Number(values.orderTotal);

        if (!/^[A-Z]{3}$/.test(currency) || !Number.isSafeInteger(minor) || minor < 0) {
            return null;
        }

        let digits;

        try {
            digits = new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits;
        } catch {
            return null;
        }

        const sentKey = sentPrefix + values.orderNumber;

        try {
            if (window.sessionStorage.getItem(sentKey) === '1') {
                return null;
            }

            window.sessionStorage.setItem(sentKey, '1');
        } catch {
            // 使えないブラウザでは、そのまま送る
        }

        let items = [];

        try {
            const parsed = JSON.parse(values.orderItems || '[]');
            items = Array.isArray(parsed)
                ? parsed.filter((item) => item && typeof item.id === 'string' && Number.isSafeInteger(item.quantity) && item.quantity > 0)
                : [];
        } catch {
            items = [];
        }

        return { value: minor / 10 ** digits, currency, items };
    }
})();
