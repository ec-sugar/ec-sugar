// App「X ピクセル」（ADR 0034、docs/design/tag-apps.md の 7）。
// 核が <script src defer> でこのファイルを読み、設定（data-setting-pixel-id・data-setting-purchase-event-id）・
// 画面の種類（data-sugar-page）・購入の完了の値（data-order-number・data-order-total・data-order-currency・data-order-items）を
// data- の印で渡す。核が差し込むのは、店がこの App を使い、同意の帯を出す店ではお客さんが「広告」に同意し、ふつうか購入の完了の画面のときだけ。
// 同意の帯は既定では出さない。帯を出さない店では同意を待たずに差し込むので、読み込まれたことを「同意あり」として扱う（店が帯を出さないと決めたため）。
// 個人情報の画面では読まれない。名前・メール・住所・電話は核から渡らず、照合用の情報（email_address・phone_number）も送らない。
// X ピクセルの同意の API（同意あり・なしを伝える呼び出し）は uwt.js に見当たらないので、同意の帯を出す店では、同意の後に読まれることだけで守る。
// Trusted Types の既定の関所（default policy）は核が作る（この App では作らない）。uwt.js の src は、その関所が
// マニフェストの script-src（https://static.ads-twitter.com）に合うかを見て通す。
// uwt.js（2026-10-29 に読んだ 2.4.12、最終更新 2026-10-13）は自前の関所を作らない。2 段目のスクリプトと iframe を作る所は
// 古い呼び出し（twttr.conversion の buildScript・buildIframe）だけで、この App は使わない（使っても関所と CSP で止まる）。
// new Function は globalThis が無いときの予備だけ。送信は sendBeacon（止まれば画像）で https://t.co と
// https://analytics.twitter.com へ（ショップが x.com でなければ analytics.twitter.com を使う）
(() => {
    const me = document.currentScript;

    if (!me) {
        return;
    }

    const data = me.dataset;
    const pixelId = data.settingPixelId || '';
    const shape = /^[a-z0-9]{4,8}$/;

    // 形は核がサーバーで確かめ済み。念のためここでも合わなければ何もしない
    if (!shape.test(pixelId)) {
        return;
    }

    // 公式のベースコードの前半（キューの関数 twq を作り、uwt.js を async で読む）を、画面に直に書かずにここで行う
    if (!window.twq) {
        const twq = function (...args) {
            if (twq.exe) {
                twq.exe(...args);
            } else {
                twq.queue.push(args);
            }
        };

        twq.version = '1.1';
        twq.queue = [];
        window.twq = twq;

        const script = document.createElement('script');
        script.async = true;

        try {
            // Trusted Types の下では、核の既定の関所が宣言した宛先（script-src）の URL だけを通す
            script.src = 'https://static.ads-twitter.com/uwt.js';
        } catch {
            return; // 関所が通さないときは読み込まない（止まる側に倒す）
        }

        document.head.appendChild(script);
    }

    const twq = window.twq;

    // ページの表示。restricted_data_use（データの使い方の制限）を付けると、uwt.js はページの入力欄からメール・電話・名前を
    // 拾う自動の照合をしない（付けないと、入力欄に文字があれば送信のたびに拾う）。その代わり X は広告の最適化に使わない
    twq('config', pixelId, { restricted_data_use: 'restrict_optimization' });

    const eventId = data.settingPurchaseEventId || '';

    if (data.sugarPage !== 'purchase' || !data.orderNumber || !shape.test(eventId)) {
        return;
    }

    // 購入の完了: 注文の番号（重複を除く conversion_id）・金額・通貨・品の ID と数だけを送る
    const number = data.orderNumber;
    const currency = (data.orderCurrency || '').toUpperCase();
    const total = Number(data.orderTotal);

    if (!/^[A-Z]{3}$/.test(currency) || !Number.isSafeInteger(total) || total < 0) {
        return;
    }

    // 同じタブで完了の画面を読み直したときに、二度送らない（conversion_id での重複の除きは公式の資料で確かめきれていない）
    const sentKey = 'sugar-x-pixel:purchase:' + number;

    try {
        if (window.sessionStorage.getItem(sentKey) === '1') {
            return;
        }

        window.sessionStorage.setItem(sentKey, '1');
    } catch {
        // 使えないブラウザでは、そのまま送る
    }

    let items = [];

    try {
        const parsed = JSON.parse(data.orderItems || '[]');

        items = Array.isArray(parsed) ? parsed : [];
    } catch {
        items = [];
    }

    const contents = items
        .filter((item) => item && typeof item.id === 'string' && Number.isSafeInteger(item.quantity) && item.quantity > 0)
        .map((item) => ({ content_id: item.id, num_items: item.quantity }));

    twq('event', 'tw-' + pixelId + '-' + eventId, {
        // 核は最小通貨単位の整数で渡す（JPY なら円）。X は通常の単位で受けるので、通貨の小数の桁で割る
        value: total / 10 ** fractionDigits(currency),
        currency,
        conversion_id: number,
        contents,
        restricted_data_use: 'restrict_optimization',
    });

    function fractionDigits(code) {
        try {
            return new Intl.NumberFormat('en', { style: 'currency', currency: code }).resolvedOptions().maximumFractionDigits;
        } catch {
            return code === 'JPY' ? 0 : 2;
        }
    }
})();
