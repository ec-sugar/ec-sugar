// テーマの下書きのプレビューを、管理画面のページの編集の画面の枠（iframe）の中で動かす部品（ADR 0039 区切り 2）。テーマではなく核が持つ JavaScript（ADR 0020）。
// 核の部品 <x-storefront.apps> が下書きのプレビューにだけ置く印（[data-sugar-editor]。中身は枠の親＝管理画面のオリジン）があるときだけ動く。
//
// - 区画（テーマが置く [data-section="区画の ID"]）を押したら、その ID だけを親へ知らせる（送信先は印のオリジンだけ。* にしない）
// - 親からの知らせは、印のオリジンから・親の窓からのものだけ受け、選んだ区画に印（data-sugar-selected）を付けて見える所へ動かす
// - プレビューの中のリンクとフォームは動かさない（押すと区画を選ぶだけ。ほかの画面へ移らない・カートに入れない・何も送らない）
// - 区画の試し見: 親が「＋ 区画を足す」で指した種類とパターンを知らせてきたら、印に添えた同じオリジンの署名つきの道筋（data-sugar-trial）から
//   その区画を見本の値で描いた断片を読み、親が言う位置（選んでいる区画の後ろ）に仮に差し込む。消す知らせ（section が null）で外す。DB には何も書かない
// - 見た目（ADR 0097）: 親が見た目を保存したと知らせてきたら、テーマの CSS の後の下書きの CSS（link[data-sugar-theme-style-preview]。同じオリジンの
//   /preview/themes/…/style.css だけ）を読み直し、外枠の <html> の data-sugar-font・data-sugar-radius を付け直す（ページは描き直さない）。
//   新しい CSS が読めてから古い link を外す（読む間に色が抜けない）。読めなければ前の色のまま
// HTML の文字列は DOM に書き込まない（断片は XMLHttpRequest が文書として読んだものから要素を写す。文字から HTML を組む口は使わない）。見た目は核の CSS（apps.css）が印で付ける
const marker = document.querySelector('[data-sugar-editor]');
const origin = marker?.getAttribute('data-sugar-editor') ?? '';
const ORIGIN_SHAPE = /^https?:\/\/[a-z0-9.-]+(:\d{1,5})?$/;
const ID_SHAPE = /^[a-z0-9_-]{1,40}$/;
const NAME_SHAPE = /^[a-z][a-z0-9_]{0,31}$/;
// 試し見の断片の道筋（同じオリジンの /preview/themes/… だけ。ほかは使わない）
const trialBase = (() => {
    const value = marker?.getAttribute('data-sugar-trial') ?? '';

    try {
        const url = new URL(value, window.location.href);

        return value !== '' && url.origin === window.location.origin && url.pathname.startsWith('/preview/themes/') ? url : null;
    } catch {
        return null;
    }
})();

function sectionOf(target) {
    const element = target instanceof Element ? target.closest('[data-section]') : null;
    const id = element?.getAttribute('data-section') ?? '';

    return ID_SHAPE.test(id) ? { element, id } : null;
}

function select(id) {
    for (const element of document.querySelectorAll('[data-sugar-selected]')) {
        element.removeAttribute('data-sugar-selected');
    }

    if (id === null) {
        return;
    }

    for (const element of document.querySelectorAll('[data-section]')) {
        if (element.getAttribute('data-section') === id) {
            element.setAttribute('data-sugar-selected', '');
            element.scrollIntoView({ block: 'nearest' });

            return;
        }
    }
}

// ---- 区画の試し見 ----

// 断片は種類とパターンごとに 1 度だけ読む（指を行き来させても読み直さない）。読むのは最新の知らせの分だけ（古い答えは捨てる）
const fragments = new Map();
let wanted = 0;
// 差し込むときに外すもの（断片はテーマの部品が描いたものだが、念のためスクリプトと埋め込みと on… の属性は写さない）
const STRIP = 'script, iframe, object, embed, frame, frameset, base, link, meta, style, template';

function idList(value) {
    return Array.isArray(value) ? value.filter((id) => typeof id === 'string' && ID_SHAPE.test(id)).slice(0, 40) : [];
}

// 差し込んだ仮の区画の印は data-sugar-trial-node（道筋を持つ編集の印 [data-sugar-trial] とは別の名前。消すときに印まで消さない）
function removeTrial() {
    for (const element of document.querySelectorAll('[data-sugar-trial-node]')) {
        element.remove();
    }
}

function loadFragment(type, pattern) {
    const key = `${type}:${pattern}`;

    if (!fragments.has(key)) {
        fragments.set(key, new Promise((resolve) => {
            const url = new URL(trialBase.href);
            url.searchParams.set('type', type);
            url.searchParams.set('pattern', pattern);
            const request = new XMLHttpRequest();
            request.open('GET', url.href);
            request.responseType = 'document';
            request.onload = () => {
                const ok = request.status === 200 && new URL(request.responseURL || url.href).origin === window.location.origin;
                resolve(ok ? (request.response?.querySelector('[data-sugar-trial-root]') ?? null) : false);
            };
            request.onerror = () => resolve(false);
            request.send();
        }).then((root) => {
            // 読めなかった分（false）は 10 秒だけ覚える（指を行き来させるたびに同じ失敗を取りに行かない。その後は読み直す）
            if (root === false) {
                setTimeout(() => fragments.delete(key), 10_000);
            } else if (root !== null) {
                clean(root);
            }

            return root;
        }));
    }

    return fragments.get(key);
}

// 読んだ断片を、写す前に読んだ文書（スクリプトの動かない文書）の中で片付ける: スクリプトと埋め込み・on… の属性・区画の印を外す
// （この文書へ写してから外すと、写した時点で on… の属性が CSP の違反として報告される）
function clean(root) {
    for (const element of root.querySelectorAll(STRIP)) {
        element.remove();
    }

    for (const element of root.querySelectorAll('*')) {
        for (const name of element.getAttributeNames()) {
            if (name.startsWith('on')) {
                element.removeAttribute(name);
            }
        }

        element.removeAttribute('data-section');
    }
}

// 片付けた断片の要素を、この文書の要素として写す（この文書にすでにある id は外す）
function adopt(root) {
    const nodes = [];

    for (const child of root.children) {
        const copy = document.importNode(child, true);

        for (const element of [copy, ...copy.querySelectorAll('[id]')]) {
            if (element.id && document.getElementById(element.id)) {
                element.removeAttribute('id');
            }
        }

        copy.setAttribute('data-sugar-trial-node', '');
        nodes.push(copy);
    }

    return nodes;
}

// 部品が何も描かない区画（見出しや写真が要る区画）は、核の箱で「入れると出ます」と知らせる。
// 外枠が描く種類（お知らせの帯・フッター）は、ページの決まった場所に出ることを知らせる。読めなかった（期限切れ・通信の失敗）ときはそう知らせる
function emptyNote(label, frame, failed) {
    const note = document.createElement('div');
    note.className = 'sugar-trial-empty';
    note.setAttribute('data-sugar-trial-node', '');
    note.textContent = failed
        ? `「${label}」のプレビューを読み込めませんでした。編集画面を開き直してください。`
        : frame ? `「${label}」は、ページの上か下の決まった位置に表示されます。` : `「${label}」は、文章や写真を入力するとここに表示されます。`;

    return note;
}

// 差し込む位置: 前の区画（近い順）の後ろ、無ければ次の区画（近い順）の前、どちらも無ければ画面の main の最後
function place(nodes, after, before) {
    const find = (id) => [...document.querySelectorAll('[data-section]')].find((element) => element.getAttribute('data-section') === id);
    const previous = after.map(find).find(Boolean);
    const next = before.map(find).find(Boolean);

    if (previous) {
        previous.after(...nodes);
    } else if (next) {
        next.before(...nodes);
    } else {
        (document.querySelector('main') ?? document.body).append(...nodes);
    }

    nodes[0]?.scrollIntoView({ block: 'nearest' });
}

// ---- 見た目（ADR 0097） ----

const STYLE_NAME = /^[a-z][a-z0-9-]{0,31}$/;

function restyle(data) {
    const links = [...document.querySelectorAll('link[data-sugar-theme-style-preview]')];
    const current = links[links.length - 1];
    let url = null;

    try {
        url = current ? new URL(current.href, window.location.href) : null;
    } catch {
        url = null;
    }

    if (url && url.origin === window.location.origin && url.pathname.startsWith('/preview/themes/') && url.pathname.endsWith('/style.css')) {
        url.searchParams.set('r', String(Number.isInteger(data.revision) && data.revision >= 0 ? data.revision : 0));
        const next = current.cloneNode();
        next.href = url.href;
        next.addEventListener('load', () => {
            for (const link of links) {
                link.remove();
            }
        }, { once: true });
        next.addEventListener('error', () => next.remove(), { once: true });
        current.after(next);
    }

    for (const [key, attribute] of [['font', 'data-sugar-font'], ['radius', 'data-sugar-radius']]) {
        const value = data[key];

        if (value === '' || (typeof value === 'string' && STYLE_NAME.test(value))) {
            document.documentElement.setAttribute(attribute, value);
        }
    }
}

async function tryOut(data) {
    const ticket = ++wanted;

    if (data.section === null) {
        removeTrial();

        return;
    }

    const { type, pattern } = data.section ?? {};

    if (!trialBase || typeof type !== 'string' || typeof pattern !== 'string' || !NAME_SHAPE.test(type) || !NAME_SHAPE.test(pattern)) {
        removeTrial();

        return;
    }

    const root = await loadFragment(type, pattern);

    // 待っている間に別の知らせが来たら、この答えは使わない
    if (ticket !== wanted) {
        return;
    }

    removeTrial();
    const label = typeof data.label === 'string' ? data.label.slice(0, 80) : '';
    const nodes = root ? adopt(root) : [];
    place(nodes.length > 0 ? nodes : [emptyNote(label, root ? root.hasAttribute('data-sugar-trial-frame') : false, root === false)], idList(data.after), idList(data.before));
}

if (marker && ORIGIN_SHAPE.test(origin) && window.parent !== window) {
    document.documentElement.setAttribute('data-sugar-editing', '');

    // 押した所の区画を選ぶ。リンク・ボタン・フォームの既定の動きは止める（プレビューの中で画面を移らない）
    document.addEventListener('click', (event) => {
        event.preventDefault();
        const found = sectionOf(event.target);

        if (found) {
            select(found.id);
            window.parent.postMessage({ source: 'sugar-preview', type: 'select', section: found.id }, origin);
        }
    }, true);

    document.addEventListener('submit', (event) => event.preventDefault(), true);

    window.addEventListener('message', (event) => {
        if (event.origin !== origin || event.source !== window.parent) {
            return;
        }

        const data = event.data;

        if (data?.source === 'sugar-editor' && data.type === 'select' && (data.section === null || (typeof data.section === 'string' && ID_SHAPE.test(data.section)))) {
            select(data.section);
        }

        if (data?.source === 'sugar-editor' && data.type === 'try') {
            tryOut(data);
        }

        if (data?.source === 'sugar-editor' && data.type === 'style') {
            restyle(data);
        }
    });
}
