// App「Meta ピクセル」（ADR 0034、docs/design/tag-apps.md の 3）。
// 核が <script src defer> でこのファイルを読み、設定（data-setting-pixel-id）・画面の種類（data-sugar-page）・
// 購入の完了の値（data-order-number・data-order-total・data-order-currency・data-order-items）を data- の印で渡す。
// 核が差し込むのは、店がこの App を使い、同意の帯を出す店ではお客さんが「広告」に同意し、ふつうか購入の完了の画面のときだけ。
// 同意の帯は既定では出さない。帯を出さない店では同意を待たずに差し込むので、読み込まれたことを「同意あり」として扱う（店が帯を出さないと決めたため）。
// 個人情報の画面では読まれない。名前・メール・住所・電話は核から渡らず、照合用の情報（詳細マッチング）も送らない。
// Trusted Types の既定の関所（default policy）は核が作る（この App では作らない）。fbevents.js の src は、その関所が
// マニフェストの script-src（https://connect.facebook.net）に合うかを見て通す。
// fbevents.js（2026-10-29 に読んだ 2.9.429）は自前の関所「connect.facebook.net/fbevents」を作ろうとし、作れなければ
// 例外を握りつぶして文字の URL のまま src に入れる（＝核の既定の関所にまかせて動く）。2 段目は connect.facebook.net の
// signals/config/ と signals/plugins/。イベントの設定ツールの iwl.js（www.facebook.com）は宛先に書いていないので読まれない。
(() => {
    const me = document.currentScript;

    if (!me) {
        return;
    }

    const data = me.dataset;
    const pixelId = data.settingPixelId || '';

    // 形は核がサーバーで確かめ済み（数字 10〜20 桁）。念のためここでも合わなければ何もしない
    if (!/^[0-9]{10,20}$/.test(pixelId)) {
        return;
    }

    // 公式のスニペットの前半（キューの関数 fbq を作り、fbevents.js を async で読む）を、画面に直に書かずにここで行う
    if (!window.fbq) {
        const fbq = function (...args) {
            if (fbq.callMethod) {
                fbq.callMethod(...args);
            } else {
                fbq.queue.push(args);
            }
        };

        if (!window._fbq) {
            window._fbq = fbq;
        }

        fbq.push = fbq;
        fbq.loaded = true;
        fbq.version = '2.0';
        fbq.queue = [];
        window.fbq = fbq;

        const script = document.createElement('script');
        script.async = true;
        script.src = 'https://connect.facebook.net/en_US/fbevents.js';
        document.head.appendChild(script);
    }

    const fbq = window.fbq;

    // 核は、同意の帯を出す店では同意の後にしかこのファイルを読まない（出さない店では読み込みを同意ありとして扱う）ので、Meta の同意の API に「同意あり」を伝える
    fbq('consent', 'grant');
    // 自動の設定（ボタンのクリックやページの中身から自動でイベントを作る機能）を切る。送る中身はこのファイルで決めたものだけにする
    fbq('set', 'autoConfig', false, pixelId);
    // 照合用の情報（3 つめの引数の詳細マッチング）は渡さない
    fbq('init', pixelId);
    fbq('track', 'PageView');

    if (data.sugarPage !== 'purchase' || !data.orderNumber) {
        return;
    }

    // 購入の完了: 注文の番号（重複を除く eventID として）・金額・通貨・品の ID と数だけを送る
    const number = data.orderNumber;
    const currency = (data.orderCurrency || '').toUpperCase();
    const total = Number(data.orderTotal);

    if (!/^[A-Z]{3}$/.test(currency) || !Number.isSafeInteger(total) || total < 0) {
        return;
    }

    // 同じタブで完了の画面を読み直したときに、二度送らない（Meta のブラウザの送信は番号だけでは重複が除かれない）
    const sentKey = 'sugar-meta-pixel:purchase:' + number;

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
        .map((item) => ({ id: item.id, quantity: item.quantity }));

    fbq('track', 'Purchase', {
        // 核は最小通貨単位の整数で渡す（JPY なら円）。Meta は通常の単位で受けるので、通貨の小数の桁で割る
        value: total / 10 ** fractionDigits(currency),
        currency,
        contents,
        content_type: 'product',
    }, { eventID: number });

    function fractionDigits(code) {
        try {
            return new Intl.NumberFormat('en', { style: 'currency', currency: code }).resolvedOptions().maximumFractionDigits;
        } catch {
            return code === 'JPY' ? 0 : 2;
        }
    }
})();
