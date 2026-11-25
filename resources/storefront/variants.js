// 種類（色・サイズ）を選ぶ部品（ADR 0036）。テーマではなく核が持つ JavaScript（ADR 0020）。
// 核の Blade の部品（<x-storefront::variant-picker>）が出す select（JavaScript が無くても選べる形）を、軸ごとの選び方に作り替える。
// - 外枠 [data-variant-picker] の data-variants（JSON）に軸と種類がある。外枠に data-variant-style="select" があれば軸ごとに select、無ければボタン
// - 選んだ種類を元の select（name="variant_id"）に入れて、フォームはそのまま送る（入れ替えたら select に change を送る）
// - ページの [data-variant-price]（価格）・[data-variant-availability]（在庫の知らせ。売り切れなら data-sold-out）・img[data-variant-photo]（写真）と、
//   フォームの数の上限と「カートに入れる」を、選んだ種類に合わせる
// - 無い組み合わせのボタンには data-unavailable、売り切れの組み合わせには data-sold-out を付ける（見た目はテーマが印から CSS で決める）
//
// 店頭の CSP（script は自分のオリジンだけ、Trusted Types）のまま動くように、HTML の文字列は差し込まない。
// 要素を作って textContent と決まった属性だけに値を入れる。

// 価格が変わった・写真を入れ替えた時の動き（強さはテーマが決め、動きを減らす設定では動かさない）
import { photoChanged, setText } from './motion.js';

const SOLD_OUT = 'sold_out';

// 自分のオリジンの道筋だけを許す（// で始まる別ホストや javascript: を入れない）
function safePath(value) {
    return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : null;
}

// srcset は「道筋 幅w」をカンマでつないだもの。どれも自分のオリジンの道筋のときだけ
function safeSrcset(value) {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    return value.split(',').every((part) => safePath(part.trim().split(/\s+/)[0]) !== null) ? value : null;
}

function readData(picker) {
    try {
        const data = JSON.parse(picker.dataset.variants ?? '');

        return Array.isArray(data?.options) && Array.isArray(data?.variants) && data.variants.length > 0 ? data : null;
    } catch {
        return null;
    }
}

function wirePicker(picker) {
    const data = readData(picker);
    const select = picker.querySelector('select[data-variant-select]');

    if (!data || !select) {
        return;
    }

    const form = picker.closest('form');
    const byId = new Map(data.variants.map((variant) => [variant.id, variant]));
    const start = byId.get(select.value) ?? byId.get(data.selected) ?? data.variants[0];
    let chosen = [...start.values];

    const find = (values) => data.variants.find((variant) => variant.values.length === values.length && variant.values.every((id, i) => id === values[i]));

    // ある軸の値を選んだときの組み合わせの具合（無い・売り切れ・選べる）
    const stateOf = (axis, valueId) => {
        const values = [...chosen];
        values[axis] = valueId;
        const variant = find(values);

        return !variant ? 'none' : variant.availability === SOLD_OUT ? SOLD_OUT : 'ok';
    };

    const asSelect = picker.dataset.variantStyle === 'select';
    const axes = document.createElement('div');
    axes.setAttribute('data-variant-axes', '');
    const controls = [];

    data.options.forEach((option, axis) => {
        const fieldset = document.createElement('fieldset');
        fieldset.setAttribute('data-variant-axis', '');
        const legend = document.createElement('legend');
        const name = document.createElement('span');
        name.setAttribute('data-variant-axis-name', '');
        name.textContent = option.name;
        const current = document.createElement('span');
        current.setAttribute('data-variant-axis-value', '');
        legend.append(name, current);
        fieldset.append(legend);

        if (asSelect) {
            const control = document.createElement('select');
            control.setAttribute('data-variant-axis-select', '');
            control.setAttribute('aria-label', option.name);

            for (const value of option.values) {
                const item = document.createElement('option');
                item.value = value.id;
                item.textContent = value.label;
                control.append(item);
            }

            control.addEventListener('change', () => choose(axis, control.value));
            fieldset.append(control);
            controls.push({ option, current, select: control, buttons: [] });
        } else {
            const list = document.createElement('div');
            list.setAttribute('data-variant-values', '');
            const buttons = option.values.map((value) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = value.label;
                button.setAttribute('data-variant-value', value.id);
                button.addEventListener('click', () => choose(axis, value.id));
                list.append(button);

                return button;
            });
            fieldset.append(list);
            controls.push({ option, current, select: null, buttons });
        }

        axes.append(fieldset);
    });

    // 軸の値を選ぶ。無い組み合わせになったら、その値を含む種類のうち、在庫があってほかの軸がなるべく同じものへ移る。
    // 売り切れの組み合わせはそのまま選び、売り切れと示す（ほかの軸をこちらで勝手に変えない）
    function choose(axis, valueId) {
        const values = [...chosen];
        values[axis] = valueId;

        if (!find(values)) {
            const score = (variant) => variant.values.filter((id, i) => id === values[i]).length + (variant.availability === SOLD_OUT ? 0 : 10);
            const candidates = data.variants.filter((variant) => variant.values[axis] === valueId).sort((a, b) => score(b) - score(a));

            if (candidates.length === 0) {
                return;
            }

            chosen = [...candidates[0].values];
        } else {
            chosen = values;
        }

        render();
    }

    const photo = document.querySelector('img[data-variant-photo]');
    const original = photo ? { src: photo.getAttribute('src'), srcset: photo.getAttribute('srcset'), alt: photo.getAttribute('alt'), width: photo.getAttribute('width'), height: photo.getAttribute('height') } : null;
    const submit = form?.querySelector('[type="submit"]') ?? null;
    const submitText = submit?.textContent ?? '';
    const quantity = form?.querySelector('input[name="quantity"]') ?? null;
    // 読み込んだ直後の 1 回目は動かさない（選び直した時だけ動かす）
    let ready = false;

    function setPhoto(next) {
        if (!photo) {
            return;
        }

        const src = next ? safePath(next.src) : null;
        const source = src ? { src, srcset: safeSrcset(next.srcset), alt: next.alt, width: next.width, height: next.height } : original;
        const changed = photo.getAttribute('src') !== source.src;

        for (const name of ['src', 'srcset', 'alt', 'width', 'height']) {
            const value = source[name];
            value === null || value === undefined ? photo.removeAttribute(name) : photo.setAttribute(name, String(value));
        }

        // 写真が替わった時だけ、新しい写真を薄い所から出す
        changed && ready && photoChanged(photo);
    }

    function render() {
        const variant = find(chosen);

        if (!variant) {
            return;
        }

        const soldOut = variant.availability === SOLD_OUT;
        const changed = select.value !== variant.id;
        select.value = variant.id;

        // 選び直したことをフォームに知らせる（オプションの部品 addons.js が合計を出し直す）
        if (changed) {
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        controls.forEach((control, axis) => {
            const value = control.option.values.find((item) => item.id === chosen[axis]);
            control.current.textContent = value?.label ?? '';

            if (control.select) {
                control.select.value = chosen[axis];

                for (const item of control.select.options) {
                    const state = stateOf(axis, item.value);
                    const label = control.option.values.find((v) => v.id === item.value)?.label ?? '';
                    item.textContent = state === 'none' ? `${label}（なし）` : state === SOLD_OUT ? `${label}（売り切れ）` : label;
                }
            }

            for (const button of control.buttons) {
                const id = button.getAttribute('data-variant-value');
                const state = stateOf(axis, id);
                button.setAttribute('aria-pressed', String(id === chosen[axis]));
                button.toggleAttribute('data-unavailable', state === 'none');
                button.toggleAttribute('data-sold-out', state === SOLD_OUT);

                if (state === 'ok') {
                    button.removeAttribute('aria-label');
                } else {
                    button.setAttribute('aria-label', `${button.textContent}（${state === SOLD_OUT ? '売り切れ' : 'この組み合わせはありません'}）`);
                }
            }
        });

        for (const price of document.querySelectorAll('[data-variant-price]')) {
            ready ? setText(price, variant.price) : (price.textContent = variant.price);
        }

        for (const availability of document.querySelectorAll('[data-variant-availability]')) {
            availability.textContent = variant.availability_label;
            availability.toggleAttribute('data-sold-out', soldOut);
        }

        setPhoto(variant.photo);

        if (quantity && variant.max > 0) {
            quantity.max = String(variant.max);
            quantity.value = String(Math.min(variant.max, Math.max(1, Number.parseInt(quantity.value, 10) || 1)));
            // 「− 数 +」のボタンの押せる・押せないを合わせ直す（cart.js の [data-stepper]）
            quantity.dispatchEvent(new Event('input', { bubbles: true }));
        }

        if (submit) {
            submit.disabled = soldOut;
            submit.textContent = soldOut ? '売り切れ' : submitText;
        }
    }

    const fallback = picker.querySelector('[data-variant-fallback]');
    fallback && (fallback.hidden = true);
    picker.append(axes);
    render();
    ready = true;
}

for (const picker of document.querySelectorAll('[data-variant-picker]')) {
    wirePicker(picker);
}
