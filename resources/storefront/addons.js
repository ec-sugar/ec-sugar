// オプション（名入れ・ギフト包装など）を選ぶ部品（ADR 0036）。テーマではなく核が持つ JavaScript（ADR 0020）。
// 核の Blade の部品（<x-storefront::addon-fields>）は JavaScript が無くても動く欄を出す。ここは合計を出すだけ:
// - 外枠 [data-addon-fields] の data-addons（JSON）に、価格（種類ごと）とオプションの額がある
// - 選んだ種類（フォームの name="variant_id"）の価格に、選んだオプションの額を足して数（name="quantity"）を掛け、
//   ページの [data-addon-total] に「2 点で ¥5,400」の形で出す（カートの行の小計と同じ計算。形は money.js。サーバーの ProductView::quantityTotalLabel と同じ形）
// - 額は案内だけ。カートに入れるときは、サーバーが今の定義と照らして決め直す
//
// 店頭の CSP（script は自分のオリジンだけ、Trusted Types）のまま動くように、HTML の文字列は差し込まない。textContent だけに入れる。

// 合計が変わった時の動き（強さはテーマが決め、動きを減らす設定では動かさない）
import { setText } from './motion.js';
// 金額の見せ方はサーバーと同じ 1 か所の形（「2 点で ¥5,400」）
import { formatQuantityTotal } from './money.js';

function readData(fields) {
    try {
        const data = JSON.parse(fields.dataset.addons ?? '');

        return Number.isInteger(data?.base) && Array.isArray(data?.addons) && data.prices && typeof data.prices === 'object' ? data : null;
    } catch {
        return null;
    }
}

function wireFields(fields) {
    const data = readData(fields);
    const form = fields.closest('form');

    if (!data || !form) {
        return;
    }

    const amountOf = (addon) => {
        const input = form.elements.namedItem(`addons[${addon.id}]`);

        if (!input) {
            return 0;
        }

        if (addon.kind === 'choice') {
            return addon.choices.find((choice) => choice.value === input.value)?.amount ?? 0;
        }

        if (addon.kind === 'toggle') {
            return input.checked ? addon.amount : 0;
        }

        return input.value.trim() !== '' ? addon.amount : 0;
    };

    // 読み込んだ直後の 1 回目は動かさない（選び直した・数を変えた時だけ動かす）
    let ready = false;

    function render() {
        const variant = form.elements.namedItem('variant_id');
        const price = variant && Number.isInteger(data.prices[variant.value]) ? data.prices[variant.value] : data.base;
        const unit = data.addons.reduce((sum, addon) => sum + (Number.isInteger(addon.amount) ? amountOf(addon) : 0), price);
        // 数は打ち込みの途中で空や範囲の外になることがある。1 より小さければ 1、上限（max）より大きければ上限で数える
        const field = form.elements.namedItem('quantity');
        const max = Number.parseInt(field?.max ?? '', 10);
        const typed = Number.parseInt(field?.value ?? '', 10) || 1;
        const quantity = Math.max(1, Number.isInteger(max) && max > 0 ? Math.min(typed, max) : typed);

        for (const target of document.querySelectorAll('[data-addon-total]')) {
            const text = formatQuantityTotal(unit * quantity, quantity, data.currency);
            ready ? setText(target, text) : (target.textContent = text);
        }
    }

    // 種類を選び直すと、種類の部品（variants.js）が name="variant_id" の select に change を送る。
    // 数の「− 1 +」（cart.js の [data-stepper]）は、ボタンで数を動かしたときに数の欄へ input を送る
    form.addEventListener('change', render);
    form.addEventListener('input', render);

    const summary = fields.querySelector('[data-addon-summary]');
    summary && (summary.hidden = false);
    render();
    ready = true;
}

for (const fields of document.querySelectorAll('[data-addon-fields]')) {
    wireFields(fields);
}
