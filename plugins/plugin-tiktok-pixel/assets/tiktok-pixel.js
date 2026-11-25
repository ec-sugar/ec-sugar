// App「TikTok ピクセル」（ADR 0034、docs/design/tag-apps.md の 6）。
// 核が <script src defer> でこのファイルを読み、設定（data-setting-pixel-id）・画面の種類（data-sugar-page）・
// 購入の完了の値（data-order-number・data-order-total・data-order-currency・data-order-items）を data- の印で渡す。
// 核が差し込むのは、店がこの App を使い、同意の帯を出す店ではお客さんが「広告」に同意し、ふつうか購入の完了の画面のときだけ。
// 同意の帯は既定では出さない。帯を出さない店では同意を待たずに差し込むので、読み込まれたことを「同意あり」として扱う（店が帯を出さないと決めたため）。
// 個人情報の画面では読まれない。名前・メール・住所・電話は核から渡らず、照合用の情報（identify）も送らない。
// Trusted Types の既定の関所（default policy）は核が作る（この App では作らない）。events.js の src は、その関所が
// マニフェストの script-src（https://analytics.tiktok.com）に合うかを見て通す。
// events.js と 2 段目の analytics.tiktok.com/i18n/pixel/static/main.<文字>.js（2026-10-29 に読んだもの）は関所を作らず、
// 続くスクリプトも文字の URL で src に入れる（＝核の既定の関所にまかせて動く）。IPv6 の補い（analytics-ipv6.tiktokw.us）は
// 宛先に書いていないので止まる
(() => {
    const me = document.currentScript;

    if (!me) {
        return;
    }

    const data = me.dataset;
    const pixelId = data.settingPixelId || '';

    // 形は核がサーバーで確かめ済み（英大文字と数字 15〜25 文字）。念のためここでも合わなければ何もしない
    if (!/^[A-Z0-9]{15,25}$/.test(pixelId)) {
        return;
    }

    // ベースコードの前半（キューの ttq を作る）を、画面に直に書かずにここで行う。
    // events.js はキューの名前を window.TiktokAnalyticsObject から、読み込みの時刻や設定を ttq._i・_t・_o から読む
    const name = 'ttq';

    if (!window[name]) {
        window.TiktokAnalyticsObject = name;

        const ttq = [];
        ttq.methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie', 'holdConsent', 'revokeConsent', 'grantConsent'];
        ttq.setAndDefer = (target, method) => {
            target[method] = (...args) => {
                target.push([method, ...args]);
            };
        };
        ttq.methods.forEach((method) => ttq.setAndDefer(ttq, method));
        ttq.instance = (id) => {
            const instance = ttq._i[id] || [];
            ttq.methods.forEach((method) => ttq.setAndDefer(instance, method));

            return instance;
        };
        ttq.load = (id, options) => {
            const url = 'https://analytics.tiktok.com/i18n/pixel/events.js';
            ttq._i = ttq._i || {};
            ttq._i[id] = [];
            ttq._i[id]._u = url;
            ttq._t = ttq._t || {};
            ttq._t[id] = Date.now();
            ttq._o = ttq._o || {};
            ttq._o[id] = options || {};

            const script = document.createElement('script');
            script.type = 'text/javascript';
            script.async = true;

            try {
                // Trusted Types の下では、核の既定の関所が宣言した宛先（script-src）の URL だけを通す
                script.src = url + '?sdkid=' + encodeURIComponent(id) + '&lib=' + name;
            } catch {
                return; // 関所が通さないときは読み込まない（止まる側に倒す）
            }

            document.head.appendChild(script);
        };
        window[name] = ttq;
    }

    const ttq = window[name];

    // 核は、同意の帯を出す店では同意の後にしかこのファイルを読まない（出さない店では読み込みを同意ありとして扱う）ので、TikTok の同意の呼び出しに「同意あり」を伝える
    // （grantConsent は公開のヘルプに書き方が無く、ピクセルのスクリプトから読み取ったもの）
    ttq.grantConsent();
    ttq.load(pixelId);
    ttq.page();

    const order = purchase(data, 'sugar-tiktok-pixel:purchase:');

    if (!order) {
        return;
    }

    // 購入の完了: 注文の番号（重複を除く event_id として）・金額・通貨・品の ID と数だけを送る
    ttq.track('Purchase', {
        contents: order.items.map((item) => ({ content_id: item.id, content_type: 'product', quantity: item.quantity })),
        value: order.value,
        currency: order.currency,
    }, { event_id: order.number });

    // 購入の完了の画面の値（無い・送り済みなら null）。金額は最小通貨単位の整数で来るので、通貨の小数の桁で割ってタグの形にする。
    // 同じタブで完了の画面を読み直したときに、二度送らない（TikTok のブラウザの送信は番号だけでは重複が除かれない）
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

        return { number: values.orderNumber, value: minor / 10 ** digits, currency, items };
    }
})();
