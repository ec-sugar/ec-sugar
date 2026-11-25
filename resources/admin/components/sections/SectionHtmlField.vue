<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { api, ApiError, describe } from '../../api.js';
import AppButton from '../ui/AppButton.vue';
import AppNotice from '../ui/AppNotice.vue';
import AppText from '../ui/AppText.vue';
import AppTextarea from '../ui/AppTextarea.vue';
import PhotoPicker from './PhotoPicker.vue';

// セクションの「自由な HTML」の欄（分かる人向け）。等幅の大きめの欄に HTML をそのまま書く。
// ショップに出るのは決まったタグ・属性・class だけで、整えるのはサーバー（保存のときと描くときの両方。SectionHtml）。
// 打つと少し待ってから同じ決まりで確認（何も書かない API）、外すものがあれば欄の下に並べる。「整えた形を欄に入れる」で保存される形に置き換えられる。
// 写真は店の写真の置き場から差し込む（外の URL の写真は外す）
const model = defineModel({ type: [String, null], default: null });
const props = defineProps({
    // 欄の宣言（{ type: 'html', label, max, help, classes }）
    spec: { type: Object, required: true },
    error: { type: String, default: null },
    // 写真（media・mediaBase）と確認の API（htmlBase）
    context: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
    test: { type: String, default: null },
});
const emit = defineEmits(['uploaded']);

const CHECK_AFTER_MS = 600;

const box = ref(null);
const check = ref({ state: 'idle', removed: [], html: '', message: null });
const classes = computed(() => Object.entries(props.spec.classes ?? {}));
let timer = null;
let asked = 0;

async function runCheck() {
    const html = model.value ?? '';
    const mine = ++asked;

    if (html.trim() === '' || !props.context.htmlBase) {
        check.value = { state: 'idle', removed: [], html: '', message: null };
        return;
    }

    check.value = { ...check.value, state: 'checking' };

    try {
        const result = await api('POST', props.context.htmlBase, { html });
        if (mine === asked) {
            check.value = { state: 'done', removed: result.removed, html: result.html, message: null };
        }
    } catch (e) {
        if (mine === asked) {
            const message = e instanceof ApiError && e.status === 422 ? (e.body?.errors?.html?.[0] ?? describe(e)) : describe(e);
            check.value = { state: 'error', removed: [], html: '', message };
        }
    }
}

watch(model, () => {
    clearTimeout(timer);
    timer = setTimeout(runCheck, CHECK_AFTER_MS);
}, { immediate: true });

onBeforeUnmount(() => clearTimeout(timer));

function useCleaned() {
    model.value = check.value.html === '' ? null : check.value.html;
}

// 写真の置き場の写真を、欄のカーソルの位置に <img> で差し込む（説明は写真の説明。属性の値に入れるので " と & と < を文字の参照にする）
function insertPhoto(item) {
    const attr = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
    const tag = `<img src="/media/shop/${item.id}/large" alt="${attr(item.alt)}">`;
    const textarea = box.value?.$el ?? null;
    const text = model.value ?? '';
    const at = textarea instanceof HTMLTextAreaElement ? textarea.selectionStart : text.length;

    model.value = `${text.slice(0, at)}${tag}${text.slice(at)}`;
}
</script>

<template>
    <div class="flex flex-col gap-2" :data-test="test">
        <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-bold text-ink-muted">{{ spec.label }}</span>
            <!-- 字数の上限はサーバーが整えた後の形で数える（文字の参照を 1 字）。ブラウザの maxlength は打った形で数えて食い違うので付けない -->
            <AppTextarea ref="box" v-model="model" code :disabled="disabled" :aria-invalid="!!error" data-test="html-input" />
        </label>
        <AppText v-if="error" size="xs" tone="danger" role="alert">{{ error }}</AppText>
        <AppText size="xs" tone="soft">
            使えるのは、見出し（h2〜h6）・段落・改行・太字・斜体・リスト・表・引用・区切り線・写真（ショップの写真）・リンク（ショップ内のページか、「https」で始まる URL）・div・span と、下の決まった class です。
            スクリプト・style・iframe・フォーム・外部サイトの画像・on… の属性・id は、保存するときに取り除きます（{{ spec.max }} 字まで）。
            スクリプトを使いたいときは、管理画面ではなく、開発者モードか App で追加してください。
        </AppText>

        <details v-if="classes.length > 0" class="text-xs text-ink-soft">
            <summary class="cursor-pointer font-bold text-ink-muted">使える class（見た目は EC-SUGAR がテーマに合わせて付けます）</summary>
            <ul class="mt-2 flex flex-col gap-1" data-test="html-classes">
                <li v-for="[name, what] in classes" :key="name"><code class="font-mono text-ink">{{ name }}</code>: {{ what }}</li>
            </ul>
        </details>

        <PhotoPicker insert :label="spec.label" :media="context.media" :base="context.mediaBase" :disabled="disabled" test="html-photo" @pick="insertPhoto" @uploaded="emit('uploaded', $event)" />

        <AppNotice v-if="check.state === 'done' && check.removed.length > 0" tone="warning" data-test="html-removed">
            <div class="flex flex-col gap-2">
                <span>ショップに表示するとき、次のものは取り除かれます（保存すると、取り除いた状態で保存されます）。</span>
                <ul class="flex list-disc flex-col gap-0.5 pl-5">
                    <li v-for="item in check.removed" :key="item">{{ item }}</li>
                </ul>
                <div>
                    <AppButton size="sm" :disabled="disabled" data-test="html-use-cleaned" @click="useCleaned">取り除いた後の HTML を欄に反映</AppButton>
                </div>
            </div>
        </AppNotice>
        <AppText v-else-if="check.state === 'done'" size="xs" tone="soft" data-test="html-clean">このままショップに表示できます。</AppText>
        <AppText v-else-if="check.state === 'error'" size="xs" tone="danger" role="alert" data-test="html-check-error">{{ check.message }}</AppText>
    </div>
</template>
