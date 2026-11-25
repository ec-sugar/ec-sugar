<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api, ApiError, describe } from '../../api.js';
import AppButton from '../ui/AppButton.vue';
import AppCheckbox from '../ui/AppCheckbox.vue';
import AppInput from '../ui/AppInput.vue';
import AppText from '../ui/AppText.vue';
import { CONTROL } from '../ui/controlClasses.js';
import PhotoPicker from './PhotoPicker.vue';

// セクションの「見たまま書ける文」の欄（誰でも）。HTML を書かずに、上の道具の帯のボタンで見出し・太字・リスト・リンク・表・写真・ボタンを整える。
// 保存する形は「自由な HTML」と同じ HTML で、整えるのはサーバー（保存のときと描くときの両方。SectionHtml）。ここは道具で、守りではない。
//
// Trusted Types の下で動かすため、HTML の文字を DOM に書き込まない（HTML の文字を差し込む口は使わない。AdminShellTest が見張る）:
// - 読むときは、サーバーの確認の API が返す整えた HTML の木（{ t, a, c }）から、要素を 1 つずつ作る
// - 書き出すときは、編集の箱の DOM を自分でたどって HTML の文字にする（残す要素と属性だけ。文字はエスケープする）
// 見出し・リスト・引用はブラウザの編集の命令（execCommand）、太字・斜体・表・写真・ボタン・区切り線・リンクは要素を作って差し込む。
// 貼り付けは文字だけ（よそのサイトの見た目や style を持ち込まない）。写真は店の写真の置き場から（編集の箱の中では管理画面の写真の道筋で見せ、
// 書き出すときにショップの道筋 /media/shop/{ID}/large に戻す）
const model = defineModel({ type: [String, null], default: null });
const props = defineProps({
    spec: { type: Object, required: true },
    error: { type: String, default: null },
    // 写真（media・mediaBase）と確認の API（htmlBase）
    context: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
    test: { type: String, default: null },
});
const emit = defineEmits(['uploaded']);

// 残す要素と属性（サーバーの SectionHtml::ELEMENTS と同じ形。class はどれにも残す）
const ELEMENTS = {
    h2: [], h3: [], h4: [], h5: [], h6: [], p: [], br: [], hr: [], div: [], span: [],
    strong: [], b: [], em: [], i: [], u: [], s: [], del: [], small: [], mark: [], sub: [], sup: [], code: [], pre: [], q: [], cite: [], abbr: ['title'],
    blockquote: [], ul: [], ol: ['start'], li: [], dl: [], dt: [], dd: [],
    table: [], caption: [], thead: [], tbody: [], tfoot: [], tr: [], th: ['colspan', 'rowspan', 'scope'], td: ['colspan', 'rowspan'],
    figure: [], figcaption: [], details: [], summary: [],
    a: ['href', 'title'],
    img: ['alt', 'width', 'height'],
};
const VOID = new Set(['br', 'hr', 'img']);
const ULID = /^[0-9a-hjkmnp-tv-z]{26}$/;
const MEDIA_SRC = /^\/media\/shop\/([0-9a-hjkmnp-tv-z]{26})\/(?:large|medium)$/;

const surface = ref(null);
const state = ref('loading');
const message = ref(null);
const linking = ref(null);
const photoOpen = ref(false);
// 最後に親へ渡した値（親からの値がこれと違えば、外で変わったので読み直す）
let emitted;
let saved = null;

const escapeText = (value) => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
const escapeAttr = (value) => escapeText(value).replaceAll('"', '&quot;');

function build(node) {
    if (typeof node === 'string') {
        return document.createTextNode(node);
    }

    if (node === null || typeof node !== 'object' || typeof node.t !== 'string' || !Object.hasOwn(ELEMENTS, node.t)) {
        return null;
    }

    const element = document.createElement(node.t);
    for (const [name, value] of Object.entries(node.a ?? {})) {
        if (typeof value === 'string' && (name === 'class' || ELEMENTS[node.t].includes(name))) {
            element.setAttribute(name, value);
        }
    }

    if (node.t === 'img') {
        const match = MEDIA_SRC.exec(node.a?.src ?? '');
        if (!match) {
            return null;
        }
        element.setAttribute('data-media', match[1]);
        element.setAttribute('src', `${props.context.mediaBase}/${match[1]}/medium`);
    }

    for (const child of Array.isArray(node.c) ? node.c : []) {
        const built = build(child);
        if (built) {
            element.append(built);
        }
    }

    return element;
}

function serialize(parent) {
    let out = '';

    for (const node of parent.childNodes) {
        if (node.nodeType === Node.TEXT_NODE) {
            out += escapeText(node.data);
            continue;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) {
            continue;
        }

        const tag = node.localName;
        // 残さない要素（ブラウザの編集が作る font など）はタグだけ外して中を残す
        if (!Object.hasOwn(ELEMENTS, tag)) {
            out += serialize(node);
            continue;
        }

        let attributes = '';
        if (tag === 'img') {
            const id = node.getAttribute('data-media') ?? '';
            if (!ULID.test(id)) {
                continue;
            }
            attributes += ` src="/media/shop/${id}/large"`;
        }
        for (const name of ['class', ...ELEMENTS[tag]]) {
            const value = node.getAttribute(name);
            if (value !== null && value !== '') {
                attributes += ` ${name}="${escapeAttr(value)}"`;
            }
        }

        out += VOID.has(tag) ? `<${tag}${attributes}>` : `<${tag}${attributes}>${serialize(node)}</${tag}>`;
    }

    return out;
}

async function load(html) {
    state.value = 'loading';
    message.value = null;

    try {
        const result = await api('POST', props.context.htmlBase, { html: html ?? '' });
        surface.value?.replaceChildren(...result.tree.map(build).filter(Boolean));
        emitted = html;
        state.value = 'ready';
    } catch (e) {
        state.value = 'error';
        message.value = e instanceof ApiError && e.status === 422 ? (e.body?.errors?.html?.[0] ?? describe(e)) : describe(e);
    }
}

function changed() {
    if (!surface.value) {
        return;
    }

    const html = serialize(surface.value).trim();
    emitted = html === '' ? null : html;
    model.value = emitted;
}

watch(model, (value) => {
    if (value !== emitted && state.value !== 'loading') {
        load(value);
    }
});

// 選んでいる所を覚える（道具の帯のボタンやリンクの欄へ焦点が移っても、そこへ差し込めるように）
function remember() {
    const selection = document.getSelection();
    if (selection && selection.rangeCount > 0 && surface.value?.contains(selection.getRangeAt(0).commonAncestorContainer)) {
        saved = selection.getRangeAt(0).cloneRange();
    }
}

function restore() {
    surface.value?.focus();
    const selection = document.getSelection();
    if (saved && selection) {
        selection.removeAllRanges();
        selection.addRange(saved);
    }

    return selection && selection.rangeCount > 0 ? selection.getRangeAt(0) : null;
}

onMounted(() => {
    document.addEventListener('selectionchange', remember);
    if (props.context.htmlBase) {
        load(model.value);
    }
});
// 画面が写真などの材料をまだ読んでいる間は確認の API の道筋が無い。届いたら読む
watch(() => props.context.htmlBase, (base) => {
    if (base && state.value === 'loading') {
        load(model.value);
    }
});
onBeforeUnmount(() => document.removeEventListener('selectionchange', remember));

const ready = () => !props.disabled && state.value === 'ready';

function command(name, value = null) {
    if (!ready()) {
        return;
    }
    restore();
    // 段落は <p>、太字などは style でなくタグで（style は残らない）
    document.execCommand('defaultParagraphSeparator', false, 'p');
    document.execCommand('styleWithCSS', false, false);
    document.execCommand(name, false, value);
    changed();
}

// 太字と斜体は、ブラウザの命令（bold）に任せず要素で包む・外す（命令は見出しの中などで style の属性を足し、管理画面の CSP の違反になるため）
function toggleInline(tag, alias) {
    if (!ready()) {
        return;
    }
    const range = restore();
    if (!range || range.collapsed) {
        return;
    }

    const start = range.commonAncestorContainer;
    const around = (start instanceof Element ? start : start.parentElement)?.closest(`${tag}, ${alias}`) ?? null;

    if (around && surface.value.contains(around)) {
        around.replaceWith(...around.childNodes);
    } else {
        const wrap = document.createElement(tag);
        wrap.append(range.extractContents());
        range.insertNode(wrap);
        const selected = document.createRange();
        selected.selectNodeContents(wrap);
        document.getSelection()?.removeAllRanges();
        document.getSelection()?.addRange(selected);
        saved = selected.cloneRange();
    }
    changed();
}

// 選んでいる所を含む、箱のすぐ下の塊（段落・見出し・表など）
function currentBlock(range) {
    let node = range?.startContainer ?? null;
    while (node && node.parentNode !== surface.value) {
        node = node.parentNode;
    }

    return node && node.parentNode === surface.value ? node : null;
}

// 塊（表・写真・ボタン・区切り線）を、今の塊の後ろに差し込み、その後ろに続きを書く段落を置く
function insertBlock(node) {
    if (!ready()) {
        return;
    }
    const block = currentBlock(restore());
    if (block) {
        block.after(node);
    } else {
        surface.value.append(node);
    }
    const next = document.createElement('p');
    next.append(document.createElement('br'));
    node.after(next);
    const range = document.createRange();
    range.setStart(next, 0);
    range.collapse(true);
    document.getSelection()?.removeAllRanges();
    document.getSelection()?.addRange(range);
    saved = range.cloneRange();
    changed();
}

function insertTable() {
    const table = document.createElement('table');
    const head = document.createElement('thead');
    const body = document.createElement('tbody');
    const row = (cell, texts) => {
        const tr = document.createElement('tr');
        for (const text of texts) {
            const td = document.createElement(cell);
            if (text === '') {
                td.append(document.createElement('br'));
            } else {
                td.textContent = text;
            }
            tr.append(td);
        }
        return tr;
    };
    head.append(row('th', ['見出し', '見出し']));
    body.append(row('td', ['', '']), row('td', ['', '']));
    table.append(head, body);
    insertBlock(table);
}

function insertRule() {
    insertBlock(document.createElement('hr'));
}

function insertPhoto(item) {
    photoOpen.value = false;
    if (!ULID.test(item?.id ?? '')) {
        return;
    }
    const image = document.createElement('img');
    image.setAttribute('data-media', item.id);
    image.setAttribute('src', `${props.context.mediaBase}/${item.id}/medium`);
    image.setAttribute('alt', item.alt ?? '');
    const paragraph = document.createElement('p');
    paragraph.append(image);
    insertBlock(paragraph);
}

// リンクとボタン: 行き先は店の中の道筋か https（サーバーと同じ決まり。合わないものはサーバーも外す）
function linkOk(url) {
    if (url.startsWith('/')) {
        return !url.startsWith('//') && !url.startsWith('/\\');
    }

    try {
        const parsed = new URL(url);
        return parsed.protocol === 'https:' && parsed.username === '' && parsed.password === '';
    } catch {
        return false;
    }
}

function linkAround(range) {
    const node = range?.startContainer ?? null;
    const element = node instanceof Element ? node : node?.parentElement;
    const link = element?.closest('a') ?? null;

    return link && surface.value?.contains(link) ? link : null;
}

function openLink(button) {
    if (!ready()) {
        return;
    }
    remember();
    const link = linkAround(saved);
    linking.value = { url: link?.getAttribute('href') ?? '', button: button || (link?.classList.contains('sugar-btn') ?? false), error: null };
}

function applyLink() {
    const url = linking.value.url.trim();
    if (!linkOk(url)) {
        linking.value.error = 'ショップ内のページ（/products など）か、「https」で始まる URL を入力してください。';
        return;
    }

    const button = linking.value.button;
    const range = restore();
    const existing = linkAround(range);
    linking.value = null;

    if (existing) {
        existing.setAttribute('href', url);
        existing.classList.toggle('sugar-btn', button);
        if (existing.classList.length === 0) {
            existing.removeAttribute('class');
        }
        changed();
        return;
    }

    const link = document.createElement('a');
    link.setAttribute('href', url);
    if (button) {
        link.setAttribute('class', 'sugar-btn');
    }

    if (!range || range.collapsed) {
        // 何も選んでいなければ、新しい行に置く（ボタンは中央に）
        link.textContent = button ? 'ボタンの文字' : url;
        const paragraph = document.createElement('p');
        if (button) {
            paragraph.setAttribute('class', 'sugar-align-center');
        }
        paragraph.append(link);
        insertBlock(paragraph);
        return;
    }

    link.append(range.extractContents());
    range.insertNode(link);
    changed();
}

function unlink() {
    if (!ready()) {
        return;
    }
    const link = linkAround(restore());
    if (link) {
        link.replaceWith(...link.childNodes);
        changed();
    }
}

// 貼り付けは文字だけ（よそのページの見た目・style・スクリプトを持ち込まない）。落とすのも止める
function paste(event) {
    if (!ready()) {
        return;
    }
    const text = event.clipboardData?.getData('text/plain') ?? '';
    document.execCommand('insertText', false, text);
    changed();
}

const TOOLS = [
    { test: 'rt-paragraph', label: '段落', run: () => command('formatBlock', 'p') },
    { test: 'rt-h2', label: '見出し', run: () => command('formatBlock', 'h2') },
    { test: 'rt-h3', label: '小見出し', run: () => command('formatBlock', 'h3') },
    { test: 'rt-bold', label: '太字', run: () => toggleInline('strong', 'b') },
    { test: 'rt-italic', label: '斜体', run: () => toggleInline('em', 'i') },
    { test: 'rt-ul', label: '箇条書き', run: () => command('insertUnorderedList') },
    { test: 'rt-ol', label: '番号付きリスト', run: () => command('insertOrderedList') },
    { test: 'rt-quote', label: '引用', run: () => command('formatBlock', 'blockquote') },
    { test: 'rt-link', label: 'リンク', run: () => openLink(false) },
    { test: 'rt-unlink', label: 'リンクを解除', run: unlink },
    { test: 'rt-button', label: 'ボタン', run: () => openLink(true) },
    { test: 'rt-table', label: '表', run: insertTable },
    { test: 'rt-photo', label: '写真', run: () => { remember(); photoOpen.value = !photoOpen.value; } },
    { test: 'rt-rule', label: '区切り線', run: insertRule },
];
</script>

<template>
    <div class="flex flex-col gap-2" :data-test="test">
        <span class="text-[13px] font-bold text-ink-muted">{{ spec.label }}</span>
        <!-- 押しても編集の箱の選んでいる所を消さない（mousedown を止める）。キーボードでは Tab で来て Enter で押せる -->
        <div class="flex flex-wrap gap-1" role="toolbar" :aria-label="`${spec.label}の書式`" data-test="rt-toolbar" @mousedown.prevent>
            <AppButton v-for="tool in TOOLS" :key="tool.test" size="sm" variant="ghost" :disabled="disabled || state !== 'ready'" :data-test="tool.test" @click="tool.run">{{ tool.label }}</AppButton>
        </div>

        <div v-if="linking" class="flex flex-col gap-2 rounded-lg bg-subtle p-3" data-test="rt-link-panel">
            <AppInput v-model="linking.url" maxlength="500" inputmode="url" :aria-label="linking.button ? 'ボタンのリンク先' : 'リンク先'" placeholder="/products" data-test="rt-link-url" @keydown.enter.prevent="applyLink" />
            <AppCheckbox v-model="linking.button" label="ボタンの形にする" test="rt-link-button" />
            <AppText v-if="linking.error" size="xs" tone="danger" role="alert">{{ linking.error }}</AppText>
            <div class="flex gap-2">
                <AppButton size="sm" variant="primary" data-test="rt-link-apply" @click="applyLink">設定</AppButton>
                <AppButton size="sm" variant="ghost" @click="linking = null">やめる</AppButton>
            </div>
        </div>

        <PhotoPicker v-if="photoOpen" insert :label="spec.label" :media="context.media" :base="context.mediaBase" :disabled="disabled" test="rt-photo-picker" @pick="insertPhoto" @uploaded="emit('uploaded', $event)" />

        <div
            ref="surface"
            :class="[CONTROL, 'rich-text min-h-48 py-3']"
            role="textbox"
            aria-multiline="true"
            :aria-label="spec.label"
            :aria-invalid="!!error"
            :contenteditable="!disabled && state === 'ready' ? 'true' : 'false'"
            data-test="rt-surface"
            @input="changed"
            @paste.prevent="paste"
            @drop.prevent
        />
        <AppText v-if="state === 'loading'" size="xs" tone="soft">読み込んでいます…</AppText>
        <AppText v-else-if="state === 'error'" size="xs" tone="danger" role="alert">{{ message }}</AppText>
        <AppText v-if="error" size="xs" tone="danger" role="alert">{{ error }}</AppText>
        <AppText v-else-if="spec.help" size="xs" tone="soft">{{ spec.help }}（{{ spec.max }} 字まで。貼り付けた文は書式が外れます）</AppText>
    </div>
</template>
