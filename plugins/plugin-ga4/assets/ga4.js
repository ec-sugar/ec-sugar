// App「Google アナリティクス 4」（ADR 0034、docs/design/tag-apps.md の 1）。
// 核が <script src defer> でこのファイルを読み、測定 ID（data-setting-measurement-id）・画面の種類（data-sugar-page）・
// 購入の完了の値（data-order-number・data-order-total・data-order-currency・data-order-items）を data- の印で渡す。
// 核が差し込むのは、店がこの App を使い、同意の帯を出す店ではお客さんが「アクセスの解析」に同意し、ふつうか購入の完了の画面のときだけ。
// 同意の帯は既定では出さない。帯を出さない店では同意を待たずに差し込むので、読み込まれたことを「同意あり」として扱う（店が帯を出さないと決めたため）。
// 個人情報の画面では読まれない。名前・メール・住所・電話は核から渡らない。
// Trusted Types の既定の関所（default policy）はここで作らない（核が外のどのスクリプトより先に作り、宣言した宛先だけを通す）
(() => {
    const me = document.currentScript;

    if (!me) {
        return;
    }

    const data = me.dataset;
    const id = data.settingMeasurementId || '';

    // 核が宣言どおりに確かめた値だが、念のため形の違う ID では何もしない
    if (!/^G-[A-Z0-9]{4,20}$/.test(id)) {
        return;
    }

    // 同意（Google の同意モード）: 核は、同意の帯を出す店では「アクセスの解析」に同意したお客さんにだけ、このファイルを読ませる。
    // 広告の保存（ad_*）は、Google 広告の App が同じページにいて、広告にも同意があるときだけ、そちらが「同意あり」にする
    googleTag({ analytics_storage: 'granted' }, id, () => {
        // Google シグナルと広告のパーソナライズは使わない（CSP には広告の機能を使わない形の宛先だけを書いている）
        window.gtag('config', id, { allow_google_signals: false, allow_ad_personalization_signals: false });

        const order = purchase(data);

        if (order) {
            // 購入の計測は、注文の番号・金額・通貨・品の ID と数だけ（tag-apps.md の 1 の 5）
            window.gtag('event', 'purchase', {
                send_to: id,
                transaction_id: order.number,
                value: order.value,
                currency: order.currency,
                items: order.items.map((item) => ({ item_id: item.id, quantity: item.quantity })),
            });
        }
    });

    // ---- ここから下は Google のタグの App で同じ部品。変えるときは ga4.js と google-ads.js の両方を同じに直す ----

    // gtag.js を GA4 と Google 広告の App で分け合う。同じページで両方使われても、gtag.js の読み込みは 1 回だけ、
    // 同意の宣言（consent default）は設定（config）より先に 1 回だけにする。
    // 差し込まれた App のスクリプト（defer）は DOMContentLoaded の前に順に動くので、その後に集まった分をまとめて始める
    function googleTag(consent, loaderId, step) {
        window.dataLayer = window.dataLayer || [];

        if (typeof window.gtag !== 'function') {
            // 公式のスニペットと同じ形（gtag.js は arguments のまま受け取る）
            window.gtag = function gtag() {
                window.dataLayer.push(arguments);
            };
        }

        const shared = (window.sugarGoogleTag = window.sugarGoogleTag || { consent: {}, steps: [], loaderId: null, started: false });

        if (shared.started) {
            // 始まった後に来たとき（ふつうは無い）: 自分の同意を足して、すぐ設定する
            window.gtag('consent', 'update', consent);
            step();

            return;
        }

        Object.assign(shared.consent, consent);
        shared.steps.push(step);
        shared.loaderId = shared.loaderId || loaderId;

        if (shared.steps.length === 1) {
            const go = () => startGoogleTag(shared);

            if (document.readyState === 'complete') {
                queueMicrotask(go);
            } else {
                document.addEventListener('DOMContentLoaded', go, { once: true });
                window.addEventListener('load', go, { once: true });
            }
        }
    }

    function startGoogleTag(shared) {
        if (shared.started) {
            return;
        }

        shared.started = true;

        // 同意のある種類だけ「同意あり」、ほかは「同意なし」で始める（基本の同意モード: 同意の前は読み込まない）
        const denied = 'denied';
        window.gtag('consent', 'default', {
            ad_storage: denied,
            ad_user_data: denied,
            ad_personalization: denied,
            analytics_storage: denied,
            ...shared.consent,
        });
        window.gtag('js', new Date());
        shared.steps.forEach((step) => step());

        const script = document.createElement('script');
        script.async = true;

        try {
            // Trusted Types の下では、核の既定の関所が宣言した宛先（script-src）の URL だけを通す
            script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(shared.loaderId);
        } catch {
            return; // 関所が通さないときは読み込まない（止まる側に倒す）
        }

        document.head.appendChild(script);
    }

    // 購入の完了の画面の値（無ければ null）。金額は最小通貨単位の整数で来るので、通貨の小数の桁で割って Google の形にする
    function purchase(values) {
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
