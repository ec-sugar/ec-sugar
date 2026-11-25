// App「Microsoft 広告 UET」（ADR 0034、docs/design/tag-apps.md の 9）。
// 核が <script src defer> でこのファイルを読み、設定（data-setting-tag-id）・画面の種類（data-sugar-page）・
// 購入の完了の値（data-order-number・data-order-total・data-order-currency・data-order-items）を data- の印で渡す。
// 核が差し込むのは、店がこの App を使い、同意の帯を出す店ではお客さんが「広告」に同意し、ふつうか購入の完了の画面のときだけ。
// 同意の帯は既定では出さない。帯を出さない店では同意を待たずに差し込むので、読み込まれたことを「同意あり」として扱う（店が帯を出さないと決めたため）。
// 個人情報の画面では読まれない。名前・メール・住所・電話は核から渡らず、照合用の情報（拡張コンバージョンの pid）も送らない。
// Trusted Types の既定の関所（default policy）は核が作る（この App では作らない）。bat.js の src は、その関所が
// マニフェストの script-src（https://bat.bing.com）に合うかを見て通す。
// bat.js（2026-10-29 に読んだもの）は関所を作らず、2 段目の bat.bing.com/p/action/<タグ ID>.js を文字の URL で src に入れる
// （＝核の既定の関所にまかせて動く）。UET のタグの設定で Clarity をつないでいると clarity.microsoft.com も読もうとするが、
// 宛先に書いていないので止まる（Clarity は別の App で、解析の同意の後に入れる）
(() => {
    const me = document.currentScript;

    if (!me) {
        return;
    }

    const data = me.dataset;
    const tagId = data.settingTagId || '';

    // 形は核がサーバーで確かめ済み（数字 5〜12 桁）。念のためここでも合わなければ何もしない
    if (!/^[0-9]{5,12}$/.test(tagId)) {
        return;
    }

    // 公式のスニペットのキュー（uetq）。bat.js が読めたら UET に入れ替わり、それまでに積んだ分を受け取る
    window.uetq = window.uetq || [];

    // 同意（Microsoft の同意モード）: 核は、同意の帯を出す店では「広告」に同意したお客さんにだけ、このファイルを読ませる（公式の言う基本の形）
    window.uetq.push('consent', 'default', { ad_storage: 'granted' });

    const script = document.createElement('script');
    script.async = true;
    script.onload = () => {
        if (typeof window.UET !== 'function') {
            return;
        }

        // 公式のスニペットの後半。画面は毎回サーバーが描くので、SPA の自動の計測（enableAutoSpaTracking）は使わない
        const queue = window.uetq;
        window.uetq = new window.UET({ ti: tagId, q: queue });
        window.uetq.push('pageLoad');

        // 送り済みの印は、bat.js が読めた後に付ける（読めなかったときは、読み直しで送り直せる）
        const order = purchase(data, 'sugar-microsoft-uet:purchase:');

        if (order) {
            // 購入の完了: 注文の番号・金額・通貨・品の ID と数だけを送る（Microsoft は取引の番号で重複を除く）
            window.uetq.push('event', 'purchase', {
                transaction_id: order.number,
                ecomm_pagetype: 'purchase',
                ecomm_prodid: order.items.map((item) => item.id),
                ecomm_totalvalue: order.value,
                revenue_value: order.value,
                currency: order.currency,
                items: order.items.map((item) => ({ id: item.id, quantity: item.quantity })),
            });
        }
    };

    try {
        // Trusted Types の下では、核の既定の関所が宣言した宛先（script-src）の URL だけを通す（公式の // は https に直す）
        script.src = 'https://bat.bing.com/bat.js';
    } catch {
        return; // 関所が通さないときは読み込まない（止まる側に倒す）
    }

    document.head.appendChild(script);

    // 購入の完了の画面の値（無い・送り済みなら null）。金額は最小通貨単位の整数で来るので、通貨の小数の桁で割ってタグの形にする。
    // 同じタブで完了の画面を読み直したときに、二度送らない
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
