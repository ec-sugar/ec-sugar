// 店頭の部品（カート: 注文の入力: ）。テーマではなく核が持つ JavaScript（ADR 0020）。
// テーマは data- の印を付けた要素を置くだけ。この部品が無くても（JS が動かなくても）フォームで同じことができる。
//
// 店頭の CSP（script は自分のオリジンだけ、Trusted Types）のまま動くように、HTML の文字列は差し込まない。
// 要素を作って textContent と決まった属性だけに値を入れる（サーバーから来た文字がスクリプトとして動く余地を作らない）。

// 同意の帯と核の画面の見た目（ADR 0034）。テーマは $storefrontScript をこの入口のまま読むので、ここから一緒に読ませる
import './consent.js';
// 種類（色・サイズ）を選ぶ部品（ADR 0036）。商品の詳細の [data-variant-picker] があるときだけ動く
import './variants.js';
// オプション（名入れ・ギフト包装など）の合計を出す部品（ADR 0036）。商品の詳細の [data-addon-fields] があるときだけ動く
import './addons.js';
// テーマの下書きのプレビューを管理画面の編集の画面の枠の中で動かす部品（ADR 0039 区切り 2）。[data-sugar-editor] があるときだけ動く
import './editor.js';
// 会員のパスキー（ADR 0044 の 3）。[data-passkey] のフォームがあるときだけ動く
import './passkeys.js';
// 贈り物の受け取りのリンクを「写す」（ADR 0055）。[data-gift-share] があるときだけ動く
import './gift.js';
// 動く QR コード（App「デジタルチケット」の入場の QR）。[data-live-qr] があるときだけ動く
import './live-qr.js';
// App の「押す・戻す」のボタン（ADR 0071。お気に入りのハートなど）。[data-sugar-toggle] のフォームがあるときだけ動く
import './toggles.js';
// 核の汎用の区画と自由なページの既定の画面の見た目（ADR 0039 区切り 3）。テーマは $storefrontScript をこの入口のまま読むので、ここから一緒に読ませる
import './sections.css';
// 引き出しを横から滑らせる動き（Motion の小さい版。ブラウザの Web Animations＝要素を動かす標準の仕組み だけを使い、eval も HTML の差し込みもしない。ADR 0037）
import { animate } from 'motion/mini';
// ショップの動き。強さはテーマの theme.json の motion、動きを減らす設定では動かさない。カートの数と値が変わった時に使う
import { motionLevel, pop, setText, settle } from './motion.js';

const JSON_HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

function setCount(count) {
    for (const badge of document.querySelectorAll('[data-cart-count]')) {
        const changed = badge.textContent.trim() !== String(count);
        badge.textContent = String(count);
        badge.classList.toggle('hidden', count === 0);

        // 数が変わった時だけ、一度ふくらませて知らせる
        if (changed && count > 0) {
            pop(badge);
        }
    }

    for (const link of document.querySelectorAll('[data-cart-link]')) {
        link.setAttribute('aria-label', `カート（${count} 点）`);
    }
}

// 自分のオリジンの道筋だけを許す（// で始まる別ホストや javascript: を入れない）
function safePath(value) {
    return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : null;
}

async function send(form, submitter) {
    const body = new FormData(form);

    if (submitter?.name) {
        body.set(submitter.name, submitter.value);
    }

    let response;

    try {
        response = await fetch(form.action, { method: 'POST', body, headers: JSON_HEADERS, credentials: 'same-origin', redirect: 'error' });
    } catch {
        return { ok: false, message: '通信できませんでした。インターネットの接続を確認して、もう一度お試しください。', cart: null };
    }

    const data = await response.json().catch(() => null);

    if (response.status === 419) {
        return { ok: false, message: '画面の有効期限が切れました。ページを再読み込みしてから、もう一度お試しください。', cart: null };
    }

    if (response.status === 429) {
        return { ok: false, message: '操作が多すぎます。少し時間をおいてから、もう一度お試しください。', cart: null };
    }

    return { ok: response.ok, message: typeof data?.message === 'string' ? data.message : '処理できませんでした。もう一度お試しください。', cart: data?.cart ?? null };
}

// 横から出るカート（テーマの <dialog data-cart-drawer>）を、カートの中身で埋めて開く
function fillDrawer(drawer, cart, message, isError) {
    const list = drawer.querySelector('[data-cart-drawer-lines]');
    const template = drawer.querySelector('template[data-cart-line-template]');
    const status = drawer.querySelector('[data-cart-drawer-message]');
    const subtotal = drawer.querySelector('[data-cart-drawer-subtotal]');

    if (status) {
        status.textContent = message ?? '';
        status.toggleAttribute('data-error', Boolean(isError));
        // カートに入れた知らせ（引き出しの上の行）を、少し下から出して目に留める
        message && settle(status);
    }

    if (cart && list && template) {
        list.replaceChildren(...cart.lines.map((line) => {
            const item = template.content.firstElementChild.cloneNode(true);
            const field = (name) => item.querySelector(`[data-field="${name}"]`);

            field('title') && (field('title').textContent = line.title);
            field('quantity') && (field('quantity').textContent = `${line.quantity} 点`);
            field('total') && (field('total').textContent = line.line_total_label);
            field('notice') && (field('notice').textContent = line.notice ?? '', field('notice').hidden = !line.notice);
            item.toggleAttribute('data-unavailable', !line.purchasable);

            const link = field('link');
            const href = safePath(line.url);

            if (link && href) {
                link.setAttribute('href', href);
            }

            const photo = field('photo');
            const src = safePath(line.photo);

            if (photo && src) {
                photo.setAttribute('src', src);
            } else if (photo) {
                photo.remove();
            }

            return item;
        }));
    }

    if (cart && subtotal) {
        subtotal.textContent = cart.subtotal_label;
    }

    const note = drawer.querySelector('[data-cart-drawer-note]');

    if (note) {
        note.textContent = cart?.free_shipping_note ?? '';
        note.hidden = !cart?.free_shipping_note;
    }

    drawer.toggleAttribute('data-empty', !cart || cart.lines.length === 0);
    openDrawer(drawer);
}

// 引き出しの開け閉めの動き。テーマの引き出しは右に寄せてある（ml-auto）ので、右の外から滑らせて出し、右へ滑らせて閉じる。
// 「視差効果を減らす」（prefers-reduced-motion: reduce）を選んだ人と、強さ none のテーマでは動かさず、すぐ開け閉めする（motion.js が見る）
const SLIDE = { duration: 0.25 };
let sliding = null;

function prefersReducedMotion() {
    return motionLevel() === 'none';
}

function openDrawer(drawer) {
    const closing = drawer.hasAttribute('data-closing');

    if (drawer.open && !closing) {
        return;
    }

    // 閉じる途中にまた開くとき（続けてカートに入れたなど）は、閉じる動きを止めて今の位置から戻す
    sliding?.stop();
    drawer.removeAttribute('data-closing');

    if (!drawer.open) {
        drawer.showModal();
    }

    if (prefersReducedMotion()) {
        drawer.style.removeProperty('transform');

        return;
    }

    sliding = animate(drawer, { transform: [closing ? getComputedStyle(drawer).transform : 'translateX(100%)', 'translateX(0)'] }, { ...SLIDE, ease: 'easeOut' });
}

function closeDrawer(drawer) {
    if (!drawer.open || drawer.hasAttribute('data-closing')) {
        return;
    }

    if (prefersReducedMotion()) {
        drawer.close();

        return;
    }

    drawer.setAttribute('data-closing', '');
    sliding?.stop();
    const animation = animate(drawer, { transform: 'translateX(100%)' }, { ...SLIDE, ease: 'easeIn' });
    sliding = animation;

    animation.finished.then(() => {
        // 途中で開き直したとき（data-closing が外れた・別の動きに替わった）は閉じない
        if (sliding === animation && drawer.hasAttribute('data-closing')) {
            drawer.close();
        }
    });
}

// 「− 1 +」（テーマの [data-stepper]）。input[type=number] の値を 1 つずつ動かす
function wireSteppers(root) {
    for (const stepper of root.querySelectorAll('[data-stepper]')) {
        const input = stepper.querySelector('input');
        const buttons = stepper.querySelectorAll('[data-step]');

        const update = () => {
            const value = Number.parseInt(input.value, 10) || 1;

            for (const button of buttons) {
                const step = Number.parseInt(button.dataset.step, 10);
                button.disabled = step < 0 ? value <= Number(input.min || 1) : value >= Number(input.max || 10);
            }
        };

        for (const button of buttons) {
            button.addEventListener('click', () => {
                const step = Number.parseInt(button.dataset.step, 10);
                const value = (Number.parseInt(input.value, 10) || 1) + step;
                input.value = String(Math.min(Number(input.max || 10), Math.max(Number(input.min || 1), value)));
                // 数が変わったことをフォームに知らせる（オプションの合計（addons.js）が数を掛け直す。押せる・押せないも input で合わせる）
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }

        input.addEventListener('input', update);
        update();
    }
}

function wireAddForms(drawer) {
    // 商品のプレビュー: カートに入れる所は押せなくする（お客さんの画面ではない。下書きの商品はカートに入らない）
    if (document.querySelector('[data-sugar-preview]')) {
        for (const form of document.querySelectorAll('form[data-cart-form]')) {
            form.addEventListener('submit', (event) => event.preventDefault());

            for (const element of form.elements) {
                element.disabled = true;
            }
        }

        return;
    }

    for (const form of document.querySelectorAll('form[data-cart-form]')) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]');
            button && (button.disabled = true);
            const result = await send(form, event.submitter);
            button && (button.disabled = false);

            if (result.cart) {
                setCount(result.cart.count);
            }

            if (drawer) {
                fillDrawer(drawer, result.cart, result.message, !result.ok);
            } else {
                // 置き場所の無いテーマでは、カートのページへ
                window.location.assign(form.dataset.cartPage || '/cart');
            }
        });
    }
}

// カートのページの「− 数 +」と「外す」（テーマの [data-cart-line] の中のフォーム）。読み込み直さずに数と小計を変える
function wireCartPage() {
    const page = document.querySelector('[data-cart-page]');

    if (!page) {
        return;
    }

    const message = page.querySelector('[data-cart-page-message]');

    for (const form of page.querySelectorAll('form[data-cart-line-form]')) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const result = await send(form, event.submitter);

            if (!result.cart || result.cart.lines.length === 0) {
                // 空になった・カートが無いときは、ページを描き直す（空の案内はテーマが持つ）
                window.location.reload();

                return;
            }

            setCount(result.cart.count);
            // 値が変わった所だけ少し動かす（motion.js）
            const set = (selector, text) => {
                const element = page.querySelector(selector);
                setText(element, text);

                return element;
            };
            set('[data-cart-subtotal]', result.cart.subtotal_label);
            set('[data-cart-shipping]', result.cart.shipping_label);
            set('[data-cart-total]', result.cart.total_label);
            set('[data-cart-free-note]', result.cart.free_shipping_note)?.classList.toggle('hidden', !result.cart.free_shipping_note);
            // 送料の添え書き（お届け先で決まる目安・便を分けて送る。ADR 0064）
            set('[data-cart-shipping-note]', result.cart.shipping_note)?.classList.toggle('hidden', !result.cart.shipping_note);
            // 値引き（クーポン・自動の割引。ADR 0054・0065）。対象の商品や数を変えると、値引きが変わる・消える・入れ替わる
            set('[data-cart-discount]', result.cart.discount_label ?? '');
            page.querySelector('[data-cart-discount-row]')?.classList.toggle('hidden', !result.cart.discount_label);
            result.cart.discount_name && set('[data-cart-discount-name]', result.cart.discount_name);
            // 次に届く自動の割引の一言（核の部品 discount-hint。hidden の属性で出し分ける）
            const hint = page.querySelector('[data-cart-discount-hint]');
            setText(hint, result.cart.discount_hint ?? '');
            hint && (hint.hidden = !result.cart.discount_hint);
            // 1 回のご注文の下限に届いていない時の一言（核の部品 minimum-notice。ADR 0109）
            const minimum = page.querySelector('[data-cart-minimum-notice]');
            setText(minimum, result.cart.minimum_notice ?? '');
            minimum && (minimum.hidden = !result.cart.minimum_notice);
            // 付くポイントの一言（核の部品 points-note。ADR 0068）
            const points = page.querySelector('[data-cart-points-note]');
            setText(points, result.cart.points_note ?? '');
            points && (points.hidden = !result.cart.points_note);
            const count = page.querySelector('[data-cart-total-count]');
            count && (count.textContent = String(result.cart.count));

            const ids = new Set(result.cart.lines.map((line) => line.line_id));

            for (const row of page.querySelectorAll('[data-cart-line]')) {
                if (!ids.has(row.dataset.cartLine)) {
                    row.remove();
                }
            }

            for (const line of result.cart.lines) {
                const row = page.querySelector(`[data-cart-line="${CSS.escape(line.line_id)}"]`);

                if (!row) {
                    continue;
                }

                const field = (name) => row.querySelector(`[data-field="${name}"]`);
                setText(field('quantity'), String(line.quantity));
                setText(field('total'), line.line_total_label);
                // 行の知らせ（売り切れ・在庫が減った・価格が変わった・無料の品は 1 点まで）と、注文できない行の印も書き換える。
                // 古いまま残すと、在庫が戻っても「注文できない」に見えるなど、表示と注文できる状態が食い違う。見せ方はテーマが印から決める
                row.toggleAttribute('data-unavailable', !line.purchasable);
                const notice = field('notice');

                if (notice) {
                    notice.textContent = line.notice ?? '';
                    notice.hidden = !line.notice;
                }

                const decrease = row.querySelector('[data-step-button="-1"]');
                const increase = row.querySelector('[data-step-button="1"]');

                if (decrease) {
                    decrease.value = String(line.quantity - 1);
                    decrease.disabled = line.quantity <= 1;
                }

                if (increase) {
                    increase.value = String(line.quantity + 1);
                    increase.disabled = line.quantity >= line.max_quantity;
                }
            }

            if (message) {
                message.textContent = result.message;
                message.toggleAttribute('data-error', !result.ok);
                message.hidden = false;
            }
        });
    }
}

function wireDrawer(drawer) {
    if (!drawer) {
        return;
    }

    for (const close of drawer.querySelectorAll('[data-cart-drawer-close]')) {
        close.addEventListener('click', () => closeDrawer(drawer));
    }

    // 外側（背景）を押しても閉じる
    drawer.addEventListener('click', (event) => {
        if (event.target === drawer) {
            closeDrawer(drawer);
        }
    });

    // Esc も同じ動きで閉じる。ブラウザが取り消せない Esc（続けて押したなど）では、そのまま閉じる
    drawer.addEventListener('cancel', (event) => {
        if (event.cancelable && !prefersReducedMotion()) {
            event.preventDefault();
            closeDrawer(drawer);
        }
    });

    // どの道で閉じても、次に開くときのために動きの跡（位置）を消す
    drawer.addEventListener('close', () => {
        sliding?.stop();
        sliding = null;
        drawer.removeAttribute('data-closing');
        drawer.style.removeProperty('transform');
    });
}

// 郵便番号から住所。テーマの [data-postal-group] の中の [data-postal-code] が 7 桁になったら、自分のサーバーに聞いて入れる。
// 町名の欄は、空のときか、前に自動で入れたままのときだけ書き換える（お客さんが打った番地は消さない）
function wirePostal() {
    for (const group of document.querySelectorAll('[data-postal-group]')) {
        const code = group.querySelector('[data-postal-code]');
        const prefecture = group.querySelector('[data-postal-prefecture]');
        const city = group.querySelector('[data-postal-city]');
        const line1 = group.querySelector('[data-postal-line1]');
        const status = group.querySelector('[data-postal-status]');
        let last = '';
        let filledTown = null;

        if (!code) {
            continue;
        }

        code.addEventListener('input', async () => {
            const digits = code.value.replace(/[^0-9]/g, '');

            if (digits.length !== 7 || digits === last) {
                return;
            }

            last = digits;
            let addresses = [];

            try {
                const response = await fetch(`/postal/${digits}`, { headers: JSON_HEADERS, credentials: 'same-origin', redirect: 'error' });
                addresses = response.ok ? ((await response.json()).addresses ?? []) : [];
            } catch {
                addresses = [];
            }

            if (digits !== code.value.replace(/[^0-9]/g, '')) {
                return; // 待っている間に書き換えられた
            }

            const address = addresses[0];

            if (!address) {
                status && (status.textContent = 'この郵便番号の住所が見つかりませんでした。住所を入力してください。');

                return;
            }

            if (prefecture && [...prefecture.options].some((option) => option.value === address.prefecture)) {
                prefecture.value = address.prefecture;
            }

            city && (city.value = address.city);

            if (line1 && (line1.value === '' || line1.value === filledTown)) {
                line1.value = address.town;
                filledTown = address.town;
            }

            status && (status.textContent = `${address.prefecture}${address.city}${address.town} を入力しました。続けて番地を入力してください。`);
        });
    }
}

// 「ご依頼主と同じ」（テーマの [data-same-as-buyer]）。同じならお届け先の欄（[data-recipient-fields]）を隠し、送らない
function wireSameAsBuyer() {
    const toggle = document.querySelector('[data-same-as-buyer]');
    const fields = document.querySelector('[data-recipient-fields]');

    if (!toggle || !fields) {
        return;
    }

    const update = () => {
        fields.hidden = toggle.checked;

        for (const input of fields.querySelectorAll('input, select')) {
            input.disabled = toggle.checked;
        }
    };

    toggle.addEventListener('change', update);
    update();
}

const drawer = document.querySelector('dialog[data-cart-drawer]');
document.documentElement.classList.add('js');
wireSteppers(document);
wireAddForms(drawer);
wireCartPage();
wireDrawer(drawer);
wirePostal();
wireSameAsBuyer();
