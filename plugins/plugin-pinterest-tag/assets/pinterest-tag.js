// App「Pinterest タグ」（ADR 0034、docs/design/tag-apps.md の 10）。
// 核が <script src defer> でこのファイルを読み、設定（data-setting-tag-id）・画面の種類（data-sugar-page）・
// 購入の完了の値（data-order-number・data-order-total・data-order-currency・data-order-items）を data- の印で渡す。
// 核が差し込むのは、店がこの App を使い、同意の帯を出す店ではお客さんが「広告」に同意し、ふつうか購入の完了の画面のときだけ。
// 同意の帯は既定では出さない。帯を出さない店では同意を待たずに差し込むので、読み込まれたことを「同意あり」として扱う（店が帯を出さないと決めたため）。
// 個人情報の画面では読まれない。名前・メール・住所・電話は核から渡らず、照合用の情報（load の em）も送らない。
// Trusted Types の既定の関所（default policy）は核が作る（この App では作らない）。core.js の src は、その関所が
// マニフェストの script-src（https://s.pinimg.com）に合うかを見て通す。
// core.js（2026-10-29 に読んだ c61fa7c9）は関所を作らず、2 段目の s.pinimg.com/ct/lib/main.<8 桁>.js を文字の URL で src に入れる
// （＝核の既定の関所にまかせて動く）。main は Chrome で ct.pinterest.com の token_create.js と iframe の ct.html も読もうとするが、
// 宛先に書いていないので止まる（Cookie の受け渡しの補いが動かないだけで、計測の送信は ct.pinterest.com へ届く）。
// ページの構造化データを拾う所は、写しの要素へ innerHTML を入れるので関所で止まり、Pinterest の中で握りつぶされる
(() => {
    const me = document.currentScript;

    if (!me) {
        return;
    }

    const data = me.dataset;
    const tagId = data.settingTagId || '';

    // 形は核がサーバーで確かめ済み（数字 9〜16 桁）。念のためここでも合わなければ何もしない
    if (!/^[0-9]{9,16}$/.test(tagId)) {
        return;
    }

    // 公式のスニペットの前半（キューの関数 pintrk を作り、core.js を async で読む）を、画面に直に書かずにここで行う
    if (!window.pintrk) {
        const pintrk = function (...args) {
            pintrk.queue.push(args);
        };

        pintrk.queue = [];
        pintrk.version = '3.0';
        window.pintrk = pintrk;

        const script = document.createElement('script');
        script.async = true;

        try {
            // Trusted Types の下では、核の既定の関所が宣言した宛先（script-src）の URL だけを通す
            script.src = 'https://s.pinimg.com/ct/core.js';
        } catch {
            return; // 関所が通さないときは読み込まない（止まる側に倒す）
        }

        document.head.appendChild(script);
    }

    const pintrk = window.pintrk;

    // 核は、同意の帯を出す店では同意の後にしかこのファイルを読まない（出さない店では読み込みを同意ありとして扱う）。Pinterest には公式の同意の API が無いので、読み込むことが「同意あり」にあたる。
    // 照合用の情報（3 つめの引数の em）は渡さない
    pintrk('load', tagId);
    pintrk('page');

    const order = purchase(data, 'sugar-pinterest-tag:purchase:');

    if (!order) {
        return;
    }

    // 購入の完了: 注文の番号（order_id と重複を除く event_id）・金額・通貨・品の ID と数だけを送る
    pintrk('track', 'checkout', {
        event_id: order.number,
        order_id: order.number,
        value: order.value,
        currency: order.currency,
        order_quantity: order.items.reduce((sum, item) => sum + item.quantity, 0),
        line_items: order.items.map((item) => ({ product_id: item.id, product_quantity: item.quantity })),
    });

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
